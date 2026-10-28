<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\ConfigurationSourceInterface;
use Yiisoft\Scheduler\Planner\FilePlannerOwner;
use Yiisoft\Scheduler\Planner\PlannerHealthSnapshot;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use const JSON_THROW_ON_ERROR;

final class PlannerHealthSnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yii-schedule-health-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
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

    public function testConfiguredStalenessThresholdUsesInclusiveHealthyBoundary(): void
    {
        $source = new MutableHealthSource('revision-one');
        $owner = (new FilePlannerOwner($this->root))->acquire($source->sourceId());
        try {
            (new PlannerHealthSnapshot($source->sourceId(), $source->revision, $owner->ownerToken(), (int) getmypid(), 100, 100))
                ->write($owner->stateDirectory());
            $written = json_decode((string) file_get_contents($owner->stateDirectory() . '/' . PlannerHealthSnapshot::FILE), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame('dev', $written['schema']);

            self::assertNull(PlannerHealthSnapshot::probe($source, $this->root, 115, 15));
            self::assertSame('planner.snapshot_stale', PlannerHealthSnapshot::probe($source, $this->root, 116, 15));
            self::assertSame('planner.snapshot_stale', PlannerHealthSnapshot::probe($source, $this->root, 116));
            self::assertNull(PlannerHealthSnapshot::probe($source, $this->root, 120, 20));
            self::assertSame('planner.snapshot_stale', PlannerHealthSnapshot::probe($source, $this->root, 121, 20));
        } finally {
            $owner->release();
        }
    }

    public function testIncompatibleSnapshotSchemaIsRejectedWithoutChangingTheFile(): void
    {
        $source = new MutableHealthSource('revision-one');
        $owner = (new FilePlannerOwner($this->root))->acquire($source->sourceId());
        try {
            $path = $owner->stateDirectory() . '/' . PlannerHealthSnapshot::FILE;
            file_put_contents($path, '{"schema":"legacy"}');
            self::assertSame('planner.snapshot_malformed', PlannerHealthSnapshot::probe($source, $this->root, 10));
            self::assertSame('{"schema":"legacy"}', file_get_contents($path));
        } finally {
            $owner->release();
        }
    }

    public function testDuplicateSnapshotMembersAreReportedMalformed(): void
    {
        $source = new MutableHealthSource('revision-one');
        $owner = (new FilePlannerOwner($this->root))->acquire($source->sourceId());
        try {
            $path = $owner->stateDirectory() . '/' . PlannerHealthSnapshot::FILE;
            $snapshot = [
                'schema' => 'dev',
                'sourceId' => $source->sourceId(),
                'sourceRevision' => $source->revision,
                'ownerToken' => $owner->ownerToken(),
                'ownerPid' => (int) getmypid(),
                'startedAt' => 10,
                'progressAt' => 10,
                'phase' => PlannerHealthSnapshot::PHASE_RUNNING,
                'error' => null,
            ];
            $valid = json_encode($snapshot, JSON_THROW_ON_ERROR);
            foreach (array_keys($snapshot) as $field) {
                $needle = '"' . $field . '":';
                $count = 0;
                $duplicate = str_replace(
                    $needle,
                    $needle . json_encode($snapshot[$field], JSON_THROW_ON_ERROR) . ',' . $needle,
                    $valid,
                    $count,
                );
                file_put_contents($path, $duplicate);
                self::assertSame('planner.snapshot_malformed', PlannerHealthSnapshot::probe($source, $this->root, 10), $field);
            }
        } finally {
            $owner->release();
        }
    }

    public function testInvalidStalenessThresholdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlannerHealthSnapshot::probe(new MutableHealthSource('revision-one'), $this->root, 100, 0);
    }

    public function testPinnedRevisionRemainsStaleAfterSourceReplacement(): void
    {
        $source = new MutableHealthSource('revision-one');
        $owner = (new FilePlannerOwner($this->root))->acquire($source->sourceId());
        try {
            $snapshot = new PlannerHealthSnapshot($source->sourceId(), 'revision-one', $owner->ownerToken(), (int) getmypid(), 10, 10);
            $snapshot->write($owner->stateDirectory());
            $source->revision = 'revision-two';

            self::assertSame('planner.source_stale', PlannerHealthSnapshot::probe($source, $this->root, 10));
        } finally {
            $owner->release();
        }
    }

    public function testMismatchedMarkerTokenIsNotHealthy(): void
    {
        $source = new MutableHealthSource('revision-one');
        $owner = (new FilePlannerOwner($this->root))->acquire($source->sourceId());
        try {
            (new PlannerHealthSnapshot($source->sourceId(), $source->revision, $owner->ownerToken(), (int) getmypid(), 10, 10))
                ->write($owner->stateDirectory());
            file_put_contents($owner->stateDirectory() . '/planner-owner', "owner-" . str_repeat('0', 32) . "\n" . getmypid() . "\n");

            self::assertSame('planner.owner_absent', PlannerHealthSnapshot::probe($source, $this->root, 10));
        } finally {
            $owner->release();
        }
    }

    public function testStaleMarkerWithoutLockIsNotHealthy(): void
    {
        $source = new MutableHealthSource('revision-one');
        $namespace = hash('sha256', $source->sourceId());
        $directory = $this->root . '/sources/' . $namespace;
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/planner-owner', "owner-" . str_repeat('0', 32) . "\n" . getmypid() . "\n");

        self::assertSame('planner.owner_absent', PlannerHealthSnapshot::probe($source, $this->root, 10));
    }

    public function testProbeDoesNotRewriteOwnerMarker(): void
    {
        $source = new MutableHealthSource('revision-one');
        $owner = (new FilePlannerOwner($this->root))->acquire($source->sourceId());
        try {
            (new PlannerHealthSnapshot($source->sourceId(), $source->revision, $owner->ownerToken(), (int) getmypid(), 10, 10))
                ->write($owner->stateDirectory());
            $marker = $owner->stateDirectory() . '/planner-owner';
            $before = file_get_contents($marker);
            self::assertNull(PlannerHealthSnapshot::probe($source, $this->root, 10));
            self::assertSame($before, file_get_contents($marker));
        } finally {
            $owner->release();
        }
    }

    public function testStoppingAndFailedPhasesAreNeverHealthy(): void
    {
        $source = new MutableHealthSource('revision-one');
        $owner = (new FilePlannerOwner($this->root))->acquire($source->sourceId());
        try {
            foreach ([PlannerHealthSnapshot::PHASE_STOPPING, PlannerHealthSnapshot::PHASE_FAILED] as $phase) {
                (new PlannerHealthSnapshot($source->sourceId(), $source->revision, $owner->ownerToken(), (int) getmypid(), 10, 10, $phase, 'planner.runtime_failed'))
                    ->write($owner->stateDirectory());
                self::assertNotNull(PlannerHealthSnapshot::probe($source, $this->root, 10));
            }
        } finally {
            $owner->release();
        }
    }
}

final class MutableHealthSource implements ConfigurationSourceInterface
{
    public function __construct(public string $revision) {}

    public function sourceId(): string
    {
        return 'health-source';
    }

    public function revision(): string
    {
        return $this->revision;
    }
}
