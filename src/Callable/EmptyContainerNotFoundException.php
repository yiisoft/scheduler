<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Callable;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function sprintf;

final class EmptyContainerNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Container entry "%s" was not found.', $id));
    }
}
