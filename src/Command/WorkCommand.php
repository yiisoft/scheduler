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
     * A task failure caught in the signal handler, rethrown once the loop has finished.
     */
    private ?Throwable $failure = null;

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
    }

    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [SIGTERM, SIGINT, SIGCHLD] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (SIGCHLD === $signal) {
            // A task process finished; collect its outcome without leaving the loop. The
            // handler runs at an arbitrary point of that loop, so a failing task must not
            // throw from here: it would abort a shutdown halfway through, stranding the
            // remaining children. Remember it and let the loop end on its own terms.
            try {
                $this->scheduler->reapChildren();
            } catch (Throwable $error) {
                $this->failure ??= $error;
                $this->scheduler->stop();
            }

            return false;
        }

        $this->scheduler->stop();

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        $this->scheduler->run(
            (float) $sleep,
            !(bool) $input->getOption('sequential'),
            null === $maxProcesses ? null : (int) $maxProcesses,
        );

        if (null !== $this->failure) {
            throw $this->failure;
        }

        $output->writeln('<info>Scheduler stopped.</info>');

        return Command::SUCCESS;
    }
}
