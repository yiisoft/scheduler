<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Yiisoft\Scheduler\Scheduler;

use function implode;
use function sprintf;

/**
 * Runs every due task once. Designed for a system cron entry running every minute:
 * `* * * * * php yii schedule:run`.
 */
#[AsCommand('schedule:run', 'Runs the tasks that are due right now, then exits.')]
final class RunCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption(
            'no-program-status',
            null,
            InputOption::VALUE_NONE,
            'Do not tell the terminal what the scheduler is doing.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = ProgramStatus::of($output, !$input->getOption('no-program-status'));
        $stateless = [];

        foreach ($this->scheduler->getSchedules() as $schedule) {
            if (!$schedule->isStateful()) {
                $stateless[] = $schedule->getName();
            }
        }

        // This command exits between runs, so it only knows what already ran from a checkpoint.
        // Without one every invocation starts from scratch and nothing is ever due.
        if ([] !== $stateless) {
            $message = sprintf(
                'Schedule "%s" has no persisted checkpoint, so no run can ever be detected as due. '
                . 'Make it stateful with Schedule::stateful($cache), or run the scheduler as a daemon '
                . 'with schedule:work.',
                implode('", "', $stateless),
            );

            $output->writeln(sprintf('<error>%s</error>', $message));
            $status->error($message);

            return Command::FAILURE;
        }

        // The batch, not the scheduling: a due task can hold the terminal for as long as the
        // work it does takes.
        $status->working('Running the tasks that are due.');

        try {
            $count = $this->scheduler->tick();
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            $status->error($e->getMessage());

            return Command::FAILURE;
        }

        $message = $count > 0 ? sprintf('Ran %d task(s).', $count) : 'No task is due.';

        $output->writeln($message);

        $status->done($message);

        return Command::SUCCESS;
    }
}
