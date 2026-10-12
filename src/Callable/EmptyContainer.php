<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Callable;

use Psr\Container\ContainerInterface;

/** A PSR-11 container with no entries. */
final class EmptyContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new EmptyContainerNotFoundException($id);
    }

    public function has(string $id): bool
    {
        return false;
    }
}
