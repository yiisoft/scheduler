<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

use function sprintf;
use function strlen;

/**
 * A minimal in-memory PSR-16 implementation for tests.
 */
final class InMemoryCache implements CacheInterface
{
    private array $values = [];
    private bool $writesFail = false;
    private int $writes = 0;
    private ?int $maximumKeyLength = null;

    /**
     * Makes every subsequent write report a failure, as a full or unavailable cache would.
     */
    public function failWrites(): void
    {
        $this->writesFail = true;
    }

    /**
     * Rejects keys longer than the given number of characters, as a backend with a key length
     * limit does. PSR-16 guarantees support for 64 of them.
     */
    public function limitKeyLength(int $maximum): void
    {
        $this->maximumKeyLength = $maximum;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->checkKey($key);

        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->checkKey($key);

        if ($this->writesFail) {
            return false;
        }

        $this->values[$key] = $value;
        ++$this->writes;

        return true;
    }

    /**
     * How many values were written, redundant writes of an unchanged value included.
     */
    public function writes(): int
    {
        return $this->writes;
    }

    public function delete(string $key): bool
    {
        $this->checkKey($key);

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
        $this->checkKey($key);

        return isset($this->values[$key]);
    }

    private function checkKey(string $key): void
    {
        if (null !== $this->maximumKeyLength && strlen($key) > $this->maximumKeyLength) {
            throw new InvalidCacheKeyException(sprintf(
                'The key "%s" is %d characters long, and this cache stores keys of up to %d.',
                $key,
                strlen($key),
                $this->maximumKeyLength,
            ));
        }
    }
}
