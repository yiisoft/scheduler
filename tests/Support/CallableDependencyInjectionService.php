<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function file_put_contents;
use function getmypid;
use function in_array;

use const FILE_APPEND;
use const LOCK_EX;

/** Deterministic lazy container used by the callable-injection acceptance fixture. */
final class CallableDependencyInjectionService implements ContainerInterface
{
    private int $resolutions = 0;

    public function __construct(private readonly string $directory) {}

    public function get(string $id): mixed
    {
        if ($id === self::class) {
            ++$this->resolutions;
            file_put_contents($this->directory . '/container-resolutions', $id . ':' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
            return $this;
        }
        if (!in_array($id, ['acceptance.async', 'acceptance.sync'], true)) {
            throw new class ('Unknown callable service.') extends RuntimeException implements NotFoundExceptionInterface {};
        }

        ++$this->resolutions;
        file_put_contents($this->directory . '/container-resolutions', $id . "\n", FILE_APPEND | LOCK_EX);
        return static function (self $service, string $effect, int $value): void {
            $service->record($effect, $value);
        };
    }

    public function has(string $id): bool
    {
        return $id === self::class || in_array($id, ['acceptance.async', 'acceptance.sync'], true);
    }

    /** Record the injected service, named scalar arguments, and process identity. */
    public function record(string $effect, int $value): void
    {
        file_put_contents($this->directory . '/' . $effect, $value . ':' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
    }

    public function resolutions(): int
    {
        return $this->resolutions;
    }
}
