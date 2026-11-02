<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function sort;
use function str_contains;
use function str_replace;
use function strlen;
use function substr;

/**
 * The package names `symfony/console`, `yiisoft/queue` and `yiisoft/mutex` in its suggestions
 * rather than its dependencies, which only holds while the code that needs each one stays where
 * an application that does not install it never reaches.
 *
 * These lists are the whole of that claim. A file added to one of them is a decision: the
 * package it names has become necessary for whatever that file is part of.
 */
final class OptionalDependenciesTest extends TestCase
{
    public function testOnlyTheConsoleCommandsAndTheirHandlerNameSymfonyConsole(): void
    {
        $this->assertSame(
            [
                'Command/ListCommand.php',
                'Command/RunCommand.php',
                'Command/WorkCommand.php',
                'Handler/ConsoleTaskHandler.php',
            ],
            self::filesNaming('Symfony'),
        );
    }

    public function testOnlyTheQueueHandlerAndTheIdentifierOfAMessageTaskNameTheQueue(): void
    {
        $this->assertSame(
            [
                'Handler/QueueTaskHandler.php',
                // Only to tell one message task from another by its type and payload; the
                // `instanceof` is false where the package is not installed.
                'RecurringTask.php',
            ],
            self::filesNaming('Yiisoft\\Queue'),
        );
    }

    public function testOnlyTheScheduleAndItsCheckpointNameTheMutex(): void
    {
        $this->assertSame(
            [
                'Generator/Checkpoint.php',
                'Schedule.php',
            ],
            self::filesNaming('Yiisoft\\Mutex'),
        );
    }

    /**
     * @return list<string> The files under src/ naming the given vendor, relative to it.
     */
    private static function filesNaming(string $vendor): array
    {
        $source = dirname(__DIR__) . '/src';
        $found = [];

        /** @var SplFileInfo $file */
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            ) as $file
        ) {
            $path = $file->getPathname();

            if ('php' !== $file->getExtension()) {
                continue;
            }

            if (str_contains((string) file_get_contents($path), $vendor)) {
                $found[] = str_replace('\\', '/', substr($path, strlen($source) + 1));
            }
        }

        sort($found);

        return $found;
    }
}
