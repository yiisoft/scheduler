<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_file;
use function md5;
use function rename;
use function serialize;
use function tempnam;
use function unlink;
use function unserialize;

/**
 * A minimal PSR-16 implementation backed by one file per key, so two processes can share a
 * checkpoint the way a real cache lets them. Values are written whole and renamed into place,
 * which is atomic on a POSIX filesystem: a reader sees either the old value or the new one.
 *
 * Not a cache anybody should run: no expiry, no cleanup, no locking beyond the rename.
 */
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $directory) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return $default;
        }

        $contents = file_get_contents($path);

        if (false === $contents || '' === $contents) {
            return $default;
        }

        /** @var mixed $value */
        $value = unserialize($contents);

        return false === $value ? $default : $value;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $temporary = tempnam($this->directory, 'cache');

        if (false === $temporary) {
            return false;
        }

        if (false === file_put_contents($temporary, serialize($value))) {
            return false;
        }

        return rename($temporary, $this->path($key));
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        return !is_file($path) || unlink($path);
    }

    public function clear(): bool
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }

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
            $this->set((string) $key, $value, $ttl);
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
        return is_file($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . md5($key) . '.cache';
    }
}
