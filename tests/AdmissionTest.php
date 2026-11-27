<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Ledger\ExecutionAuthority;
use Yiisoft\Scheduler\Ledger\ExecutionIdentity;
use Yiisoft\Scheduler\Ledger\ExecutionOutcome;
use Yiisoft\Scheduler\Ledger\ExecutionPhase;
use Yiisoft\Scheduler\Ledger\InMemoryLedger;
use Yiisoft\Scheduler\Ledger\LaunchDescription;
use Yiisoft\Scheduler\Ledger\LaunchFact;
use Yiisoft\Scheduler\Overlap;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;

use function usleep;

/**
 * Whether an occurrence can be started now: the task's own answer about overlapping itself, and
 * the room its source has left. Both are read from the ledger, so an execution started by a
 * planner that is no longer here still counts.
 *
 * The executions held in these tests are written straight to the ledger, which is what another
 * planner on another host looks like from here.
 */
final class AdmissionTest extends TestCase
{
    public function testTheCapacityOfASourceIsAtLeastOneExecution(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('at least one execution, 0 given');

        (new Schedule())->capacity(0);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testATaskAlreadyRunningSomewhereElseIsNotStartedAgain(): void
    {
        $ledger = new InMemoryLedger();
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $task = RecurringTask::cron('* * * * *', static fn() => null)->withId('report');

        $scheduler = $this->scheduler($cache, $ledger, $clock, [$task]);
        $scheduler->tick();
        $clock->advance('+1 minute');

        $due = $clock->now();
        $this->hold($ledger, 'report', $due->modify('-1 hour'));
        $scheduler->tick(true);

        $this->assertCount(1, $ledger->holdingProcess('default', 10), 'nothing was started beside the run held');

        // The occurrence was decided rather than left: the ledger says what became of it, and
        // the tick after this one has nothing of it to offer.
        $record = $ledger->find(new ExecutionIdentity('default', 'report', $due));

        $this->assertSame(ExecutionOutcome::Skipped, $record?->outcome);
        $this->assertSame(ExecutionPhase::Terminal, $record?->phase);

        $scheduler->tick(true);

        $this->assertCount(1, $ledger->holdingProcess('default', 10), 'the skipped run is not offered again');
        $this->reap($scheduler);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testATaskThatAllowsOverlapIsStartedAnyway(): void
    {
        $ledger = new InMemoryLedger();
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $task = RecurringTask::cron('* * * * *', static fn() => null)->withId('report')
            ->withOverlap(Overlap::Allow);

        $scheduler = $this->scheduler($cache, $ledger, $clock, [$task]);
        $scheduler->tick();
        $clock->advance('+1 minute');

        $due = $clock->now();
        $this->hold($ledger, 'report', $due->modify('-1 hour'));
        $scheduler->tick(true);

        $this->assertCount(2, $ledger->holdingProcess('default', 10), 'the run held, and the one just started');

        // What the record says was launched, which outlives the process that was launched.
        $record = $ledger->find(new ExecutionIdentity('default', 'report', $due));

        $this->assertSame(LaunchFact::Started, $record?->launch);
        $this->assertNotNull($record?->process);
        $this->reap($scheduler);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testTwoTasksOfOneSourceAnswerForThemselves(): void
    {
        $ledger = new InMemoryLedger();
        $clock = new TestClock();
        $cache = new InMemoryCache();

        $together = RecurringTask::cron('* * * * *', static fn() => null)->withId('together')
            ->withOverlap(Overlap::Allow);
        $alone = RecurringTask::cron('* * * * *', static fn() => null)->withId('alone')
            ->withOverlap(Overlap::Skip);

        $scheduler = $this->scheduler($cache, $ledger, $clock, [$together, $alone]);
        $scheduler->tick();
        $clock->advance('+1 minute');

        $this->hold($ledger, 'together', $clock->now()->modify('-1 hour'));
        $this->hold($ledger, 'alone', $clock->now()->modify('-1 hour'));
        $scheduler->tick(true);

        $held = $ledger->holdingProcess('default', 10);
        $started = [];

        foreach ($held as $record) {
            $started[$record->identity->taskId] = ($started[$record->identity->taskId] ?? 0) + 1;
        }

        $this->assertSame(['together' => 2, 'alone' => 1], $started);
        $this->reap($scheduler);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testARunWithNoRoomIsLeftDueRatherThanRecorded(): void
    {
        $ledger = new InMemoryLedger();
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $task = RecurringTask::cron('* * * * *', static fn() => null)->withId('report');

        $scheduler = $this->scheduler($cache, $ledger, $clock, [$task], capacity: 1);
        $scheduler->tick();
        $clock->advance('+1 minute');

        $occupant = $this->hold($ledger, 'other', $clock->now()->modify('-1 hour'));
        $scheduler->tick(true);

        $this->assertCount(1, $ledger->holdingProcess('default', 10), 'the source was full');

        // The occurrence was never acknowledged, so it is still the schedule's to offer. The
        // capacity comes back when the execution gives its process up, and not before.
        $ledger->releaseProcess($occupant, ExecutionAuthority::forAttempt($occupant, 1), null, $clock->now());

        $scheduler->tick(true);

        $this->assertCount(1, $ledger->holdingProcess('default', 10), 'the run nobody made is made now');
        $this->assertSame('report', $ledger->holdingProcess('default', 10)[0]->identity->taskId);
        $this->reap($scheduler);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testAnExecutionHoldingAProcessOutlivesThePlannerThatStartedIt(): void
    {
        $ledger = new InMemoryLedger();
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $task = RecurringTask::cron('* * * * *', static fn() => null)->withId('report');

        $this->scheduler($cache, $ledger, $clock, [$task], capacity: 1)->tick();
        $clock->advance('+1 minute');
        $this->hold($ledger, 'report', $clock->now()->modify('-1 hour'));

        // A planner that knows nothing of what came before it, which is what a restart is.
        $restarted = $this->scheduler($cache, $ledger, $clock, [$task], capacity: 1);
        $restarted->tick(true);

        $this->assertCount(1, $ledger->holdingProcess('default', 10), 'the execution it never started still counts');
        $this->reap($restarted);
    }

    private function hold(InMemoryLedger $ledger, string $taskId, DateTimeImmutable $occurrence): ExecutionIdentity
    {
        $identity = new ExecutionIdentity('default', $taskId, $occurrence);
        $authority = ExecutionAuthority::forAttempt($identity, 1);
        $at = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $ledger->admit($identity, 'revision', $at, $at->modify('+1 day'));
        $ledger->claim($identity, $authority, $at);
        $ledger->recordLaunch($identity, $authority, LaunchFact::Started, new LaunchDescription('elsewhere', 4242), $at);

        return $identity;
    }

    /**
     * @param list<RecurringTask> $tasks
     */
    private function scheduler(
        InMemoryCache $cache,
        InMemoryLedger $ledger,
        TestClock $clock,
        array $tasks,
        ?int $capacity = null,
    ): Scheduler {
        $schedule = (new Schedule())->stateful($cache)->ledger($ledger)->task(...$tasks);

        if (null !== $capacity) {
            $schedule->capacity($capacity);
        }

        return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
    }

    private function reap(Scheduler $scheduler): void
    {
        while ($scheduler->hasRunningChildren()) {
            $scheduler->reapChildren();
            usleep(10_000);
        }
    }
}
