<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\TaskContext;

use function sprintf;

/**
 * Runs a {@see PhpCommand} by resolving it to the program it is — a PHP binary and a script —
 * and handing that to the executor of programs.
 */
final class PhpExecutor implements ExecutorInterface
{
    public function __construct(private readonly ProcessExecutor $processes = new ProcessExecutor()) {}

    public function execute(CommandInterface $command, TaskContext $context): mixed
    {
        if (!$command instanceof PhpCommand) {
            throw new LogicException(
                sprintf('This executor runs "%s" commands, and was given "%s".', PhpCommand::class, $command::class),
            );
        }

        return $this->processes->execute($command->resolve(), $context);
    }
}
