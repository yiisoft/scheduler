<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;
use function fclose;
use function feof;
use function fread;
use function is_resource;
use function proc_close;
use function proc_open;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

/**
 * What the commands write to a terminal, which is the one place the status is meant for and
 * the one an ordinary test cannot give them: the tester and every redirected stream are not
 * terminals, so a command ignoring `--no-program-status` would pass unnoticed everywhere
 * else. Each command runs through a pseudo-terminal here instead.
 */
final class ProgramStatusOnATerminalTest extends TestCase
{
    #[DataProvider('commandProvider')]
    public function testStatusIsReportedToATerminal(string $command, string $expected): void
    {
        $output = $this->runOnATerminal($command);

        $this->assertStringContainsString("\033]7501;state=working:app=yii-scheduler", $output);
        $this->assertStringContainsString("\033]7501;state=done:app=yii-scheduler", $output);
        $this->assertStringContainsString($expected, $output);
    }

    #[DataProvider('commandProvider')]
    public function testNoProgramStatusIsHonouredOnATerminal(string $command, string $expected): void
    {
        $output = $this->runOnATerminal($command, '--no-program-status');

        $this->assertStringNotContainsString("\033]7501", $output);
        $this->assertStringContainsString($expected, $output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function commandProvider(): array
    {
        return [
            'schedule:run' => ['run', 'No task is due.'],
            'schedule:work' => ['work', 'Scheduler stopped.'],
        ];
    }

    private function runOnATerminal(string $command, string ...$options): string
    {
        if ('/' !== DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('A pseudo-terminal is opened on POSIX systems only.');
        }

        $root = dirname(__DIR__, 2);
        $process = @proc_open(
            array_values([
                PHP_BINARY,
                $root . '/tests/Support/run-command.php',
                $root . '/vendor/autoload.php',
                $command,
                ...$options,
            ]),
            [['pty'], ['pty'], ['pty']],
            $pipes,
            $root,
            ['TERM' => 'xterm'],
        );

        if (!is_resource($process)) {
            $this->markTestSkipped('This build of PHP cannot open a pseudo-terminal.');
        }

        fclose($pipes[0]);

        $output = '';

        // The terminal answers the end of the child with an I/O error rather than an end of
        // file, so a read that failed is the end of what there is to read.
        while (!feof($pipes[1])) {
            $chunk = @fread($pipes[1], 8192);

            if (false === $chunk || '' === $chunk) {
                break;
            }

            $output .= $chunk;
        }

        fclose($pipes[1]);
        proc_close($process);

        return $output;
    }
}
