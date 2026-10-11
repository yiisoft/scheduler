<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Event\FailureEvent;
use Yiisoft\Scheduler\Event\PostRunEvent;
use Yiisoft\Scheduler\Event\PreRunEvent;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Tests\Support\InMemoryMutex;
use RuntimeException;

use function sprintf;

use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const LOCK_EX;
use const SIGKILL;
use const SIGTERM;
use const SIG_DFL;

final class SchedulerTest extends TestCase
{
    public function testDueTaskRuns(): void
    {
        $clock = new TestClock();
        $ran = [];

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                $ran[] = 'hit';
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $this->assertSame(0, $scheduler->tick());

        $clock->advance('+1 minute');
        $this->assertSame(1, $scheduler->tick());
        $this->assertSame(['hit'], $ran);
    }

    public function testTaskRunsOncePerDueDate(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();
        $scheduler->tick();

        $this->assertSame(1, $ran);
    }

    public function testMissedRunsAreCaughtUp(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+3 minutes');
        $scheduler->tick();

        $this->assertSame(3, $ran);
    }

    public function testProcessOnlyLastMissedRun(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())
            ->processOnlyLastMissedRun()
            ->task(
                RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                    ++$ran;
                }),
            );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+3 minutes');
        $scheduler->tick();

        $this->assertSame(1, $ran);
    }

    public function testStatefulScheduleResumesAcrossProcesses(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $ran = 0;

        $buildScheduler = static function () use ($cache, $clock, &$ran): Scheduler {
            $schedule = (new Schedule())
                ->stateful($cache)
                ->task(
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        ++$ran;
                    }),
                );

            return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        };

        $scheduler = $buildScheduler();
        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        // The first process dies; a new one resumes from the persisted checkpoint.
        $scheduler = $buildScheduler();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(2, $ran);
    }

    public function testPreRunEventCancelsTheRun(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof PreRunEvent) {
                    $event->cancel();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(0, $ran);
    }

    public function testPostRunEventReceivesTheResult(): void
    {
        $clock = new TestClock();
        $results = [];

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static fn(): string => 'done'),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public array $results = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof PostRunEvent) {
                    $this->results[] = $event->result;
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(['done'], $dispatcher->results);
    }

    public function testFailureIsRethrownUnlessIgnored(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task failed.');

        $scheduler->tick();
    }

    public function testIgnoredFailureLetsTheSchedulerContinue(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof FailureEvent) {
                    $event->ignore();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $this->assertSame(2, $scheduler->tick());
        $this->assertSame(1, $ran);
    }

    public function testScheduleListeners(): void
    {
        $clock = new TestClock();
        $calls = [];

        $schedule = (new Schedule())
            ->before(static function () use (&$calls): void {
                $calls[] = 'before';
            })
            ->after(static function () use (&$calls): void {
                $calls[] = 'after';
            })
            ->task(
                RecurringTask::cron('* * * * *', static fn() => null),
            );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(['before', 'after'], $calls);
    }

    public function testDuplicateTaskIdIsRejected(): void
    {
        $task = RecurringTask::cron('* * * * *', 'cache:clear');

        $schedule = new Schedule();

        $this->expectException(LogicException::class);

        $schedule->task($task, $task);
    }

    public function testLockedScheduleIsSkipped(): void
    {
        $clock = new TestClock();
        $mutex = new InMemoryMutex();
        $ran = 0;

        $schedule = (new Schedule())
            ->lock($mutex)
            ->task(
                RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                    ++$ran;
                }),
            );

        // Another process holds the schedule mutex.
        $mutex->forceLock();

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $this->assertSame(0, $scheduler->tick());
        $this->assertSame(0, $ran);
    }

    public function testMissedRunsAreCaughtUpAcrossProcesses(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $ran = 0;

        $buildScheduler = static function () use ($cache, $clock, &$ran): Scheduler {
            $schedule = (new Schedule())
                ->stateful($cache)
                ->task(
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        ++$ran;
                    }),
                );

            return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        };

        // First process ticks once, then is down for three minutes.
        $buildScheduler()->tick();

        $clock->advance('+3 minutes');

        // A new process resumes from the persisted checkpoint and catches up every missed run.
        $buildScheduler()->tick();

        $this->assertSame(3, $ran);
    }

    public function testTasksSharingADueTimeAreNotReRunAfterARestart(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $ran = [];

        $buildScheduler = static function () use ($cache, $clock, &$ran): Scheduler {
            $schedule = (new Schedule())
                ->stateful($cache)
                ->task(
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        $ran[] = 'A';
                    }),
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        $ran[] = 'B';
                    }),
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        $ran[] = 'C';
                    }),
                );

            return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        };

        $buildScheduler()->tick();

        $clock->advance('+1 minute');
        $buildScheduler()->tick();

        // A fresh process with nothing newly due must not replay the runs of the shared due time.
        $this->assertSame(0, $buildScheduler()->tick());
        $this->assertSame(['A', 'B', 'C'], $ran);
    }

    public function testStandbyProcessDoesNotReplayRunsHandledByTheLockHolder(): void
    {
        $cache = new InMemoryCache();
        $mutex = new InMemoryMutex();
        $clock = new TestClock();
        $ran = 0;

        $buildScheduler = static function () use ($cache, $mutex, $clock, &$ran): Scheduler {
            $schedule = (new Schedule())
                ->stateful($cache)
                ->lock($mutex)
                ->task(
                    RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                        ++$ran;
                    }),
                );

            return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        };

        $primary = $buildScheduler();
        $standby = $buildScheduler();

        $primary->tick();
        $standby->tick();

        // The primary wins the mutex for four minutes in a row.
        for ($minute = 0; $minute < 4; ++$minute) {
            $clock->advance('+1 minute');
            $primary->tick();
        }

        $this->assertSame(4, $ran);

        // The standby takes over: only the newly due minute is its to run.
        $clock->advance('+1 minute');

        $this->assertSame(1, $standby->tick());
        $this->assertSame(5, $ran);
    }

    public function testTaskFailureIsNotMaskedByACheckpointPersistenceFailure(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();

        $schedule = (new Schedule())
            ->stateful($cache)
            ->task(
                RecurringTask::cron('* * * * *', static function (): void {
                    throw new RuntimeException('Task failed.');
                }),
            );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $cache->failWrites();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task failed.');

        $scheduler->tick();
    }

    public function testDuplicateScheduleNameIsRejected(): void
    {
        $clock = new TestClock();

        $first = (new Schedule())->task(RecurringTask::cron('* * * * *', 'app:first'));
        $second = (new Schedule())->task(RecurringTask::cron('* * * * *', 'app:second'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('default');

        new Scheduler([$first, $second], new CallableTaskHandler(), $clock);
    }

    public function testSchedulesWithDistinctNamesAreBothKept(): void
    {
        $clock = new TestClock();
        $ran = [];

        $first = (new Schedule('first'))->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                $ran[] = 'first';
            }),
        );
        $second = (new Schedule('second'))->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                $ran[] = 'second';
            }),
        );

        $scheduler = new Scheduler([$first, $second], new CallableTaskHandler(), $clock);

        $this->assertCount(2, $scheduler->getSchedules());

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(['first', 'second'], $ran);
    }

    public function testCancelledRunSkipsTheScheduleListeners(): void
    {
        $clock = new TestClock();
        $calls = [];

        $schedule = (new Schedule())
            ->before(static function () use (&$calls): void {
                $calls[] = 'before';
            })
            ->after(static function () use (&$calls): void {
                $calls[] = 'after';
            })
            ->task(
                RecurringTask::cron('* * * * *', static fn() => null),
            );

        $dispatcher = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof PreRunEvent) {
                    $event->cancel();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame([], $calls);
    }

    public function testStopRequestedBeforeTheLoopStartsIsHonoured(): void
    {
        $clock = new TestClock();
        $ran = 0;
        $scheduler = null;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$ran, &$scheduler): void {
                ++$ran;
                $scheduler?->stop();
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');

        // A signal handled between the handler registration and the loop must not be lost.
        $scheduler->stop();
        $scheduler->run(0.0);

        $this->assertSame(0, $ran);
    }

    public function testTaskFailureDoesNotEndTheLoop(): void
    {
        $clock = new TestClock();
        $scheduler = null;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
            RecurringTask::cron('* * * * *', static function () use (&$scheduler): void {
                $scheduler?->stop();
            }),
        );

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (LogLevel::ERROR === $level) {
                    $this->errors[] = (string) ($context['error'] ?? '');
                }
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, null, $logger);

        $scheduler->tick();
        $clock->advance('+1 minute');

        // The task behind the failing one stops the loop, which it only gets to do if the
        // failure was not thrown out of it.
        $scheduler->run(0.01, false);

        $this->assertSame(['Task failed.'], $logger->errors);
    }

    public function testTaskFailureIsThrownAgainOnceTheLoopHasEnded(): void
    {
        $clock = new TestClock();
        $scheduler = null;
        $fail = false;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$scheduler, &$fail): void {
                $scheduler?->stop();

                if ($fail) {
                    throw new RuntimeException('Task failed.');
                }
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->run(0.01, false);

        $fail = true;
        $clock->advance('+1 minute');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task failed.');

        $scheduler->tick();
    }

    #[RequiresPhpExtension('pcntl')]
    public function testForkedTaskFailureDoesNotEndTheLoop(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public ?Scheduler $scheduler = null;
            /** @var list<string> */
            public array $failures = [];

            public function dispatch(object $event): object
            {
                // Not ignored: the loop has to cope with the failure by itself.
                if ($event instanceof FailureEvent) {
                    $this->failures[] = $event->error->getMessage();
                    $this->scheduler?->stop();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);
        $dispatcher->scheduler = $scheduler;

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->run(0.01);

        $this->assertSame(['The task process exited with code 1.'], $dispatcher->failures);
    }

    public function testNextRunDateIsNullWithoutTasks(): void
    {
        $scheduler = new Scheduler([new Schedule()], new CallableTaskHandler(), new TestClock());

        $this->assertNull($scheduler->nextRunDate());
    }

    public function testNextRunDateReflectsTheSoonestPendingRun(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static fn() => null),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $this->assertSame('2026-01-01 00:01:00', $scheduler->nextRunDate()?->format('Y-m-d H:i:s'));

        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame('2026-01-01 00:02:00', $scheduler->nextRunDate()?->format('Y-m-d H:i:s'));
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentTickRunsDueTasksInParallel(): void
    {
        $clock = new TestClock();
        $dir = sys_get_temp_dir() . '/yii-scheduler-test-' . uniqid();
        mkdir($dir);

        // Each task waits for the other to have started; run one after another,
        // the first would time out waiting for the second.
        $waitForPeer = static function (string $own, string $peer) use ($dir): void {
            file_put_contents("$dir/$own.pid", (string) getmypid());

            $deadline = microtime(true) + 10;

            while (!file_exists("$dir/$peer.pid")) {
                if (microtime(true) > $deadline) {
                    file_put_contents("$dir/$own.timeout", '1');

                    return;
                }

                usleep(10_000);
            }
        };

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use ($waitForPeer): void {
                $waitForPeer('a', 'b');
            }),
            RecurringTask::cron('* * * * *', static function () use ($waitForPeer): void {
                $waitForPeer('b', 'a');
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public array $results = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof PostRunEvent) {
                    $this->results[] = $event->result;
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        try {
            $scheduler->tick();
            $clock->advance('+1 minute');

            $this->assertSame(2, $scheduler->tick(true));

            $deadline = microtime(true) + 15;
            while ($scheduler->hasRunningChildren() && microtime(true) < $deadline) {
                $scheduler->reapChildren();
                usleep(10_000);
            }

            $pidA = file_exists("$dir/a.pid") ? (int) file_get_contents("$dir/a.pid") : null;
            $pidB = file_exists("$dir/b.pid") ? (int) file_get_contents("$dir/b.pid") : null;

            $this->assertNotNull($pidA);
            $this->assertNotNull($pidB);
            $this->assertNotSame($pidA, $pidB);
            $this->assertNotSame(getmypid(), $pidA);
            $this->assertNotSame(getmypid(), $pidB);
            $this->assertFileDoesNotExist("$dir/a.timeout");
            $this->assertFileDoesNotExist("$dir/b.timeout");

            // The result cannot cross the process boundary, so the events carry null.
            $this->assertSame([null, null], $dispatcher->results);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentTickReportsChildFailure(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public array $failures = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof FailureEvent) {
                    $this->failures[] = $event->error->getMessage();
                    $event->ignore();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick(true);

        while ($scheduler->hasRunningChildren()) {
            $scheduler->reapChildren();
            usleep(10_000);
        }

        $this->assertSame(['The task process exited with code 1.'], $dispatcher->failures);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentTickThrowsUnignoredChildFailure(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function (): void {
                throw new RuntimeException('Task failed.');
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The task process exited with code 1.');

        while ($scheduler->hasRunningChildren()) {
            $scheduler->reapChildren();
            usleep(10_000);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentTickSkipsATaskWhosePreviousRunIsStillRunning(): void
    {
        $clock = new TestClock();
        $dir = sys_get_temp_dir() . '/yii-scheduler-test-' . uniqid();
        mkdir($dir);

        // Runs until released, so the second due time arrives while the first run is still on.
        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use ($dir): void {
                file_put_contents("$dir/runs", getmypid() . "\n", FILE_APPEND | LOCK_EX);

                $deadline = microtime(true) + 10;

                while (!file_exists("$dir/release") && microtime(true) < $deadline) {
                    usleep(10_000);
                }
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        try {
            $scheduler->tick();
            $clock->advance('+1 minute');
            $scheduler->tick(true);

            // The first child has to be up, or the second tick would have nothing to skip.
            $deadline = microtime(true) + 10;
            while (!file_exists("$dir/runs") && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $clock->advance('+1 minute');
            $scheduler->tick(true);

            file_put_contents("$dir/release", '1');

            $deadline = microtime(true) + 15;
            while ($scheduler->hasRunningChildren() && microtime(true) < $deadline) {
                $scheduler->reapChildren();
                usleep(10_000);
            }

            $this->assertFalse($scheduler->hasRunningChildren());
            $this->assertCount(1, file("$dir/runs", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testForkedTaskDoesNotInheritTheParentsSignalHandlers(): void
    {
        $clock = new TestClock();
        $dir = sys_get_temp_dir() . '/yii-scheduler-test-' . uniqid();
        mkdir($dir);

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use ($dir): void {
                file_put_contents("$dir/pid", (string) getmypid());

                $deadline = microtime(true) + 15;

                while (microtime(true) < $deadline) {
                    usleep(10_000);
                }
            }),
        );

        $dispatcher = new class implements EventDispatcherInterface {
            public array $failures = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof FailureEvent) {
                    $this->failures[] = $event->error->getMessage();
                    $event->ignore();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock, $dispatcher);

        // The daemon handles SIGTERM itself; forked without a reset, the child would inherit
        // that handler and quietly ignore the stop request a shutdown sends it.
        $asyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {});

        try {
            $scheduler->tick();
            $clock->advance('+1 minute');
            $scheduler->tick(true);

            $deadline = microtime(true) + 10;
            while (!file_exists("$dir/pid") && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $pid = (int) file_get_contents("$dir/pid");
            $this->assertNotSame(0, $pid);

            posix_kill($pid, SIGTERM);

            $deadline = microtime(true) + 10;
            while ($scheduler->hasRunningChildren() && microtime(true) < $deadline) {
                $scheduler->reapChildren();
                usleep(10_000);
            }

            if ($scheduler->hasRunningChildren()) {
                posix_kill($pid, SIGKILL);
                $this->fail('The forked task ignored SIGTERM.');
            }

            $this->assertSame(
                [sprintf('The task process was terminated by signal %d.', SIGTERM)],
                $dispatcher->failures,
            );
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_async_signals($asyncSignals);
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentTickKeepsToTheProcessLimit(): void
    {
        $clock = new TestClock();
        $dir = sys_get_temp_dir() . '/yii-scheduler-test-' . uniqid();
        mkdir($dir);

        // Each run brackets itself in the log, so two running at once would interleave.
        $record = static function (string $marker) use ($dir): void {
            file_put_contents("$dir/order", $marker . "\n", FILE_APPEND | LOCK_EX);
        };

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use ($record): void {
                $record('start a');
                usleep(100_000);
                $record('end a');
            }),
            RecurringTask::cron('* * * * *', static function () use ($record): void {
                $record('start b');
                usleep(100_000);
                $record('end b');
            }),
            RecurringTask::cron('* * * * *', static function () use ($record): void {
                $record('start c');
                usleep(100_000);
                $record('end c');
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        try {
            $scheduler->tick();
            $clock->advance('+1 minute');
            $scheduler->tick(true, 1);

            $deadline = microtime(true) + 15;
            while ($scheduler->hasRunningChildren() && microtime(true) < $deadline) {
                $scheduler->reapChildren();
                usleep(10_000);
            }

            $this->assertFalse($scheduler->hasRunningChildren());

            $order = file("$dir/order", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $this->assertCount(6, $order);

            // Every run has ended before the next one starts, whatever order they came in.
            foreach ([0, 2, 4] as $index) {
                $this->assertStringStartsWith('start ', $order[$index]);
                $this->assertSame('end ' . substr($order[$index], 6), $order[$index + 1]);
            }
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testForkedTaskDoesNotRunTheParentsShutdownSequence(): void
    {
        $clock = new TestClock();
        $marker = sys_get_temp_dir() . '/yii-scheduler-teardown-' . uniqid();

        // Stands in for whatever the daemon holds open across the fork - a connection, say,
        // whose destructor would say goodbye to the server the parent is still talking to.
        $shared = new class ($marker) {
            public function __construct(private readonly string $path) {}

            public function __destruct()
            {
                file_put_contents($this->path, "destructed\n", FILE_APPEND);
            }
        };

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use ($shared): void {
                // Only to carry the object across the fork.
                unset($shared);
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        try {
            $scheduler->tick();
            $clock->advance('+1 minute');
            $scheduler->tick(true);

            $deadline = microtime(true) + 15;
            while ($scheduler->hasRunningChildren() && microtime(true) < $deadline) {
                $scheduler->reapChildren();
                usleep(10_000);
            }

            $this->assertFalse($scheduler->hasRunningChildren());
            $this->assertFileDoesNotExist($marker);

            // It does run where it belongs, in the process that owns the object - which is
            // what makes the check above more than a statement about this destructor.
            unset($scheduler, $schedule, $shared);

            $this->assertFileExists($marker);
        } finally {
            if (file_exists($marker)) {
                unlink($marker);
            }
        }
    }
}
