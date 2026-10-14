<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\OccurrenceSkipReason;
use Yiisoft\Scheduler\Integration\Async\Store\FileExecutionOccurrenceCheckpoint;
use PHPUnit\Framework\Attributes\DataProvider;

final class FileExecutionOccurrenceCheckpointTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/schedule-checkpoint-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root);
    }

    public function testInitializeOnlyCreatesAbsentStateAndStateSurvivesRestart(): void
    {
        $checkpoint = new FileExecutionOccurrenceCheckpoint($this->root);
        self::assertNull($checkpoint->read('task'));

        $checkpoint->initialize('task', 't0000000001');
        $restarted = new FileExecutionOccurrenceCheckpoint($this->root);
        self::assertSame('t0000000001', $restarted->read('task'));
        $this->expectException(InvalidArgumentException::class);
        $checkpoint->initialize('task', 't0000000002');
    }

    public function testAdvanceIsExactCasAndRejectsStaleDuplicateAndNoOpWithoutChangingState(): void
    {
        $checkpoint = new FileExecutionOccurrenceCheckpoint($this->root);
        $checkpoint->initialize('task', 'cursor-1');
        $checkpoint->advance('task', 'cursor-1', 'cursor-2');
        self::assertSame('cursor-2', $checkpoint->read('task'));

        foreach ([['cursor-1', 'cursor-3'], ['cursor-2', 'cursor-2']] as [$expected, $next]) {
            try {
                $checkpoint->advance('task', $expected, $next);
                self::fail('Expected an exact CAS rejection.');
            } catch (InvalidArgumentException) {
                self::assertSame('cursor-2', $checkpoint->read('task'));
            }
        }
    }

    public function testAdvanceRejectsBackwardCursorAndPreservesState(): void
    {
        $checkpoint = new FileExecutionOccurrenceCheckpoint($this->root);
        $checkpoint->initialize('task', 'cursor-2');

        $this->expectException(InvalidArgumentException::class);
        try {
            $checkpoint->advance('task', 'cursor-2', 'cursor-1');
        } finally {
            self::assertSame('cursor-2', $checkpoint->read('task'));
        }
    }

    public function testSkipIsDurableExactCasAndRestartSafe(): void
    {
        $checkpoint = new FileExecutionOccurrenceCheckpoint($this->root);
        $checkpoint->initialize('task', 'cursor-1');
        $checkpoint->skip('task', 'cursor-1', 'cursor-2', OccurrenceSkipReason::OVERLAP);
        self::assertSame('cursor-2', $checkpoint->read('task'));
        try {
            $checkpoint->skip('task', 'cursor-1', 'cursor-3', OccurrenceSkipReason::OVERLAP);
            self::fail('Expected a stale skip to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame('cursor-2', $checkpoint->read('task'));
        }
        self::assertSame('cursor-2', (new FileExecutionOccurrenceCheckpoint($this->root))->read('task'));
    }

    public function testVersionOneCheckpointJsonIsRejected(): void
    {
        file_put_contents($this->root . '/checkpoints.json', '{"version":1,"checkpoints":{"schedule":{"task":"cursor-1"}}}');
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionOccurrenceCheckpoint($this->root))->read('task');
    }

    public function testMalformedAndUnexpectedStateIsRejected(): void
    {
        file_put_contents($this->root . '/checkpoints.json', '{"version":1,"checkpoints":{"schedule":{"task":42}}}');
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionOccurrenceCheckpoint($this->root))->read('task');
    }

    public function testOversizedStateAndInvalidInputsAreRejected(): void
    {
        file_put_contents($this->root . '/checkpoints.json', str_repeat('x', 1_048_577));
        $checkpoint = new FileExecutionOccurrenceCheckpoint($this->root);
        $this->expectException(InvalidArgumentException::class);
        $checkpoint->read('task');
    }

    #[DataProvider('invalidInputProvider')]
    public function testInvalidKeysAndCursorsAreRejected(string $task, string $cursor): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionOccurrenceCheckpoint($this->root))->initialize($task, $cursor);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'key traversal' => ['../schedule', 'cursor'],
            'empty task' => ['', 'cursor'],
            'empty cursor' => ['task', ''],
            'cursor control' => ['task', "cursor\n"],
            'cursor oversized' => ['task', str_repeat('x', 2049)],
        ];
    }
}
