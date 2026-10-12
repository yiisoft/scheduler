<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Acceptance;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Yiisoft\Scheduler\Tests\Support\AcceptanceScheduler;

use const JSON_THROW_ON_ERROR;

final class HealthcheckTest extends TestCase
{
    public function testMissingSnapshotIsRejected(): void
    {
        [$planner, $config, $directory] = $this->planner();
        try {
            AcceptanceScheduler::suspendPlanner($planner);
            try {
                $state = AcceptanceScheduler::sourceStateDirectory($config);
                self::assertTrue(unlink($state . '/planner-health.json'));
                $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
                $this->assertHealth($health, 'planner.snapshot_missing');
            } finally {
                AcceptanceScheduler::resumePlanner($planner);
            }
        } finally {
            $planner->stop(1);
        }
    }

    public function testMalformedSnapshotIsRejected(): void
    {
        [$planner, $config, $directory] = $this->planner();
        try {
            AcceptanceScheduler::suspendPlanner($planner);
            try {
                $state = AcceptanceScheduler::sourceStateDirectory($config);
                self::assertNotFalse(file_put_contents($state . '/planner-health.json', '{malformed'));
                $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
                $this->assertHealth($health, 'planner.snapshot_malformed');
            } finally {
                AcceptanceScheduler::resumePlanner($planner);
            }
        } finally {
            $planner->stop(1);
        }
    }

    public function testStartingPhaseIsNotHealthy(): void
    {
        [$planner, $config, $directory] = $this->planner();
        try {
            AcceptanceScheduler::suspendPlanner($planner);
            try {
                $snapshotPath = AcceptanceScheduler::sourceStateDirectory($config) . '/planner-health.json';
                $snapshot = json_decode((string) file_get_contents($snapshotPath), true, 16, JSON_THROW_ON_ERROR);
                $snapshot['phase'] = 'starting';
                self::assertNotFalse(file_put_contents($snapshotPath, json_encode($snapshot, JSON_THROW_ON_ERROR)));

                $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
                $this->assertHealth($health, 'planner.not_running');
            } finally {
                AcceptanceScheduler::resumePlanner($planner);
            }
        } finally {
            $planner->stop(1);
        }
    }

    public function testHardKilledOwnerWithOldSnapshotIsAbsent(): void
    {
        [$planner, $config, $directory] = $this->planner();
        $state = AcceptanceScheduler::sourceStateDirectory($config);
        $snapshotPath = $state . '/planner-health.json';
        AcceptanceScheduler::suspendPlanner($planner);
        try {
            $snapshot = json_decode((string) file_get_contents($snapshotPath), true, 16, JSON_THROW_ON_ERROR);
            $snapshot['progressAt'] = 1;
            self::assertNotFalse(file_put_contents($snapshotPath, json_encode($snapshot, JSON_THROW_ON_ERROR)));
        } finally {
            AcceptanceScheduler::resumePlanner($planner);
        }
        $planner->stop(0, 9);
        self::assertFalse($planner->isRunning());

        $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
        $this->assertHealth($health, 'planner.snapshot_stale');
    }

    public function testWorkerOnlyProcessDoesNotOwnHealth(): void
    {
        [$planner, $config, $directory] = $this->planner();
        $admission = AcceptanceScheduler::admittedRecord($directory, 'external-task', $config);
        AcceptanceScheduler::releaseDispatch($directory, $admission, $config);
        $workerPid = trim((string) file_get_contents($directory . '/worker.pid'));
        self::assertNotSame('', $workerPid);
        $planner->stop(0, 9);
        self::assertFalse($planner->isRunning());

        // Exercise the worker-only CLI path while the durable worker marker is
        // still present; it must not be mistaken for planner ownership.
        $worker = AcceptanceScheduler::invoke('run', 'missing-task', $config, $directory);
        self::assertNotSame(0, $worker->getExitCode());
        self::assertSame("worker.claim_rejected\n", $worker->getErrorOutput());
        self::assertSame('', $worker->getOutput());
        $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
        $this->assertHealth($health, 'planner.owner_absent');
    }

    public function testPidAndTokenMismatchIsRejected(): void
    {
        [$planner, $config, $directory] = $this->planner();
        try {
            $state = AcceptanceScheduler::sourceStateDirectory($config);
            self::assertNotFalse(file_put_contents($state . '/planner-owner', "owner-" . str_repeat('0', 32) . "\n" . $planner->getPid() . "\n"));
            $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
            $this->assertHealth($health, 'planner.owner_absent');
        } finally {
            $planner->stop(1);
        }
    }

    public function testHealthSourcesAreIsolated(): void
    {
        $root = sys_get_temp_dir() . '/yii-schedule-health-acceptance-' . bin2hex(random_bytes(8));
        mkdir($root . '/one', 0700, true);
        mkdir($root . '/two', 0700, true);
        $one = $root . '/one/config.php';
        $two = $root . '/two/config.php';
        copy(__DIR__ . '/../Support/AcceptanceConfiguration.php', $one);
        copy(__DIR__ . '/../Support/AcceptanceConfiguration.php', $two);
        $first = AcceptanceScheduler::startPlanner($one, $root . '/one')['process'];
        $second = AcceptanceScheduler::startPlanner($two, $root . '/two')['process'];
        try {
            AcceptanceScheduler::awaitPlannerReady($root . '/one');
            AcceptanceScheduler::awaitPlannerReady($root . '/two');
            AcceptanceScheduler::suspendPlanner($first);
            try {
                $state = AcceptanceScheduler::sourceStateDirectory($one);
                self::assertNotFalse(file_put_contents($state . '/planner-health.json', '{}'));

                $isolated = AcceptanceScheduler::invoke('healthcheck', null, $two, $root . '/two');
                self::assertSame(0, $isolated->getExitCode(), $isolated->getErrorOutput());
                self::assertSame('', $isolated->getOutput());
                $broken = AcceptanceScheduler::invoke('healthcheck', null, $one, $root . '/one');
                $this->assertHealth($broken, 'planner.snapshot_malformed');
            } finally {
                AcceptanceScheduler::resumePlanner($first);
            }
        } finally {
            $first->stop(1);
            $second->stop(1);
        }
    }

    /** @return array{0: Process, 1:string, 2:string} */
    private function planner(): array
    {
        $directory = sys_get_temp_dir() . '/yii-schedule-health-acceptance-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $config = $directory . '/scheduler.php';
        copy(__DIR__ . '/../Support/AcceptanceConfiguration.php', $config);
        $planner = AcceptanceScheduler::startPlanner($config, $directory)['process'];
        self::assertTrue($planner->isRunning());
        AcceptanceScheduler::awaitPlannerReady($directory);
        return [$planner, $config, $directory];
    }

    private function assertHealth(Process $health, string $reason): void
    {
        self::assertNotSame(0, $health->getExitCode());
        self::assertSame($reason . "\n", $health->getErrorOutput());
        self::assertSame('', $health->getOutput());
    }
}
