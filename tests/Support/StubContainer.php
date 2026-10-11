<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function array_key_exists;
use function sprintf;

/**
 * A container backed by a plain map, for exercising the package configuration.
 */
final class StubContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private readonly array $entries = []) {}

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new class (sprintf('Entry "%s" is not defined.', $id)) extends RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
