<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Planner;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\Schedule\Planner\FilePlannerOwner;
use Yiisoft\Schedule\Planner\PlannerStateFormat;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PlannerStateFormatTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yii-schedule-format-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/sources', 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testFreshNamespaceIsInitializedAndCompatibleMarkerIsPreserved(): void
    {
        $directory = $this->root . '/sources/state';
        mkdir($directory, 0700, true);
        PlannerStateFormat::initializeOrValidate($directory);
        $marker = $directory . '/' . PlannerStateFormat::FILE;
        $before = file_get_contents($marker);

        PlannerStateFormat::initializeOrValidate($directory);

        self::assertSame('{"version":1,"runtime":3}', $before);
        self::assertSame($before, file_get_contents($marker));
    }

    /** @dataProvider invalidMarkerProvider */
    public function testInvalidMarkerRejectsBeforeOwnerMarker(string $contents): void
    {
        $source = 'format-source';
        $directory = $this->root . '/sources/' . hash('sha256', $source);
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/' . PlannerStateFormat::FILE, $contents);

        try {
            (new FilePlannerOwner($this->root))->acquire($source);
            self::fail('Invalid state format should be rejected.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($directory . '/planner-owner');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMarkerProvider(): iterable
    {
        yield 'malformed' => ['not-json'];
        yield 'unknown version' => ['{"version":99,"runtime":3}'];
        yield 'wrong type' => ['{"version":"1","runtime":3}'];
        yield 'extra key' => ['{"version":1,"runtime":3,"extra":true}'];
        yield 'duplicate version' => ['{"version":1,"runtime":3,"version":1}'];
        yield 'duplicate runtime' => ['{"version":1,"runtime":3,"runtime":3}'];
        yield 'oversized' => ['{"version":1,"runtime":3}' . str_repeat(' ', 4096)];
    }
}
