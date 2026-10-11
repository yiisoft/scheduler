<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * A minimal in-memory PSR-16 implementation for tests.
 */
final class InMemoryCache implements CacheInterface
{
    private array $values = [];
    private bool $writesFail = false;

    /**
     * Makes every subsequent write report a failure, as a full or unavailable cache would.
     */
    public function failWrites(): void
    {
        $this->writesFail = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        if ($this->writesFail) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }
}
