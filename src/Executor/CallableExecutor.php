<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Executor;

use Yiisoft\Schedule\Callable\CallableFactory;
use Yiisoft\Schedule\Callable\ContainerProvider;
use Yiisoft\Schedule\Callable\InvocationGuardContainer;
use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;
use Yiisoft\Schedule\ScalarMetadata;
use Throwable;
use Closure;
use InvalidArgumentException;
use RuntimeException;

use function array_is_list;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function is_array;

/** Executes a process-local callable command. */
final class CallableExecutor implements ExecutorInterface
{
    private readonly ?Closure $guard;

    public function __construct(
        private readonly ContainerProvider $containerProvider,
        ?callable $guard = null,
    ) {
        $this->guard = $guard === null ? null : Closure::fromCallable($guard);
    }

    public function execute(ExecutionContext $context): ExecutionOutcome
    {
        try {
            if (!$context->command instanceof CallableCommand) {
                return new ExecutionOutcome(ExecutionOutcome::FAILED, 'callable.invalid_command');
            }
            $arguments = $this->arguments($context->command->arguments());
            $callable = $context->command->callable();

            $this->checkGuard();
            $container = $this->containerProvider->resolve();
            $this->checkGuard();
            $guarded = $this->guard === null ? $container : new InvocationGuardContainer($container, $this->guard);
            $invocation = (new CallableFactory($guarded))->create($callable);
            $this->checkGuard();
            $result = $invocation(...$arguments);
            $this->checkGuard();

            if ($result instanceof ExecutionOutcome) {
                return $result;
            }
            return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED, $this->reason($result));
        } catch (Throwable) {
            return new ExecutionOutcome(ExecutionOutcome::FAILED, 'callable.execution_failed');
        }
    }

    /** @return array<string, mixed> */
    private function arguments(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Callable payload must be a named argument map.');
        }
        if (array_is_list($payload) && $payload !== []) {
            throw new InvalidArgumentException('Callable payload must be a named argument map.');
        }

        /** @var array<string, mixed> $arguments */
        $arguments = [];
        foreach ($payload as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Callable argument names must be strings.');
            }
            if ($value !== null && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
                throw new InvalidArgumentException('Callable arguments must be scalar values.');
            }
            $arguments[$name] = $value;
        }
        return $arguments;
    }

    private function checkGuard(): void
    {
        if ($this->guard === null) {
            return;
        }
        if (($this->guard)() === false) {
            throw new RuntimeException('Invocation guard rejected execution.');
        }
    }

    private function reason(mixed $result): ?string
    {
        if ($result === null || $result === '') {
            return null;
        }
        if (!is_string($result) && !is_int($result) && !is_float($result) && !is_bool($result)) {
            return null;
        }
        ScalarMetadata::assertSupported($result);
        return $result === false ? '0' : (is_string($result) ? $result : (string) $result);
    }
}
