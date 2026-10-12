<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use RuntimeException;

/** A bounded indication that a worker launch did not reach a confirmed return boundary. */
final class ExecutionWorkerLaunchException extends RuntimeException
{
    public const PHASE_BUILD = 'build';
    public const PHASE_START = 'start';
    public const PHASE_OBSERVER = 'observer';

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_AMBIGUOUS = 'ambiguous';

    private function __construct(public readonly string $phase, public readonly string $status, string $message)
    {
        parent::__construct($message);
    }

    public static function builderFailure(): self
    {
        return new self(self::PHASE_BUILD, self::STATUS_NOT_STARTED, 'Unable to build the worker process launch request.');
    }

    public static function startFailure(): self
    {
        return new self(self::PHASE_START, self::STATUS_AMBIGUOUS, 'Worker process launch is ambiguous.');
    }

    public static function observerFailure(): self
    {
        return new self(self::PHASE_OBSERVER, self::STATUS_AMBIGUOUS, 'Worker process handle registration is ambiguous.');
    }
}
