<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\Store\FileExecutionLedger;
use Yiisoft\Scheduler\Integration\Async\{ExecutionFence, ExecutionIdentity, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceKey};

use const JSON_THROW_ON_ERROR;

final class CurrentSchemaRejectionTest extends TestCase
{
    public function testNumericLegacyAndIncompatibleSnapshotsAreRejectedWithoutMutation(): void
    {
        $directory = $this->directory();
        try {
            $ledger = new FileExecutionLedger($directory);
            $record = $this->record();
            $ledger->admit($record);
            $path = glob($directory . '/record-*.json')[0];
            $original = file_get_contents($path);
            self::assertIsString($original);
            $ownership = $ledger->ownershipHoldingExecutions('source-1');
            self::assertCount(1, $ownership);

            foreach ([
                ['schema' => 6],
                ['schema' => 'legacy'],
                ['taskSnapshot' => ['schema' => 'old']],
            ] as $change) {
                $value = json_decode($original, true, 64, JSON_THROW_ON_ERROR);
                $value = array_replace($value, $change);
                file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));

                try {
                    new FileExecutionLedger($directory);
                    self::fail('An incompatible ledger snapshot was accepted.');
                } catch (InvalidArgumentException) {
                    self::assertSame(json_encode($value, JSON_THROW_ON_ERROR), file_get_contents($path));
                    self::assertFileExists($path);
                }
                file_put_contents($path, $original);
            }

            self::assertCount(1, $ledger->ownershipHoldingExecutions('source-1'));
            self::assertEquals($record->occurrence, $ledger->observe($record->occurrence)?->occurrence);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testCurrentSchemaStateReadsAndWrites(): void
    {
        $directory = $this->directory();
        try {
            $ledger = new FileExecutionLedger($directory);
            $record = $this->record();
            self::assertTrue($ledger->admit($record)->newlyAdmitted);
            self::assertEquals($record, $ledger->observe($record->occurrence));
            self::assertStringContainsString('"schema":"dev"', (string) file_get_contents(glob($directory . '/record-*.json')[0]));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function record(): ExecutionLedgerRecord
    {
        return new ExecutionLedgerRecord(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution'),
            1,
            new ExecutionFence(1, 'fence'),
            ExecutionLedgerState::LAUNCH_REQUIRED,
            'manifest',
            ['schema' => 'dev', 'source' => ['source-1', 'revision-1'], 'task' => [
                'id' => 'task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0,
                'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]],
                'overlap' => 'allow', 'missed' => ['skip', null],
            ]],
            1,
            1,
            sourceIdentity: 'source-1',
            sourceRevision: 'revision-1',
        );
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/yii-schedule-current-schema-' . bin2hex(random_bytes(6));
        mkdir($directory, 0770, true);
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
