<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\TaskContext;

use function extension_loaded;
use function getmypid;
use function get_debug_type;
use function is_numeric;
use function is_scalar;
use function sprintf;

use const SIGCHLD;
use const SIGINT;
use const SIGTERM;

/**
 * Runs the scheduler as a daemon, sleeping until the next scheduled run instead of polling
 * on a fixed interval. With ext-pcntl, each due task runs in its own forked process, so
 * tasks sharing a due time start together instead of blocking one another, up to the limit
 * --max-processes sets.
 */
#[AsCommand('schedule:work', 'Runs the scheduler as a long-running process.')]
final class WorkCommand extends Command implements SignalableCommandInterface
{
    /**
     * An error caught in the signal handler, rethrown once the loop has finished.
     */
    private ?Throwable $failure = null;

    /**
     * What the terminal is told the daemon is doing. Built once the output is known, and
     * kept because the signal handler refreshes it between ticks. Null until the command
     * runs, because a signal can reach the handler of a command that never started.
     */
    private ?ProgramStatus $status = null;

    /**
     * Whether the daemon is on its way out. A child ending during the shutdown must not put
     * the record back to the work the daemon is no longer taking on.
     */
    private bool $stopping = false;

    /** The process the record belongs to: a forked task is not it. */
    private int|false $owner = false;

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'sleep',
            's',
            InputOption::VALUE_REQUIRED,
            'The seconds to sleep when no task has a pending run.',
            1,
        );
        $this->addOption(
            'sequential',
            null,
            InputOption::VALUE_NONE,
            'Run due tasks one after another instead of forking a process per task.',
        );
        $this->addOption(
            'max-processes',
            null,
            InputOption::VALUE_REQUIRED,
            'The most task processes to run at once. Unlimited by default.',
        );
        $this->addOption(
            'no-program-status',
            null,
            InputOption::VALUE_NONE,
            'Do not tell the terminal what the scheduler is doing.',
        );
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [SIGTERM, SIGINT, SIGCHLD] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (SIGCHLD === $signal) {
            // A task process finished; collect its outcome without leaving the loop. A failing
            // task is not thrown while the loop runs, but a listener told about it may throw.
            // The handler runs at an arbitrary point of that loop, so nothing must be thrown
            // from here: it would abort a shutdown halfway through, stranding the remaining
            // children. Remember the error and let the loop end on its own terms.
            try {
                $this->scheduler->reapChildren();
            } catch (Throwable $error) {
                $this->failure ??= $error;
                $this->scheduler->stop();
            }

            // A task that failed does not end the daemon, and must not report it as stopped.
            $this->refresh($this->doing());

            return false;
        }

        $this->status?->working('Stopping, waiting for the running tasks.');
        $this->stopping = true;
        $this->scheduler->stop();

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->status = ProgramStatus::of($output, !$input->getOption('no-program-status'));
        $this->owner = getmypid();

        /** @var mixed $sleep */
        $sleep = $input->getOption('sleep');

        // A non-numeric or non-positive value would silently cast to zero and spin the loop
        // at full speed instead of sleeping between ticks.
        if (!is_numeric($sleep) || (float) $sleep <= 0) {
            $message = sprintf(
                'The --sleep option must be a number greater than zero, "%s" given.',
                is_scalar($sleep) ? (string) $sleep : get_debug_type($sleep),
            );

            $output->writeln(sprintf('<error>%s</error>', $message));
            $status->error($message);

            return Command::INVALID;
        }

        /** @var mixed $maxProcesses */
        $maxProcesses = $input->getOption('max-processes');

        // A fractional or non-positive limit would round down to a count no task fits in,
        // leaving the loop waiting for a slot that never frees.
        if (null !== $maxProcesses && (!is_numeric($maxProcesses) || (float) $maxProcesses < 1 || (float) (int) $maxProcesses !== (float) $maxProcesses)) {
            $message = sprintf(
                'The --max-processes option must be a whole number greater than zero, "%s" given.',
                is_scalar($maxProcesses) ? (string) $maxProcesses : get_debug_type($maxProcesses),
            );

            $output->writeln(sprintf('<error>%s</error>', $message));
            $status->error($message);

            return Command::INVALID;
        }

        $output->writeln('<info>Scheduler started.</info>');

        $this->reportWork();

        // A daemon between runs looks exactly like a dead one from the outside, which is the
        // state worth reporting: the terminal drops a working record when the process ends.
        $status->working($this->doing());

        try {
            $this->scheduler->run(
                (float) $sleep,
                !(bool) $input->getOption('sequential'),
                null === $maxProcesses ? null : (int) $maxProcesses,
            );
        } catch (Throwable $error) {
            $status->error($error->getMessage());

            throw $error;
        }

        if (null !== $this->failure) {
            $status->error($this->failure->getMessage());

            throw $this->failure;
        }

        $output->writeln('<info>Scheduler stopped.</info>');
        $status->done('Scheduler stopped.');

        return Command::SUCCESS;
    }

    /**
     * Follows the record of what the daemon is doing through the work of a tick, which the
     * loop itself does not report: the listeners of a schedule are the one point inside it
     * this command has. A listener added here lives as long as the process does, which is
     * the whole of this command.
     */
    private function reportWork(): void
    {
        foreach ($this->scheduler->getSchedules() as $schedule) {
            $schedule
                ->before(function (TaskContext $context): void {
                    $this->refresh(sprintf('Running task %s.', $context->taskId));
                })
                ->after(function (): void {
                    $this->refresh($this->doing());
                })
                ->onFailure(function (): void {
                    $this->refresh($this->doing());
                });
        }
    }

    /**
     * Brings the record up to date, where it is still this process's to report.
     *
     * A forked task runs the listeners of its schedule as well, and the daemon is not its to
     * describe; a shutdown has already said what it is doing and is not taking work on.
     */
    private function refresh(string $message): void
    {
        if ($this->stopping || getmypid() !== $this->owner) {
            return;
        }

        $this->status?->working($message);
    }

    /**
     * What the daemon is doing, as far as it can say between two ticks.
     *
     * Only what is already in hand: the time of the next run would have to be read from the
     * checkpoint, which takes the schedule lock and can fail, and this is called from a
     * signal handler. A date written once would also go stale where nothing refreshes it,
     * which is a daemon running its tasks one after another, or any host without ext-pcntl:
     * there is no child to end and no signal to refresh on. A time in the past reads exactly
     * like the dead scheduler this is here to tell apart from a live one.
     */
    private function doing(): string
    {
        return $this->scheduler->hasRunningChildren() ? 'Running a task.' : 'Waiting for the next run.';
    }
}
