<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Binary;

use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Binary\ExecutionWorkerExitCodeMapper;
use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerInvocationResult;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExecutionWorkerExitCodeMapperTest extends TestCase
{
    #[DataProvider('statusExitCodes')]
    public function testEveryWorkerStatusHasAnExplicitExitCode(string $status, int $expected, ?ExecutionOutcome $outcome = null): void
    {
        $code = (new ExecutionWorkerExitCodeMapper())->map(new ExecutionWorkerInvocationResult($status, outcome: $outcome));

        self::assertSame($expected, $code);
        self::assertGreaterThanOrEqual(0, $code);
        self::assertLessThanOrEqual(255, $code);
    }

    public static function statusExitCodes(): iterable
    {
        yield 'committed success' => [ExecutionWorkerInvocationResult::COMMITTED_SUCCEEDED, ExecutionWorkerExitCodeMapper::SUCCESS];
        yield 'committed failed' => [ExecutionWorkerInvocationResult::COMMITTED_FAILED, ExecutionWorkerExitCodeMapper::EXECUTION_FAILURE];
        yield 'committed cancelled' => [ExecutionWorkerInvocationResult::COMMITTED_CANCELLED, ExecutionWorkerExitCodeMapper::EXECUTION_FAILURE];
        yield 'post claim execution failure' => [ExecutionWorkerInvocationResult::POST_CLAIM_FAILED, ExecutionWorkerExitCodeMapper::EXECUTION_FAILURE];
        yield 'invalid capability' => [ExecutionWorkerInvocationResult::INVALID_CAPABILITY, ExecutionWorkerExitCodeMapper::PROTOCOL_FAILURE];
        yield 'locator unavailable' => [ExecutionWorkerInvocationResult::LOCATOR_UNAVAILABLE, ExecutionWorkerExitCodeMapper::PROTOCOL_FAILURE];
        yield 'claim rejected' => [ExecutionWorkerInvocationResult::CLAIM_REJECTED, ExecutionWorkerExitCodeMapper::PROTOCOL_FAILURE];
        yield 'terminal missing' => [ExecutionWorkerInvocationResult::TERMINAL_MISSING, ExecutionWorkerExitCodeMapper::PROTOCOL_FAILURE];
        yield 'terminal stale fenced' => [ExecutionWorkerInvocationResult::TERMINAL_STALE_FENCED, ExecutionWorkerExitCodeMapper::PROTOCOL_FAILURE];
        yield 'terminal already terminal unknown' => [ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL, ExecutionWorkerExitCodeMapper::CATCH_OR_ARGUMENT_FAILURE];
        yield 'terminal rejected' => [ExecutionWorkerInvocationResult::TERMINAL_REJECTED, ExecutionWorkerExitCodeMapper::CATCH_OR_ARGUMENT_FAILURE];
        yield 'unresolved ownership' => [ExecutionWorkerInvocationResult::UNRESOLVED_OWNERSHIP, ExecutionWorkerExitCodeMapper::UNRESOLVED_OR_AMBIGUOUS];
        yield 'terminal ambiguous' => [ExecutionWorkerInvocationResult::TERMINAL_AMBIGUOUS, ExecutionWorkerExitCodeMapper::UNRESOLVED_OR_AMBIGUOUS];
        yield 'duplicate durable success' => [ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL, ExecutionWorkerExitCodeMapper::SUCCESS, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED)];
        yield 'duplicate durable failure' => [ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL, ExecutionWorkerExitCodeMapper::EXECUTION_FAILURE, new ExecutionOutcome(ExecutionOutcome::FAILED)];
        yield 'duplicate durable cancellation' => [ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL, ExecutionWorkerExitCodeMapper::EXECUTION_FAILURE, new ExecutionOutcome(ExecutionOutcome::CANCELLED)];
    }
}
