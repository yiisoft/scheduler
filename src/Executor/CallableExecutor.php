<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Closure;
use Psr\Container\ContainerInterface;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\TaskContext;

use function get_debug_type;
use function is_callable;
use function sprintf;

/**
 * Calls the callable a {@see CallableCommand} holds, with the context of the run as its argument.
 *
 * A command naming a service rather than holding a callable is resolved here, in the process
 * about to do the work: the container this executor was built with is that process's own, so
 * the dependencies the service is given are the ones that process has.
 */
final class CallableExecutor implements ExecutorInterface
{
    public function __construct(private readonly ?ContainerInterface $container = null) {}

    public function execute(CommandInterface $command, TaskContext $context): mixed
    {
        if (!$command instanceof CallableCommand) {
            throw new LogicException(
                sprintf('This executor runs "%s" commands, and was given "%s".', CallableCommand::class, $command::class),
            );
        }

        return ($this->callable($command))($context);
    }

    private function callable(CallableCommand $command): Closure
    {
        $callable = $command->getCallable();

        if (null !== $callable) {
            return $callable;
        }

        /** @var array{string, string} $service */
        $service = $command->getService();
        [$id, $method] = $service;

        if (null === $this->container) {
            throw new LogicException(
                sprintf(
                    'The "%s" command names the "%s" service, so this executor needs the container of the process running it.',
                    CallableCommand::class,
                    $id,
                ),
            );
        }

        $resolved = $this->container->get($id);

        if (!is_callable([$resolved, $method])) {
            throw new LogicException(
                sprintf('The "%s" service is of type "%s", which has no callable "%s" method.', $id, get_debug_type($resolved), $method),
            );
        }

        return Closure::fromCallable([$resolved, $method]);
    }
}
