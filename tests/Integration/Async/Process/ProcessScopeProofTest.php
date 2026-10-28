<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Process;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessHandleInterface;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessIdentity;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLookupResult;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessResult;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessScopeProof;

final class ProcessScopeProofTest extends TestCase
{
    public function testProofStatusesAndProvenanceAreStable(): void
    {
        $descriptor = $this->descriptor();
        $identity = $this->identity($descriptor);

        self::assertSame(ProcessScopeProof::LIVE, ProcessScopeProof::liveFromLookup($descriptor, $identity)->status);
        self::assertSame(ProcessScopeProof::LOOKUP, ProcessScopeProof::liveFromLookup($descriptor, $identity)->provenance);
        self::assertSame(ProcessScopeProof::RETAINED_POLL, ProcessScopeProof::closedTerminalFromRetainedPoll($descriptor, $identity)->provenance);
        self::assertTrue(ProcessScopeProof::closedEsrchFromLookup($descriptor, $identity)->isClosed());
        self::assertSame($identity, ProcessScopeProof::closedEsrchFromLookup($descriptor, $identity)->identity);
        self::assertSame(ProcessScopeProof::UNCERTAIN, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_EPERM)->status);
    }

    public function testLookupEnvelopeEnforcesDescriptorAndIdentityBinding(): void
    {
        $descriptor = $this->descriptor();
        $identity = $this->identity($descriptor);
        $proof = ProcessScopeProof::liveFromLookup($descriptor, $identity);
        $handle = new class implements ProcessHandleInterface {
            public function scopeProof(): ProcessScopeProof
            {
                return ProcessScopeProof::closedTerminalFromRetainedPoll(new ExecutionLaunchDescriptor('test', 1, 'test'), new ProcessIdentity(10, 10, 10, '1', 'boot', new ExecutionLaunchDescriptor('test', 1, 'test')));
            }

            public function isRunning(): bool
            {
                return true;
            }

            public function isQuiescent(): bool
            {
                return false;
            }

            public function poll(): ?ProcessResult
            {
                return null;
            }

            public function terminateSoft(): void {}

            public function terminateHard(): void {}
        };

        $result = new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, $handle, $proof);
        self::assertTrue($result->matched());

        $other = $this->descriptor('other');
        $this->expectException(InvalidArgumentException::class);
        new ProcessLookupResult(ProcessLookupResult::MATCH, $this->identity($other), $handle, $proof);
    }

    public function testClosedAndUncertainEnvelopesAreStrictAndNoMatchIsNotClosure(): void
    {
        $descriptor = $this->descriptor();
        $identity = $this->identity($descriptor);
        $closed = new ProcessLookupResult(
            ProcessLookupResult::CLOSED,
            proof: ProcessScopeProof::closedEsrchFromLookup($descriptor, $identity),
        );
        self::assertSame(ProcessLookupResult::CLOSED, $closed->status);
        self::assertSame($identity, $closed->proof?->identity);
        self::assertFalse((new ProcessLookupResult(ProcessLookupResult::NO_MATCH))->proof?->isClosed() ?? false);

        $uncertain = ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_RACE, $identity);
        $uncertainResult = new ProcessLookupResult(ProcessLookupResult::UNREADABLE, proof: $uncertain);
        self::assertNull($uncertainResult->identity);
        self::assertSame($identity, $uncertainResult->proof?->identity);

        $this->expectException(InvalidArgumentException::class);
        new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: $uncertain);
    }

    public function testClosedProofRejectsMissingIdentity(): void
    {
        $this->expectException(TypeError::class);
        ProcessScopeProof::closedEsrchFromLookup($this->descriptor(), null);
    }

    public function testInvalidReasonsAndIdentityBindingAreRejected(): void
    {
        $descriptor = $this->descriptor();
        $this->expectException(InvalidArgumentException::class);
        ProcessScopeProof::uncertainFromLookup($descriptor, 'free-form reason');
    }

    public function testUncertainProofIdentityMustMatchDescriptor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProcessScopeProof::uncertainFromLookup(
            $this->descriptor(),
            ProcessScopeProof::REASON_RACE,
            $this->identity($this->descriptor('other')),
        );
    }

    private function descriptor(string $execution = 'execution'): ExecutionLaunchDescriptor
    {
        return new ExecutionLaunchDescriptor($execution, 1, 'nonce-' . $execution);
    }

    private function identity(ExecutionLaunchDescriptor $descriptor): ProcessIdentity
    {
        return new ProcessIdentity(10, 10, 10, '100', 'boot', $descriptor);
    }
}
