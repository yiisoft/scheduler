<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Scheduler\Scheduler;

/**
 * Lists the scheduled tasks with their triggers and next run dates.
 */
#[AsCommand('schedule:list', 'Lists the scheduled tasks and their next run dates.')]
final class ListCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();

        $table = new Table($output);
        $table->setHeaders(['Schedule', 'Task', 'Trigger', 'Next Run']);

        foreach ($this->scheduler->getSchedules() as $schedule) {
            foreach ($schedule->tasks() as $task) {
                $next = $task->getTrigger()->getNextRunDate($now);

                $table->addRow([
                    $schedule->getName(),
                    $task->getId(),
                    (string) $task->getTrigger(),
                    null === $next ? '(exhausted)' : $next->format('Y-m-d H:i:s P'),
                ]);
            }
        }

        $table->render();

        return Command::SUCCESS;
    }
}
