<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Yiisoft\Scheduler\Event\FailureEvent;
use Yiisoft\Scheduler\Event\PostRunEvent;
use Yiisoft\Scheduler\Event\PreRunEvent;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\Generator\TaskGenerator;
use Yiisoft\Scheduler\Handler\TaskHandlerInterface;

use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function extension_loaded;
use function function_exists;
use function flush;
use function hrtime;
use function in_array;
use function is_executable;
use function max;
use function min;
use function ob_end_clean;
use function ob_get_level;
use function sprintf;
use function usleep;

use const SIGCHLD;
use const SIGINT;
use const SIGKILL;
use const SIGTERM;
use const SIG_BLOCK;
use const SIG_DFL;
use const SIG_UNBLOCK;
use const WNOHANG;

/**
 * Runs the due tasks of one or more schedules.
 *
 * Use tick() for a single pass (e.g. from a system cron entry) or run() for a daemon loop.
 * The daemon loop sleeps until the next scheduled run instead of polling on a fixed interval
 * and, when ext-pcntl is available, forks a child process per due task so tasks sharing a due
 * time start together instead of blocking one another.
 */
final class Scheduler
{
    /**
     * How long a shutdown waits for the children to stop before it resorts to SIGKILL.
     */
    private const TERMINATION_TIMEOUT_SECONDS = 5;

    /**
     * What a finished child hands the process image over to, so PHP's shutdown never runs on
     * the parent's resources. Required by POSIX to be at this path.
     */
    private const EXIT_SHELL = '/bin/sh';

    /**
     * The longest the loop sleeps in one go while children are still running. SIGCHLD does
     * not interrupt the sleep unless a handler is registered for it, so with a far-away next
     * run the loop has to come back by itself to collect the finished ones.
     */
    private const CHILD_POLL_SECONDS = 0.5;

    /** @var array<string, TaskGenerator> */
    private array $generators = [];
    private bool $shouldStop = false;
    /** @var array<int, array{RecurringTask, TaskContext}> Child process id to its task and context. */
    private array $children = [];
    private bool $terminating = false;
    /**
     * Whether run() is driving the ticks. A task failure is then only logged, not thrown:
     * thrown, it would end the loop and with it every other task of the schedule. Dropped by
     * terminateChildren(), the last thing run() does: a child failing while the loop is on its
     * way out is still the loop's to log, not something to throw at whoever stopped it.
     */
    private bool $looping = false;
    private readonly LoggerInterface $logger;

    /**
     * @param iterable<Schedule> $schedules The schedules to run.
     */
    public function __construct(
        iterable $schedules,
        private readonly TaskHandlerInterface $handler,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        foreach ($schedules as $schedule) {
            $name = $schedule->getName();

            // Schedules are addressed by name, in the checkpoint cache key as well, so two of
            // them sharing one would quietly discard a schedule and mix up their positions.
            if (array_key_exists($name, $this->generators)) {
                throw new LogicException(
                    sprintf('A schedule named "%s" is already registered in the scheduler.', $name),
                );
            }

            $this->generators[$name] = new TaskGenerator($schedule, $clock);
        }
    }

    /**
     * Runs every due task once and returns how many tasks ran.
     *
     * @param bool $concurrent Fork a child process per due task, so tasks due at the same time
     * run in parallel instead of blocking one another. Requires ext-pcntl; without it the tasks
     * run one after another as before. A task failure then surfaces as a RuntimeException about
     * the child exit status when the child is reaped, and the PostRunEvent result is null
     * because the outcome cannot cross the process boundary. A task whose previous run is still
     * going is skipped rather than started a second time.
     * @param int|null $maxProcesses The most task processes to have running at once, null for
     * no limit. Once that many are running, the next due task waits for one of them to finish
     * instead of being started alongside them.
     */
    public function tick(bool $concurrent = false, ?int $maxProcesses = null): int
    {
        if (!$concurrent || !self::supportsForking()) {
            $count = 0;

            foreach ($this->generators as $generator) {
                foreach ($generator->getTasks() as $context => $task) {
                    $this->runTask($task, $context);
                    ++$count;
                }
            }

            return $count;
        }

        $due = [];

        foreach ($this->generators as $generator) {
            foreach ($generator->getTasks() as $context => $task) {
                $due[] = [$task, $context];
            }
        }

        $failure = null;

        // Draining the generators above already moved every checkpoint past these runs, so a
        // task that fails to start must not abort the ones queued behind it: they would never
        // be emitted again. Collect the first failure and report it once the batch is started.
        foreach ($due as $index => [$task, $context]) {
            try {
                if (!$this->awaitFreeSlot($maxProcesses)) {
                    $this->logger->warning(
                        'Stopping, {count} due task(s) of this tick are not started.',
                        ['count' => count($due) - $index],
                    );

                    break;
                }

                $this->forkTask($task, $context);
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }

        return count($due);
    }

    /**
     * The soonest pending run across all schedules, if any.
     */
    public function nextRunDate(): ?DateTimeImmutable
    {
        $next = null;

        foreach ($this->generators as $generator) {
            $date = $generator->getNextRunDate();

            if (null !== $date && (null === $next || $date < $next)) {
                $next = $date;
            }
        }

        return $next;
    }

    /**
     * Runs the scheduler loop until stop() is called (e.g. from a signal handler).
     *
     * A stop() requested before the loop starts, by a signal handler registered ahead of it,
     * is honoured: the loop is not entered at all.
     *
     * Between ticks the loop sleeps until the soonest pending run instead of waking up on a
     * fixed interval, so an idle daemon costs a couple of syscalls per run instead of a full
     * schedule evaluation per second.
     *
     * A task failure does not end the loop. The failure event and the schedule's failure
     * listeners are told about it as usual and it is logged as an error, whether a listener
     * ignored it or not; the other tasks, running or due later, are not affected.
     *
     * @param float $sleepSeconds The fallback sleep when no task has a pending run.
     * @param bool $concurrent Fork a child process per due task (see tick()). Falls back to
     * sequential execution when ext-pcntl is not available.
     * @param int|null $maxProcesses The most task processes to have running at once (see
     * tick()), null for no limit.
     *
     * @psalm-suppress RedundantCondition, TypeDoesNotContainType The flag is flipped by stop() from a signal handler.
     */
    public function run(float $sleepSeconds = 1.0, bool $concurrent = true, ?int $maxProcesses = null): void
    {
        $concurrent = $concurrent && self::supportsForking();
        $this->looping = true;

        try {
            while (!$this->shouldStop) {
                $this->tick($concurrent, $maxProcesses);

                if ($this->shouldStop) {
                    break;
                }

                $sleep = $this->computeSleep($sleepSeconds);

                if ($sleep > 0) {
                    $this->sleep($sleep);
                }

                $this->reapChildren();
            }
        } finally {
            $this->terminateChildren();
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Collects the outcome of every finished child process, dispatching its post-run or
     * failure event. Safe to call from a SIGCHLD handler; does nothing without ext-pcntl.
     *
     * Outside of run(), a failure that no listener ignored is thrown once the whole batch is
     * reaped, so one broken task neither kills its siblings nor goes silently unnoticed.
     * Inside of run() nothing is thrown for it; it is logged as an error instead, the way an
     * ignored failure is everywhere. A listener may still throw, though, and called from a
     * signal handler that lands wherever the main loop happens to be, so the handler should
     * catch it and let the loop finish instead of unwinding it from there.
     */
    public function reapChildren(): void
    {
        if ([] === $this->children || $this->terminating || !self::supportsForking()) {
            return;
        }

        $failure = null;

        // Only the processes we started: pcntl_wait() would also consume the exit status of a
        // child the host application forked, leaving its owner unable to collect it.
        foreach (array_keys($this->children) as $pid) {
            // A SIGCHLD handler may have re-entered this method and taken the entry already.
            if (!array_key_exists($pid, $this->children)) {
                continue;
            }

            if ($pid !== pcntl_waitpid($pid, $status, WNOHANG)) {
                continue;
            }

            [$task, $context] = $this->children[$pid];
            unset($this->children[$pid]);

            try {
                $this->handleChildOutcome($task, $context, $status);
            } catch (Throwable $error) {
                $failure ??= $error;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    public function hasRunningChildren(): bool
    {
        return [] !== $this->children;
    }

    /**
     * @return Schedule[]
     */
    public function getSchedules(): array
    {
        return array_map(
            static fn(TaskGenerator $generator): Schedule => $generator->getSchedule(),
            $this->generators,
        );
    }

    private static function supportsForking(): bool
    {
        return extension_loaded('pcntl') && function_exists('pcntl_fork');
    }

    /**
     * How long the loop may idle before the next tick: the time to the soonest pending run,
     * or the configured fallback when no schedule reports one.
     */
    private function computeSleep(float $fallback): float
    {
        $next = $this->nextRunDate();

        if (null === $next) {
            return $fallback;
        }

        return max(
            0.0,
            (float) $next->format('U.u') - (float) $this->clock->now()->format('U.u'),
        );
    }

    /**
     * Sleeps until the deadline, waking up early when a signal (a stop request or a finished
     * child, when a SIGCHLD handler is registered) interrupts the sleep.
     */
    private function sleep(float $seconds): void
    {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

        while (!$this->shouldStop) {
            $remaining = ($deadline - hrtime(true)) / 1_000_000_000;

            if ($remaining <= 0) {
                break;
            }

            // Without a SIGCHLD handler the signal is ignored and does not interrupt the
            // sleep, so a long idle stretch would leave finished tasks unreaped - and their
            // events undispatched - for its whole length.
            if ([] !== $this->children) {
                $remaining = min($remaining, self::CHILD_POLL_SECONDS);
            }

            self::waitFor($remaining);

            $this->reapChildren();
        }
    }

    /**
     * Sleeps for the given seconds, returning early when a signal interrupts the wait.
     *
     * usleep() is interrupted by a signal just like time_nanosleep(), and unlike it is
     * available everywhere: time_nanosleep() is only compiled in where nanosleep() is, which
     * leaves out Windows. Microsecond resolution is finer than any schedule needs.
     */
    private static function waitFor(float $seconds): void
    {
        $microseconds = (int) ($seconds * 1_000_000);

        // A remainder below a microsecond is not worth the syscall; the caller's deadline
        // check ends the wait right after this anyway.
        if ($microseconds < 1) {
            return;
        }

        usleep($microseconds);
    }

    private function runTask(RecurringTask $task, TaskContext $context): void
    {
        if (!$this->beforeRun($task, $context)) {
            return;
        }

        $this->executeTask($task, $context);
    }

    /**
     * Dispatches the pre-run event and the schedule's before listeners.
     *
     * Checked before the listeners run: a cancelled run has no after/onFailure counterpart,
     * so whatever a before listener opens here would never be closed.
     */
    private function beforeRun(RecurringTask $task, TaskContext $context): bool
    {
        $schedule = $this->generators[$context->scheduleName]->getSchedule();

        $preEvent = new PreRunEvent($context, $task);
        $this->dispatcher?->dispatch($preEvent);

        if ($preEvent->isCancelled()) {
            $this->logger->info('Task {id} of schedule {schedule} was cancelled.', ['id' => $context->taskId, 'schedule' => $context->scheduleName]);

            return false;
        }

        foreach ($schedule->getBeforeListeners() as $listener) {
            $listener($context, $task);
        }

        return true;
    }

    private function executeTask(RecurringTask $task, TaskContext $context): void
    {
        $schedule = $this->generators[$context->scheduleName]->getSchedule();

        try {
            $result = $this->handler->handle($task->getTask(), $context);
        } catch (Throwable $error) {
            $failureEvent = new FailureEvent($context, $task, $error);
            $this->dispatcher?->dispatch($failureEvent);
            foreach ($schedule->getFailureListeners() as $listener) {
                $listener($context, $task, $error);
            }

            if (!$failureEvent->isIgnored() && !$this->looping) {
                throw $error;
            }

            $this->logger->error(
                'Task {id} of schedule {schedule} failed: {error}',
                ['id' => $context->taskId, 'schedule' => $context->scheduleName, 'error' => $error->getMessage()],
            );

            return;
        }

        $this->dispatcher?->dispatch(new PostRunEvent($context, $task, $result));
        foreach ($schedule->getAfterListeners() as $listener) {
            $listener($context, $task, $result);
        }
    }

    /**
     * Starts the task in a child process. The pre-run event and the before listeners run in
     * the parent, so cancellation never pays for a fork; the post-run and failure events are
     * dispatched back in the parent when the child is reaped.
     */
    private function forkTask(RecurringTask $task, TaskContext $context): void
    {
        // One process per task at a time: a task that takes longer than its own interval
        // would otherwise pile up a process per due time and never catch up.
        if ($this->hasRunningChild($context)) {
            $this->logger->warning(
                'Task {id} of schedule {schedule} is still running, skipping this run.',
                ['id' => $context->taskId, 'schedule' => $context->scheduleName],
            );

            return;
        }

        if (!$this->beforeRun($task, $context)) {
            return;
        }

        // SIGCHLD must not arrive between the fork and the line recording the pid: the
        // handler would reap a child it does not know about yet and drop its outcome, and
        // the entry written afterwards would then be waited on forever.
        $blocked = self::blockChildSignal();

        $pid = pcntl_fork();

        if (0 === $pid) {
            $this->runChild($task, $context);
        }

        if (-1 === $pid) {
            self::unblockChildSignal($blocked);

            // Forking is refused (e.g. resource limits): run inline rather than drop the run.
            $this->executeTask($task, $context);

            return;
        }

        $this->children[$pid] = [$task, $context];

        self::unblockChildSignal($blocked);
    }

    /**
     * Waits until fewer than $maxProcesses children are running, collecting the finished ones
     * while it waits, so a burst of due tasks cannot outgrow the limit.
     *
     * @return bool Whether there is room now; false when a stop was requested while waiting.
     *
     * @psalm-suppress RedundantCondition The flag is flipped by stop() from a signal handler.
     */
    private function awaitFreeSlot(?int $maxProcesses): bool
    {
        if (null === $maxProcesses || $maxProcesses < 1) {
            return true;
        }

        while (count($this->children) >= $maxProcesses) {
            if ($this->shouldStop) {
                return false;
            }

            $this->reapChildren();

            if (count($this->children) >= $maxProcesses) {
                usleep(10_000);
            }
        }

        return true;
    }

    /**
     * Whether a child started for this very task is still running.
     */
    private function hasRunningChild(TaskContext $context): bool
    {
        foreach ($this->children as [, $running]) {
            if ($running->scheduleName === $context->scheduleName && $running->taskId === $context->taskId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The child's whole lifespan: run the task and report through the exit code. The full
     * error cannot cross to the parent, so it is logged here, next to where it happened.
     *
     * Note the child shares the parent's open sockets and connections; handlers meant for the
     * daemon should acquire their own instead of reusing ones opened before the fork.
     */
    private function runChild(RecurringTask $task, TaskContext $context): never
    {
        // The fork copied the parent's bookkeeping along with its signal handlers. Left as
        // they are, the inherited SIGCHLD handler would reap the subprocesses the task itself
        // starts, and the inherited SIGTERM handler would swallow the stop request a shutdown
        // sends, leaving SIGKILL as the only way to end this process.
        $this->children = [];
        $this->terminating = false;
        self::restoreDefaultSignalHandlers();

        // Whatever the parent had buffered before the fork belongs to the parent, which
        // writes it out itself; releasing the buffers here also keeps the task's own output
        // from piling up in a buffer nothing will ever flush.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        try {
            $this->handler->handle($task->getTask(), $context);
        } catch (Throwable $error) {
            $this->logger->error(
                'Task {id} of schedule {schedule} failed: {error}',
                ['id' => $context->taskId, 'schedule' => $context->scheduleName, 'error' => $error->getMessage()],
            );

            self::exitChild(1);
        }

        self::exitChild(0);
    }

    /**
     * Ends the child without PHP's shutdown sequence, which would otherwise run over
     * everything the fork copied: the destructor of a connection the parent is still using
     * would say goodbye on its behalf, and shutdown functions registered before the fork
     * would run a second time. Handing the process image to a shell that does nothing but
     * exit skips all of it and still reports the outcome through the exit status.
     *
     * Falls back to a plain exit where that is not possible; the stream writes made up to
     * here are not buffered by PHP, so neither path loses them.
     */
    private static function exitChild(int $code): never
    {
        flush();

        if (function_exists('pcntl_exec') && is_executable(self::EXIT_SHELL)) {
            pcntl_exec(self::EXIT_SHELL, ['-c', 'exit ' . $code]);
        }

        exit($code);
    }

    /**
     * Turns a child's exit status into the same events an inline run would have produced.
     */
    private function handleChildOutcome(RecurringTask $task, TaskContext $context, int $status): void
    {
        $schedule = $this->generators[$context->scheduleName]->getSchedule();

        if (pcntl_wifexited($status) && 0 === pcntl_wexitstatus($status)) {
            // The result cannot cross the process boundary, so listeners see null here.
            $this->dispatcher?->dispatch(new PostRunEvent($context, $task, null));
            foreach ($schedule->getAfterListeners() as $listener) {
                $listener($context, $task, null);
            }

            return;
        }

        $error = new RuntimeException(
            pcntl_wifexited($status)
                ? sprintf('The task process exited with code %d.', pcntl_wexitstatus($status))
                : sprintf('The task process was terminated by signal %d.', pcntl_wtermsig($status)),
        );

        $failureEvent = new FailureEvent($context, $task, $error);
        $this->dispatcher?->dispatch($failureEvent);
        foreach ($schedule->getFailureListeners() as $listener) {
            $listener($context, $task, $error);
        }

        if (!$failureEvent->isIgnored() && !$this->looping) {
            throw $error;
        }

        $this->logger->error(
            'Task {id} of schedule {schedule} failed: {error}',
            ['id' => $context->taskId, 'schedule' => $context->scheduleName, 'error' => $error->getMessage()],
        );
    }

    /**
     * On the way out, asks the still-running children to stop and waits for them briefly, so
     * a reload or deploy does not strand task processes. Their outcomes are not turned into
     * events: a shutdown is not a task failure.
     */
    private function terminateChildren(): void
    {
        if ([] === $this->children || !self::supportsForking()) {
            $this->looping = false;

            return;
        }

        // From here on these children are ours to collect, so a SIGCHLD handler must not
        // dispatch a failure for one this method is in the middle of stopping. The flag is
        // dropped again on the way out: run() may well be entered a second time.
        $this->terminating = true;

        try {
            $this->stopChildren();
        } finally {
            $this->terminating = false;
            $this->looping = false;
        }
    }

    /**
     * The shutdown itself: SIGTERM, a grace period, then SIGKILL for whatever is left.
     */
    private function stopChildren(): void
    {
        if (!function_exists('posix_kill')) {
            // Without ext-posix there is no way to ask them to stop, and waiting for tasks of
            // unknown length would hang the shutdown instead of ending it: let them finish on
            // their own and be reaped by init.
            $this->logger->warning(
                'Leaving {count} task process(es) behind: ext-posix is required to stop them.',
                ['count' => count($this->children)],
            );
            $this->children = [];

            return;
        }

        foreach (array_keys($this->children) as $pid) {
            posix_kill($pid, SIGTERM);
        }

        $deadline = hrtime(true) + self::TERMINATION_TIMEOUT_SECONDS * 1_000_000_000;
        $pending = array_keys($this->children);

        while (true) {
            foreach ($pending as $index => $pid) {
                // Anything but "still running" ends the wait for this one, a vanished child
                // (-1) included - there is nothing left to collect from it.
                if (0 !== pcntl_waitpid($pid, $status, WNOHANG)) {
                    unset($this->children[$pid], $pending[$index]);
                }
            }

            if ([] === $pending) {
                break;
            }

            if (hrtime(true) >= $deadline) {
                foreach ($pending as $pid) {
                    posix_kill($pid, SIGKILL);
                    // Just killed, so this returns at once; a signal interrupting it (-1)
                    // still ends the wait rather than looping on a process that is gone.
                    pcntl_waitpid($pid, $status);
                    unset($this->children[$pid]);
                }

                break;
            }

            usleep(10_000);
        }
    }

    /**
     * Keeps SIGCHLD from being delivered until the fork is recorded.
     *
     * @return bool Whether it has to be unblocked again, i.e. it was not blocked already.
     */
    private static function blockChildSignal(): bool
    {
        if (!function_exists('pcntl_sigprocmask')) {
            return false;
        }

        $previous = [];

        if (!pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previous)) {
            return false;
        }

        return !in_array(SIGCHLD, $previous, true);
    }

    private static function unblockChildSignal(bool $blocked): void
    {
        if ($blocked) {
            pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD]);
        }
    }

    /**
     * Hands a freshly forked child the signal dispositions of a plain process, so the
     * handlers the daemon installed for itself do not act on its behalf.
     */
    private static function restoreDefaultSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        foreach ([SIGCHLD, SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        if (function_exists('pcntl_sigprocmask')) {
            pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD]);
        }
    }
}
