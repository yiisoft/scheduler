<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;
use Yiisoft\Schedule\ScalarMetadata;

use function in_array;
use function strlen;

/** Immutable, bounded terminal fact for an execution. */
final class ExecutionOutcome
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const UNRESOLVED = 'unresolved';

    public function __construct(public readonly string $status, public readonly ?string $reason = null, public readonly array $metadata = [])
    {
        if (!in_array($status, [self::SUCCEEDED, self::FAILED, self::CANCELLED, self::UNRESOLVED], true)) {
            throw new InvalidArgumentException('Invalid execution outcome.');
        }
        ScalarMetadata::assertAggregateSupported($reason, $metadata);
        if ($reason !== null && ($reason === '' || strlen($reason) > 4096)) {
            throw new InvalidArgumentException('Execution outcome reason is empty or too long.');
        }
    }
}
