<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Planner;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\Schedule\Planner\FilePlannerOwner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PlannerOwnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yii-schedule-owner-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700, true));
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

    public function testSameSourceCannotBeOwnedTwiceAndConflictHasNoCallbackOpportunity(): void
    {
        $owner = new FilePlannerOwner($this->root);
        $first = $owner->acquire('/app/config/scheduler.php', 'revision-one');
        try {
            $owner->acquire('/app/config/scheduler.php', 'revision-two');
            self::fail('A second owner should conflict.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('already owned', $e->getMessage());
        }
        $first->release();
    }

    public function testDistinctSourcesHaveIndependentNamespaces(): void
    {
        $owner = new FilePlannerOwner($this->root);
        $first = $owner->acquire('/app/config/one.php');
        $second = $owner->acquire('/app/config/two.php');

        self::assertNotSame($first->stateDirectory(), $second->stateDirectory());
        self::assertNotSame($first->sourceId(), $second->sourceId());

        $first->release();
        $second->release();
    }

    public function testRevisionDoesNotChangeNamespaceAndLeaseReleaseIsIdempotent(): void
    {
        $owner = new FilePlannerOwner($this->root);
        $first = $owner->acquire('source', 'old');
        $directory = $first->stateDirectory();
        $token = $first->ownerToken();

        self::assertMatchesRegularExpression('/^owner-[a-f0-9]{32}$/', $token);
        self::assertSame($token . "\n" . getmypid() . "\n", file_get_contents($directory . '/planner-owner'));
        $first->release();
        self::assertFileDoesNotExist($directory . '/planner-owner');
        $first->release();

        $second = $owner->acquire('source', 'new');
        self::assertSame($directory, $second->stateDirectory());
        self::assertMatchesRegularExpression('/^owner-[a-f0-9]{32}$/', $second->ownerToken());
        $second->release();
    }

    public function testInvalidRootAndSourceAreRejected(): void
    {
        self::expectException(InvalidArgumentException::class);
        new FilePlannerOwner($this->root . "\0bad");
    }

    public function testInvalidSourceIsRejectedBeforeLocking(): void
    {
        $owner = new FilePlannerOwner($this->root);

        self::expectException(InvalidArgumentException::class);
        $owner->acquire("bad\0source");
    }
}
