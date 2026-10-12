<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Executor\{Command, CommandExecutorMap};
use Yiisoft\Scheduler\Executor\CommandExecutorRuntime;
use Closure;

use function count;

final class SchedulerConfig
{
    /** @var array<int, ScheduleBuilder> */
    private array $schedules = [];
    private ?TimeoutPolicy $timeout = null;
    private int $asyncWorkerCapacity = 16;
    private readonly ContainerProvider $containerProvider;
    /** @var callable|null */
    private $commandExecutorMap = null;

    public function __construct(ContainerInterface|callable|null $container = null)
    {
        $this->containerProvider = new ContainerProvider($container);
    }

    public function asyncWorkerCapacity(int $capacity): self
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Async worker capacity must be positive.');
        } $this->asyncWorkerCapacity = $capacity;
        return $this;
    }

    public function timeout(float $soft, ?float $hard = null): self
    {
        $this->timeout = new TimeoutPolicy($soft, $hard);
        return $this;
    }

    public function periodic(Command $command, ?int $seconds = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): ScheduleBuilder
    {
        $builder = new ScheduleBuilder($this, $command, count($this->schedules));
        $this->schedules[] = $builder;
        return $seconds === null ? $builder : $builder->every($seconds, $from, $until);
    }

    public function every(Command $command, int $seconds, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): ScheduleBuilder
    {
        return $this->periodic($command)->every($seconds, $from, $until);
    }

    public function cron(Command $command, string $expression, ?string $timezone = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): ScheduleBuilder
    {
        return $this->periodic($command)->cron($expression, $timezone, $from, $until);
    }

    public function replace(int $ordinal, ScheduleBuilder $builder): void
    {
        $this->schedules[$ordinal] = $builder;
    }

    /** @return array<int, ScheduleBuilder> */
    public function schedules(): array
    {
        return $this->schedules;
    }

    public function timeoutPolicy(): ?TimeoutPolicy
    {
        return $this->timeout;
    }

    public function asyncWorkerCapacityValue(): int
    {
        return $this->asyncWorkerCapacity;
    }

    public function containerProvider(): ContainerProvider
    {
        return $this->containerProvider;
    }

    /** @param callable(CommandExecutorRuntime): CommandExecutorMap $callback */
    public function commandExecutorMap(callable $callback): self
    {
        $this->commandExecutorMap = Closure::fromCallable($callback);
        return $this;
    }

    public function commandExecutorMapCallback(): ?callable
    {
        return $this->commandExecutorMap;
    }
}
