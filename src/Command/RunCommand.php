<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stateless = [];

        foreach ($this->scheduler->getSchedules() as $schedule) {
            if (!$schedule->isStateful()) {
                $stateless[] = $schedule->getName();
            }
        }

        // This command exits between runs, so it only knows what already ran from a checkpoint.
        // Without one every invocation starts from scratch and nothing is ever due.
        if ([] !== $stateless) {
            $output->writeln(sprintf(
                '<error>Schedule "%s" has no persisted checkpoint, so no run can ever be detected as due. '
                . 'Make it stateful with Schedule::stateful($cache), or run the scheduler as a daemon '
                . 'with schedule:work.</error>',
                implode('", "', $stateless),
            ));

            return Command::FAILURE;
        }

        try {
            $count = $this->scheduler->tick();
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($count > 0) {
            $output->writeln(sprintf('Ran %d task(s).', $count));
        } else {
            $output->writeln('No task is due.');
        }

        return Command::SUCCESS;
    }
}
