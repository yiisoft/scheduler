<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Process;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use Yiisoft\Scheduler\Integration\Async\Process\LinuxProcessObserver;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLookupResult;

use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_OS_FAMILY;

final class LinuxProcessObserverTest extends TestCase
{
    private string $procPath;

    protected function setUp(): void
    {
        $this->procPath = sys_get_temp_dir() . '/yii-scheduler-proc-' . uniqid('', true);
        mkdir($this->procPath . '/sys/kernel/random', 0777, true);
        file_put_contents($this->procPath . '/sys/kernel/random/boot_id', "test-boot\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->procPath . '/10/stat', $this->procPath . '/10/environ', $this->procPath . '/10', $this->procPath . '/20/stat', $this->procPath . '/20/environ', $this->procPath . '/20', $this->procPath . '/sys/kernel/random/boot_id', $this->procPath . '/sys/kernel/random', $this->procPath . '/sys/kernel', $this->procPath . '/sys', $this->procPath] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRealProcStatUsesLinuxFieldPositionsAfterComm(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux process table is required.');
        }
        $stat = file_get_contents('/proc/' . getmypid() . '/stat');
        self::assertIsString($stat);
        $method = new ReflectionMethod(LinuxProcessObserver::class, 'statFields');
        $fields = $method->invoke(new LinuxProcessObserver(), $stat);
        self::assertIsArray($fields);

        self::assertSame(getmypid(), (int) $fields[1]);
        self::assertGreaterThan(0, (int) $fields[5]);
        self::assertGreaterThan(0, (int) $fields[6]);
        self::assertMatchesRegularExpression('/^[0-9]+$/', (string) $fields[22]);
    }

    public function testPersistentUnreadableEntryDoesNotHideAnExactMatch(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'nonce');
        mkdir($this->procPath . '/20');
        $this->writeProcess(10, $descriptor->marker());

        $result = (new LinuxProcessObserver($this->procPath))->lookup($descriptor);

        self::assertSame(ProcessLookupResult::MATCH, $result->status);
        self::assertSame(10, $result->identity?->pid);
    }

    public function testPersistentUnreadableEntryIsNeverReportedAsNoMatch(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'nonce');
        mkdir($this->procPath . '/20');

        $result = (new LinuxProcessObserver($this->procPath))->lookup($descriptor);

        self::assertSame(ProcessLookupResult::UNREADABLE, $result->status);
    }

    public function testWrongScopeDescriptorIsRejectedBeforeMarkerLookup(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'nonce', 'other-scope');
        $result = (new LinuxProcessObserver())->lookup($descriptor);

        self::assertSame(ProcessLookupResult::UNSUPPORTED, $result->status);
        self::assertStringStartsWith('other-scope:', $descriptor->marker());
        self::assertNotSame($descriptor->marker(), (new ExecutionLaunchDescriptor('execution', 1, 'nonce'))->marker());
    }

    private function writeProcess(int $pid, string $marker): void
    {
        mkdir($this->procPath . '/' . $pid);
        $stat = [
            (string) $pid,
            '(worker)',
            'S',
            '1',
            (string) $pid,
            (string) $pid,
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '12345',
        ];
        file_put_contents($this->procPath . '/' . $pid . '/stat', implode(' ', $stat) . "\n");
        file_put_contents($this->procPath . '/' . $pid . '/environ', 'YII_SCHEDULER_WORKER_MARKER=' . $marker . "\0");
    }
}
