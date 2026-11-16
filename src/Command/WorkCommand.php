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

use function extension_loaded;
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

            // A task that finished is the one moment this process knows something changed
            // without the loop telling it, so it is where the record is brought up to date.
            // A task that failed does not end the daemon, and must not report it as stopped.
            $this->status?->working($this->doing());

            return false;
        }

        $this->status?->working('Stopping, waiting for the running tasks.');
        $this->scheduler->stop();

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->status = ProgramStatus::of($output, !$input->getOption('no-program-status'));

        /** @var mixed $sleep */
        $sleep = $input->getOption('sleep');

        // A non-numeric or non-positive value would silently cast to zero and spin the loop
        // at full speed instead of sleeping between ticks.
        if (!is_numeric($sleep) || (float) $sleep <= 0) {
            $output->writeln(sprintf(
                '<error>The --sleep option must be a number greater than zero, "%s" given.</error>',
                is_scalar($sleep) ? (string) $sleep : get_debug_type($sleep),
            ));

            return Command::INVALID;
        }

        /** @var mixed $maxProcesses */
        $maxProcesses = $input->getOption('max-processes');

        // A fractional or non-positive limit would round down to a count no task fits in,
        // leaving the loop waiting for a slot that never frees.
        if (null !== $maxProcesses && (!is_numeric($maxProcesses) || (float) $maxProcesses < 1 || (float) (int) $maxProcesses !== (float) $maxProcesses)) {
            $output->writeln(sprintf(
                '<error>The --max-processes option must be a whole number greater than zero, "%s" given.</error>',
                is_scalar($maxProcesses) ? (string) $maxProcesses : get_debug_type($maxProcesses),
            ));

            return Command::INVALID;
        }

        $output->writeln('<info>Scheduler started.</info>');

        // A daemon between runs looks exactly like a dead one from the outside, which is the
        // state worth reporting: the terminal drops a working record when the process ends.
        $this->status->working($this->doing());

        try {
            $this->scheduler->run(
                (float) $sleep,
                !(bool) $input->getOption('sequential'),
                null === $maxProcesses ? null : (int) $maxProcesses,
            );
        } catch (Throwable $error) {
            $this->status->error($error->getMessage());

            throw $error;
        }

        if (null !== $this->failure) {
            $this->status->error($this->failure->getMessage());

            throw $this->failure;
        }

        $output->writeln('<info>Scheduler stopped.</info>');
        $this->status->done('Scheduler stopped.');

        return Command::SUCCESS;
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
