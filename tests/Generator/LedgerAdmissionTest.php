<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Generator;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Ledger\ExecutionIdentity;
use Yiisoft\Scheduler\Ledger\ExecutionAuthority;
use Yiisoft\Scheduler\Ledger\ExecutionOutcome;
use Yiisoft\Scheduler\Ledger\ExecutionRecord;
use Yiisoft\Scheduler\Ledger\InMemoryLedger;
use Yiisoft\Scheduler\Ledger\LaunchDescription;
use Yiisoft\Scheduler\Ledger\LaunchFact;
use Yiisoft\Scheduler\Ledger\LedgerCommandInterface;
use Yiisoft\Scheduler\Ledger\LedgerQueryInterface;
use Yiisoft\Scheduler\Ledger\ProcessObservation;
use Yiisoft\Scheduler\Ledger\TransitionResult;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;

/**
 * Nothing acknowledges scheduling progress past an occurrence before its record is durable.
 *
 * The ordering is the whole point of admission: a process that dies between the two leaves work
 * the ledger knows about, rather than work the checkpoint has already counted and nothing
 * remembers.
 */
final class LedgerAdmissionTest extends TestCase
{
    public function testEveryEmittedOccurrenceWasAdmittedFirst(): void
    {
        $ledger = new AdmittedLedger();
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $task = RecurringTask::every(60, static fn() => null);

        // The first tick writes down where the schedule starts; nothing is due yet.
        (new Scheduler([$this->schedule($cache, $ledger, $task)], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+3 minutes');
        (new Scheduler([$this->schedule($cache, $ledger, $task)], new CallableTaskHandler(), $clock))->tick();

        $admitted = $ledger->admissions();

        $this->assertCount(3, $admitted);
        $this->assertSame($task->getId(), $admitted[0]->identity->taskId);
    }

    public function testAnOccurrenceTheLedgerCouldNotRecordIsStillDue(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $task = RecurringTask::every(60, static fn() => null);

        (new Scheduler([$this->schedule($cache, new UnwritableLedger(), $task)], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+1 minute');

        $refusing = $this->schedule($cache, new UnwritableLedger(), $task);

        try {
            (new Scheduler([$refusing], new CallableTaskHandler(), $clock))->tick();
            $this->fail('the ledger refused to write and the tick went on regardless');
        } catch (RuntimeException) {
            // The point of the test is what the checkpoint holds afterwards.
        }

        $ledger = new AdmittedLedger();
        (new Scheduler([$this->schedule($cache, $ledger, $task)], new CallableTaskHandler(), $clock))->tick();

        $this->assertCount(
            1,
            $ledger->admissions(),
            'the checkpoint never moved past the occurrence that was not recorded',
        );
    }

    public function testASkippedMissedRunIsNotAdmitted(): void
    {
        // Only the latest missed run fires, so the ones passed over are never executed and
        // there is no execution to keep a record of.
        $ledger = new AdmittedLedger();
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $task = RecurringTask::every(60, static fn() => null);

        $first = $this->schedule($cache, $ledger, $task)->processOnlyLastMissedRun();
        (new Scheduler([$first], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+5 minutes');

        $schedule = $this->schedule($cache, $ledger, $task)->processOnlyLastMissedRun();
        (new Scheduler([$schedule], new CallableTaskHandler(), $clock))->tick();

        $this->assertCount(1, $ledger->admissions());
    }

    public function testARedefinedTaskIsAdmittedUnderANewRevision(): void
    {
        // The identifier is the generated one here, so it changes with the trigger and the
        // revision would follow even if it were read from identifiers alone.
        $this->assertNotSame(
            $this->revisionOf(RecurringTask::every(60, [self::class, 'reportDaily'])),
            $this->revisionOf(RecurringTask::every(120, [self::class, 'reportDaily'])),
        );
    }

    public function testATaskRetriggeredUnderItsOwnIdentifierIsAdmittedUnderANewRevision(): void
    {
        // The case identifiers cannot answer: the trigger changed and the identifier did not,
        // so a revision read from identifiers would call the new configuration the old one and
        // recovery would go on running work under a schedule that no longer exists.
        $this->assertNotSame(
            $this->revisionOf(RecurringTask::every(60, [self::class, 'reportDaily'])->withId('report')),
            $this->revisionOf(RecurringTask::every(120, [self::class, 'reportDaily'])->withId('report')),
        );
    }

    public function testTaskWorkChangedUnderItsOwnIdentifierIsAdmittedUnderANewRevision(): void
    {
        // The other half of the same case: the trigger is untouched and the work is not.
        $this->assertNotSame(
            $this->revisionOf(RecurringTask::every(60, [self::class, 'reportDaily'])->withId('report')),
            $this->revisionOf(RecurringTask::every(60, [self::class, 'reportWeekly'])->withId('report')),
        );
    }

    public function testTheSameScheduleIsTheSameRevision(): void
    {
        $this->assertSame(
            $this->revisionOf(RecurringTask::every(60, [self::class, 'reportDaily'])->withId('report')),
            $this->revisionOf(RecurringTask::every(60, [self::class, 'reportDaily'])->withId('report')),
        );
    }

    public function testATaskAddedAfterTheSchedulerWasBuiltIsAdmittedUnderARevisionThatDescribesIt(): void
    {
        $ledger = new AdmittedLedger();
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $schedule = $this->schedule($cache, $ledger, RecurringTask::every(60, [self::class, 'reportDaily'])->withId('daily'));

        // Built before the schedule is finished, which is what a container does: a scheduler
        // makes a generator per schedule as it is constructed.
        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $schedule->task(RecurringTask::every(60, [self::class, 'reportWeekly'])->withId('weekly'));

        // The first tick records where the schedule starts; the second has runs behind it.
        $scheduler->tick();
        $clock->advance('+10 minutes');
        $scheduler->tick();

        $admitted = $ledger->admissions();
        $revisions = [];

        foreach ($admitted as $record) {
            $revisions[$record->identity->taskId] = $record->sourceRevision;
        }

        $this->assertArrayHasKey('weekly', $revisions, 'a task added before the tick is still emitted');
        $this->assertSame(
            $revisions['daily'],
            $revisions['weekly'],
            'one tick admits under one revision, and it is the schedule as the tick found it',
        );
        $this->assertNotSame(
            $this->revisionOf(RecurringTask::every(60, [self::class, 'reportDaily'])->withId('daily')),
            $revisions['daily'],
            'the revision describes both tasks, not the one the scheduler was built with',
        );
    }

    /** Two named pieces of work, which is all the revision reads of them. */
    public static function reportDaily(): void {}

    public static function reportWeekly(): void {}

    /**
     * The revision an occurrence of the given schedule is admitted under, which is the one the
     * record keeps.
     */
    private function revisionOf(RecurringTask $task): string
    {
        $ledger = new AdmittedLedger();
        $clock = new TestClock();
        $cache = new InMemoryCache();

        (new Scheduler([$this->schedule($cache, $ledger, $task)], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+10 minutes');
        (new Scheduler([$this->schedule($cache, $ledger, $task)], new CallableTaskHandler(), $clock))->tick();

        $admitted = $ledger->admissions();

        $this->assertNotEmpty($admitted, 'an occurrence has to be admitted for its revision to be read');

        return $admitted[0]->sourceRevision;
    }

    private function schedule(InMemoryCache $cache, LedgerCommandInterface $ledger, RecurringTask $task): Schedule
    {
        return (new Schedule())
            ->stateful($cache)
            ->ledger($ledger)
            ->task($task);
    }
}

/**
 * A ledger that cannot write, which is how a store that is down behaves.
 */
final class UnwritableLedger implements LedgerCommandInterface
{
    public function admit(
        ExecutionIdentity $identity,
        string $sourceRevision,
        DateTimeImmutable $at,
        DateTimeImmutable $recoveryDeadline,
    ): ExecutionRecord {
        throw new RuntimeException('The ledger could not be written to.');
    }

    public function claim(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        DateTimeImmutable $at,
    ): TransitionResult {
        throw new RuntimeException('The ledger could not be written to.');
    }

    public function recordLaunch(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        LaunchFact $launch,
        ?LaunchDescription $process,
        DateTimeImmutable $at,
    ): TransitionResult {
        throw new RuntimeException('The ledger could not be written to.');
    }

    public function recordOutcome(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ExecutionOutcome $outcome,
        DateTimeImmutable $at,
    ): TransitionResult {
        throw new RuntimeException('The ledger could not be written to.');
    }

    public function releaseProcess(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ?ProcessObservation $observation,
        DateTimeImmutable $at,
    ): TransitionResult {
        throw new RuntimeException('The ledger could not be written to.');
    }

    public function reauthorize(
        ExecutionIdentity $identity,
        ExecutionAuthority $expected,
        DateTimeImmutable $at,
    ): TransitionResult {
        throw new RuntimeException('The ledger could not be written to.');
    }
}

/**
 * An {@see InMemoryLedger} that also keeps every record as admission left it.
 *
 * The tests above are about what is written down before an occurrence is emitted, and what
 * happens to it afterwards is another ticket's: a run the scheduler makes and resolves is no
 * longer unresolved, which would make the ledger look empty to a test asking it that way.
 */
final class AdmittedLedger implements LedgerCommandInterface, LedgerQueryInterface
{
    /**
     * @var list<ExecutionRecord>
     */
    private array $admitted = [];

    private readonly InMemoryLedger $ledger;

    public function __construct()
    {
        $this->ledger = new InMemoryLedger();
    }

    /**
     * The records of this source as they were admitted, oldest occurrence first.
     *
     * @return list<ExecutionRecord>
     */
    public function admissions(string $source = 'default'): array
    {
        $admitted = [];

        foreach ($this->admitted as $record) {
            if ($record->identity->source === $source) {
                $admitted[] = $record;
            }
        }

        return $admitted;
    }

    public function admit(
        ExecutionIdentity $identity,
        string $sourceRevision,
        DateTimeImmutable $at,
        DateTimeImmutable $recoveryDeadline,
    ): ExecutionRecord {
        $known = $this->ledger->find($identity);
        $record = $this->ledger->admit($identity, $sourceRevision, $at, $recoveryDeadline);

        if (null === $known) {
            $this->admitted[] = $record;
        }

        return $record;
    }

    public function claim(ExecutionIdentity $identity, ExecutionAuthority $authority, DateTimeImmutable $at): TransitionResult
    {
        return $this->ledger->claim($identity, $authority, $at);
    }

    public function recordLaunch(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        LaunchFact $launch,
        ?LaunchDescription $process,
        DateTimeImmutable $at,
    ): TransitionResult {
        return $this->ledger->recordLaunch($identity, $authority, $launch, $process, $at);
    }

    public function recordOutcome(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ExecutionOutcome $outcome,
        DateTimeImmutable $at,
    ): TransitionResult {
        return $this->ledger->recordOutcome($identity, $authority, $outcome, $at);
    }

    public function releaseProcess(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ?ProcessObservation $observation,
        DateTimeImmutable $at,
    ): TransitionResult {
        return $this->ledger->releaseProcess($identity, $authority, $observation, $at);
    }

    public function reauthorize(ExecutionIdentity $identity, ExecutionAuthority $authority, DateTimeImmutable $at): TransitionResult
    {
        return $this->ledger->reauthorize($identity, $authority, $at);
    }

    public function find(ExecutionIdentity $identity): ?ExecutionRecord
    {
        return $this->ledger->find($identity);
    }

    public function unresolved(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        return $this->ledger->unresolved($source, $limit, $after);
    }

    public function holdingProcess(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        return $this->ledger->holdingProcess($source, $limit, $after);
    }
}
