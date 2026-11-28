<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Executor;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\Executor\CallableCommand;
use Yiisoft\Scheduler\Executor\CallableExecutor;
use Yiisoft\Scheduler\Executor\ExecutorMap;
use Yiisoft\Scheduler\Executor\PhpCommand;
use Yiisoft\Scheduler\Executor\ProcessCommand;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Tests\Support\StubContainer;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;
use Yiisoft\Scheduler\Executor\CommandInterface;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_BINARY;

/**
 * The three kinds this package ships, each doing the thing it stands for. None of them needs a
 * framework, a command registry or a message bus to do it.
 */
final class ExecutionTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $files = [];

    /**
     * @var list<string>
     */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        foreach ($this->directories as $directory) {
            foreach ((array) glob($directory . '/*') as $file) {
                @unlink((string) $file);
            }

            @rmdir($directory);
        }

        $this->files = [];
        $this->directories = [];

        parent::tearDown();
    }

    public function testACallableIsCalledWithTheContextOfTheRun(): void
    {
        $seen = null;
        $command = CallableCommand::call(static function (TaskContext $context) use (&$seen): string {
            $seen = $context->taskId;

            return 'done';
        });

        $this->assertSame('done', $this->execute($command));
        $this->assertSame('task', $seen);
    }

    public function testAServiceIsAskedOfTheContainerOfTheProcessRunningIt(): void
    {
        $service = new class {
            public int $ran = 0;

            public function send(): string
            {
                ++$this->ran;

                return 'sent';
            }
        };

        $executor = new CallableExecutor(new StubContainer(['report' => $service]));

        $this->assertSame('sent', $executor->execute(CallableCommand::of('report', 'send'), $this->context()));
        $this->assertSame(1, $service->ran);
    }

    public function testAServiceNeedsAContainerToBeAskedOf(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs the container of the process running it');

        (new CallableExecutor())->execute(CallableCommand::of('report', 'send'), $this->context());
    }

    public function testAServiceWithoutTheMethodSaysSo(): void
    {
        $executor = new CallableExecutor(new StubContainer(['report' => new DateTimeImmutable()]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no callable "send" method');

        $executor->execute(CallableCommand::of('report', 'send'), $this->context());
    }

    public function testAProgramRunsInAProcessOfItsOwn(): void
    {
        $file = $this->file();

        $this->execute(new ProcessCommand(PHP_BINARY, ['-r', 'file_put_contents($argv[1], getmypid());', $file]));

        $this->assertNotSame('', file_get_contents($file));
        $this->assertNotSame((string) getmypid(), file_get_contents($file), 'the program is not this process');
    }

    public function testAnArgumentReachesTheProgramAsItWasWritten(): void
    {
        $file = $this->file();
        $literal = '$HOME; echo other > /dev/null && rm -rf *';

        $this->execute(new ProcessCommand(PHP_BINARY, ['-r', 'file_put_contents($argv[1], $argv[2]);', $file, $literal]));

        $this->assertSame($literal, file_get_contents($file), 'nothing read the argument as shell syntax');
    }

    public function testAProgramIsGivenTheEnvironmentItWasDeclaredWith(): void
    {
        $file = $this->file();
        $command = new ProcessCommand(
            PHP_BINARY,
            ['-r', 'file_put_contents($argv[1], (string) getenv("SCHEDULER_WINDOW"));', $file],
            ['SCHEDULER_WINDOW' => 'yesterday'],
        );

        $this->execute($command);

        $this->assertSame('yesterday', file_get_contents($file));
    }

    public function testAProgramThatFailsIsAFailedTask(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exited with code 3');

        $this->execute(new ProcessCommand(PHP_BINARY, ['-r', 'exit(3);']));
    }

    public function testAProgramThatIsNotThereIsAFailedTask(): void
    {
        // Whether the failure is the process never starting or the shell-less exec of it
        // exiting 127 is the operating system's business; either way the task failed.
        $this->expectException(RuntimeException::class);

        $this->execute(new ProcessCommand(__DIR__ . '/no-such-program'));
    }

    public function testAPhpScriptRunsUnderThePhpOfTheProcessThatStartsIt(): void
    {
        $output = $this->file();
        $script = $this->file();
        file_put_contents($script, '<?php file_put_contents($argv[1], PHP_BINARY . "|" . $argv[2]);');

        $this->execute(new PhpCommand($script, [$output, 'weekly']));

        $this->assertSame(PHP_BINARY . '|weekly', file_get_contents($output));
    }

    public function testAnEmptyArgumentReachesTheProgram(): void
    {
        $file = $this->file();

        $this->execute(new ProcessCommand(PHP_BINARY, ['-r', 'file_put_contents($argv[1], count($argv) . "|" . $argv[2]);', $file, '']));

        $this->assertSame('3|', file_get_contents($file), 'the empty argument is one argument, not none');
    }

    public function testAScriptNamedLikeAnOptionIsOpenedRatherThanRead(): void
    {
        $directory = $this->directory();
        $output = $this->file();
        file_put_contents($directory . '/-report.php', '<?php file_put_contents($argv[1], $argv[2]);');

        $this->execute(new PhpCommand('-report.php', [$output, 'ran'], null, [], [], $directory));

        $this->assertSame('ran', file_get_contents($output));
    }

    public function testAnArgumentNamedLikeAPhpOptionIsTheScriptsOwn(): void
    {
        $output = $this->file();
        $script = $this->file();
        file_put_contents($script, '<?php file_put_contents($argv[1], $argv[2]);');

        $this->execute(new PhpCommand($script, [$output, '--version']));

        $this->assertSame('--version', file_get_contents($output), 'PHP answered with the script rather than with itself');
    }

    private function execute(object $command): mixed
    {
        /** @var CommandInterface $command */
        return ExecutorMap::standard()->for($command)->execute($command, $this->context());
    }

    private function directory(): string
    {
        $directory = (string) tempnam(sys_get_temp_dir(), 'scheduler-test-');
        unlink($directory);
        mkdir($directory);
        $this->directories[] = $directory;

        return $directory;
    }

    private function file(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'scheduler-test-');
        $this->files[] = $file;

        return $file;
    }

    private function context(): TaskContext
    {
        return new TaskContext('default', 'task', new CronExpressionTrigger('* * * * *'), new DateTimeImmutable(), null);
    }
}
