<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use DateTimeImmutable;
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
 * One of these belongs to one tick. The executions holding a process are read once, the first
 * time something is asked of it, and what this planner starts afterwards is counted on top of
 * them: a tick that starts ten runs does not read the ledger ten times to find that out.
 */
final class Admission
{
    /**
     * How many executions are read in one page of the survey below.
     */
    private const PAGE = 100;

    /**
     * The most executions a survey reads before it answers with what it has.
     *
     * A source holding more than this is one whose executions nobody is giving back, which is
     * recovery's business rather than this tick's: the tick answers "no room" and gets on with
     * what it can do.
     */
    private const SURVEY_LIMIT = 1_000;

    /**
     * @var list<ExecutionRecord>|null The executions holding a process, read once.
     */
    private ?array $holding = null;

    /**
     * How many executions this planner has started since that was read.
     */
    private int $started = 0;

    public function __construct(private readonly Schedule $schedule) {}

    /**
     * Whether another execution of this task durably holds a process.
     *
     * The occurrence being decided is left out of the answer: it was admitted a moment ago and
     * is not an earlier run of itself.
     */
    public function isTaskHoldingProcess(string $taskId, DateTimeImmutable $occurrence): bool
    {
        foreach ($this->holding() as $record) {
            if ($record->identity->taskId === $taskId && $record->identity->occurrence != $occurrence) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the source has room for one more execution.
     *
     * @param int $localRunning How many runs this process has going, for a schedule with no
     * ledger to answer from.
     */
    public function hasRoom(int $localRunning): bool
    {
        $capacity = $this->schedule->getCapacity();

        if (null === $capacity) {
            return true;
        }

        return $this->occupied($localRunning) < $capacity;
    }

    /**
     * Counts a run this planner is about to start, so that the runs behind it in the same tick
     * are decided against a source that already holds it.
     */
    public function starting(): void
    {
        ++$this->started;
    }

    private function occupied(int $localRunning): int
    {
        if (null === $this->schedule->getLedgerQueries()) {
            // Nothing durable to count, so the only executions this schedule knows about are
            // the ones this process is running.
            return $localRunning;
        }

        return count($this->holding()) + $this->started;
    }

    /**
     * @return list<ExecutionRecord>
     */
    private function holding(): array
    {
        if (null !== $this->holding) {
            return $this->holding;
        }

        $queries = $this->schedule->getLedgerQueries();

        if (null === $queries) {
            return $this->holding = [];
        }

        $records = [];
        $after = null;
        $capacity = $this->schedule->getCapacity();

        // One more than the capacity is enough to know there is no room; without one, the
        // survey reads until the ledger runs out or the bound above is reached.
        $wanted = null === $capacity ? self::SURVEY_LIMIT : min($capacity + 1, self::SURVEY_LIMIT);

        while (count($records) < $wanted) {
            /** @var positive-int $size The loop runs while fewer than $wanted are read. */
            $size = min(self::PAGE, $wanted - count($records));
            $page = $queries->holdingProcess($this->schedule->getName(), $size, $after);

            if ([] === $page) {
                break;
            }

            foreach ($page as $record) {
                $records[] = $record;
            }

            $after = $page[count($page) - 1]->identity;
        }

        return $this->holding = $records;
    }
}
