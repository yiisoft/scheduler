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
 * package it names has become necessary for whatever that file is part of. `config/` is read
 * as well as `src/`, because the bundled configuration is what a Yii application loads, and a
 * `class_exists()` or a `new` written there reaches an application just as surely.
 *
 * Both spellings count: the namespace a file imports and the composer name a docblock or a
 * message writes, so that a mention cannot hide from the list in the other form.
 */
final class OptionalDependenciesTest extends TestCase
{
    public function testOnlyTheConsoleCommandsTheirHandlerAndTheWiringNameSymfonyConsole(): void
    {
        $this->assertSame(
            [
                // `$application instanceof Application`, which is false, and autoloads nothing,
                // where the package is absent.
                'config/di.php',
                'src/Command/ListCommand.php',
                'src/Command/RunCommand.php',
                'src/Command/WorkCommand.php',
                'src/Handler/ConsoleTaskHandler.php',
            ],
            self::filesNaming('Symfony', 'symfony/console'),
        );
    }

    public function testOnlyTheQueueHandlerTheWiringAndTheIdentifierOfAMessageTaskNameTheQueue(): void
    {
        $this->assertSame(
            [
                'config/di.php',
                'src/Handler/QueueTaskHandler.php',
                // Only to tell one message task from another by its type and payload; the
                // `instanceof` is false where the package is not installed.
                'src/RecurringTask.php',
            ],
            self::filesNaming('Yiisoft\\Queue', 'yiisoft/queue'),
        );
    }

    public function testOnlyTheAdapterAndWhatPointsAtItNameTheMutex(): void
    {
        $this->assertSame(
            [
                // Named in a docblock, to say which interface the adapter adapts.
                'src/Lock/LockInterface.php',
                'src/Lock/MutexLock.php',
                // Named in the documentation of `lock()`, to point at the adapter.
                'src/Schedule.php',
            ],
            self::filesNaming('Yiisoft\\Mutex', 'yiisoft/mutex'),
        );
    }

    /**
     * @param string ...$spellings The ways the package can be named: its namespace, its
     * composer name, or any other form that would reach an application.
     *
     * @return list<string> The files under src/ and config/ naming it, relative to the package.
     */
    private static function filesNaming(string ...$spellings): array
    {
        $root = dirname(__DIR__);
        $found = [];

        foreach (['src', 'config'] as $directory) {
            /** @var SplFileInfo $file */
            foreach (
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
                ) as $file
            ) {
                $path = $file->getPathname();

                if ('php' !== $file->getExtension()) {
                    continue;
                }

                $contents = (string) file_get_contents($path);

                foreach ($spellings as $spelling) {
                    if (str_contains($contents, $spelling)) {
                        $found[] = str_replace('\\', '/', substr($path, strlen($root) + 1));

                        break;
                    }
                }
            }
        }

        sort($found);

        return $found;
    }
}
