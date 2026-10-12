<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

use InvalidArgumentException;

use function strlen;

/** Immutable source identity and revision fence. */
final class ConfigurationSourcePin
{
    public function __construct(public readonly string $sourceId, public readonly string $revision)
    {
        if ($sourceId === '' || strlen($sourceId) > 4096 || str_contains($sourceId, "\0")) {
            throw new InvalidArgumentException('Configuration source identity is empty or too long.');
        }
        if ($revision === '' || strlen($revision) > ConfigurationVersionGuard::MAX_REVISION_LENGTH || str_contains($revision, "\0")) {
            throw new InvalidArgumentException('Configuration source revision is empty or too long.');
        }
    }
}
