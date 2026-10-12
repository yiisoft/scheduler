<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Acceptance;

use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Tests\Support\AcceptanceScheduler;
use PHPUnit\Framework\Attributes\DataProvider;

final class CommandDispatchTest extends TestCase
{
    #[DataProvider('unsupportedCommandProvider')]
    public function testUnsupportedFirstArgumentIsRejected(string $command): void
    {
        $directory = sys_get_temp_dir() . '/yii-schedule-command-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $config = $directory . '/scheduler.php';
        file_put_contents($config, "<?php\nreturn static function (): void {};\n");

        $process = AcceptanceScheduler::invoke($command, null, $config, $directory);

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame("Unsupported command: {$command}\n", $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }

    /** @return array<string, array{string}> */
    public static function unsupportedCommandProvider(): array
    {
        return [
            'status' => ['status'],
            'history' => ['history'],
            'other unknown command' => ['unknown-command'],
        ];
    }
}
