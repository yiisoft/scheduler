<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use DateTimeImmutable;
use Yiisoft\Scheduler\Ledger\ExecutionIdentity;
use Yiisoft\Scheduler\Ledger\ExecutionPhase;
use Yiisoft\Scheduler\Ledger\ExecutionRecord;

use function count;
use function min;

/**
 * Whether an occurrence of this schedule can be started now.
 *
 * Two questions, kept together because they are one: whether the task is already running, and
 * whether the source has room for another execution. They are answered from the ledger, so what
 * another planner holds, and what this one held before it was restarted, count as much as what
 * it started a minute ago.
 *
 * An execution counts from the moment a planner takes the right to launch it rather than from
 * the moment a process exists, because the gap between the two is exactly where a planner dies
 * and leaves a worker nobody wrote down. It stops counting when the execution gives its process
 * up, which recovery does for a planner that cannot.
 *
 * One of these belongs to one tick. The executions are read once, the first time something is
 * asked of it, and what this planner claims afterwards is added to them: a tick that starts ten
 * runs does not read the ledger ten times to find that out.
 *
 * Two planners reading at the same time see the same room and may both take it: the ledger
 * settles one occurrence at a time, which is what keeps two planners off one run, and not how
 * many runs a source has going. A capacity is how much work a source starts, not a lock.
 */
final class Admission
{
    /**
     * How many executions are read in one page of the survey below.
     */
    private const PAGE = 100;

    /**
     * The most executions a survey reads before it gives up on an exact answer.
     *
     * A source holding more than this is one whose executions nobody is giving back, which is
     * recovery's business rather than this tick's. The tick then answers that there is no room
     * and that the task is running, because the one thing a survey this long says for certain
     * is that the source is busier than any capacity it was given.
     */
    private const SURVEY_LIMIT = 1_000;

    /**
     * @var list<ExecutionRecord>|null The executions of this source that are under way.
     */
    private ?array $started = null;

    /**
     * Whether the survey above stopped at its bound rather than at the end of the backlog.
     */
    private bool $truncated = false;

    /**
     * @var array<string, ExecutionIdentity> What this planner has claimed since the survey.
     */
    private array $claimed = [];

    public function __construct(private readonly Schedule $schedule) {}

    /**
     * Whether an execution is under way, which is what occupies a source.
     *
     * A claim is: a planner has taken the right to launch it and the process it starts is not
     * written down yet, which is exactly where a planner dies and leaves a worker nobody knows
     * about. A process still held is, whatever the record says became of the work, because an
     * outcome reported an hour ago says nothing about a process nobody has proven closed.
     *
     * An occurrence that was only admitted is neither: nobody has taken it on. It is work this
     * schedule still owes rather than work it is doing. Neither is one that gave its process
     * back, which is what returns the room it took.
     */
    public static function occupies(ExecutionRecord $record): bool
    {
        return $record->holdsProcess() || ExecutionPhase::Claimed === $record->phase;
    }

    /**
     * Whether another execution of this task is under way.
     *
     * The occurrence being decided is left out of the answer: it was admitted a moment ago and
     * is not an earlier run of itself.
     */
    public function isTaskRunning(string $taskId, DateTimeImmutable $occurrence): bool
    {
        foreach ($this->survey() as $record) {
            if ($record->identity->taskId === $taskId && $record->identity->occurrence != $occurrence) {
                return true;
            }
        }

        foreach ($this->claimed as $identity) {
            if ($identity->taskId === $taskId && $identity->occurrence != $occurrence) {
                return true;
            }
        }

        return $this->truncated;
    }

    /**
     * Whether the source has room for one more execution.
     *
     * @param int $localRunning How many runs of this schedule this process has going, for a
     * schedule with no ledger to answer from.
     */
    public function hasRoom(int $localRunning): bool
    {
        $capacity = $this->schedule->getCapacity();

        if (null === $capacity) {
            return true;
        }

        if (null === $this->schedule->getLedgerQueries()) {
            // Nothing durable to count, so the only executions this schedule knows about are
            // the ones this process is running.
            return $localRunning < $capacity;
        }

        if ($this->truncated) {
            return false;
        }

        return count($this->survey()) + count($this->claimed) < $capacity;
    }

    /**
     * Counts a run this planner has taken the right to launch, so that the runs behind it in
     * the same tick are decided against a source that already holds it.
     */
    public function claimed(ExecutionIdentity $identity): void
    {
        $this->claimed[$identity->key()] = $identity;
    }

    /**
     * Stops counting a run this planner claimed and then did not start, so that the room it
     * took comes back within the tick that took it.
     */
    public function gaveUp(ExecutionIdentity $identity): void
    {
        unset($this->claimed[$identity->key()]);
    }

    /**
     * The executions of this source that are under way.
     *
     * @return list<ExecutionRecord>
     */
    private function survey(): array
    {
        if (null !== $this->started) {
            return $this->started;
        }

        $queries = $this->schedule->getLedgerQueries();

        if (null === $queries) {
            return $this->started = [];
        }

        $records = [];
        $after = null;
        $read = 0;

        while ($read < self::SURVEY_LIMIT) {
            /** @var positive-int $size The loop runs while fewer than the bound are read. */
            $size = min(self::PAGE, self::SURVEY_LIMIT - $read);
            $page = $queries->unresolved($this->schedule->getName(), $size, $after);
            $read += count($page);
            $last = null;

            foreach ($page as $record) {
                $last = $record;

                if (self::occupies($record)) {
                    $records[] = $record;
                }
            }

            // A page shorter than the one asked for is the end of the backlog.
            if (null === $last || count($page) < $size) {
                return $this->started = $records;
            }

            $after = $last->identity;
        }

        $this->truncated = true;

        return $this->started = $records;
    }
}
