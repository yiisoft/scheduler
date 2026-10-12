<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Binary;

use Yiisoft\Scheduler\Integration\Async\ExecutionOutcome;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerInvocationResult;

/** Maps a worker result to a bounded process exit code. */
final class ExecutionWorkerExitCodeMapper
{
    public const SUCCESS = 0;
    public const EXECUTION_FAILURE = 1;
    public const CATCH_OR_ARGUMENT_FAILURE = 2;
    public const PROTOCOL_FAILURE = self::CATCH_OR_ARGUMENT_FAILURE;
    public const UNRESOLVED_OR_AMBIGUOUS = 3;

    public function map(ExecutionWorkerInvocationResult $result): int
    {
        if ($result->status === ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL) {
            return match ($result->outcome?->status) {
                ExecutionOutcome::SUCCEEDED => self::SUCCESS,
                ExecutionOutcome::FAILED, ExecutionOutcome::CANCELLED => self::EXECUTION_FAILURE,
                default => self::CATCH_OR_ARGUMENT_FAILURE,
            };
        }

        return match ($result->status) {
            ExecutionWorkerInvocationResult::COMMITTED_SUCCEEDED => self::SUCCESS,
            ExecutionWorkerInvocationResult::COMMITTED_FAILED,
            ExecutionWorkerInvocationResult::COMMITTED_CANCELLED,
            ExecutionWorkerInvocationResult::POST_CLAIM_FAILED => self::EXECUTION_FAILURE,
            ExecutionWorkerInvocationResult::INVALID_CAPABILITY,
            ExecutionWorkerInvocationResult::LOCATOR_UNAVAILABLE,
            ExecutionWorkerInvocationResult::CLAIM_REJECTED,
            ExecutionWorkerInvocationResult::TERMINAL_MISSING,
            ExecutionWorkerInvocationResult::TERMINAL_STALE_FENCED,
            ExecutionWorkerInvocationResult::TERMINAL_REJECTED => self::CATCH_OR_ARGUMENT_FAILURE,
            ExecutionWorkerInvocationResult::UNRESOLVED_OWNERSHIP,
            ExecutionWorkerInvocationResult::TERMINAL_AMBIGUOUS => self::UNRESOLVED_OR_AMBIGUOUS,
        };
    }
}
