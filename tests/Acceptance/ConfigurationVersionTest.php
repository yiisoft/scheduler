<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Acceptance;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Binary\ExecutionWorkerExitCodeMapper;
use Yiisoft\Scheduler\Tests\Support\AcceptanceScheduler;

use const LOCK_EX;

final class ConfigurationVersionTest extends TestCase
{
    public function testPlannerPinsSourceVersionAndFailsClosedAfterReplacement(): void
    {
        $directory = sys_get_temp_dir() . '/yii-schedule-version-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $config = $directory . '/config.php';
        copy(__DIR__ . '/../Support/AcceptanceConfiguration.php', $config);

        $planner = AcceptanceScheduler::startPlanner($config, $directory)['process'];
        try {
            self::assertTrue($planner->isRunning());
            AcceptanceScheduler::awaitPlannerReady($directory);
            // Obtain the capability from the planner's real durable admission. A
            // hand-written capability would only test transport validation.
            $admitted = AcceptanceScheduler::admittedRecord($directory, 'external-task', $config);
            // Consume the worker FIFO barrier while the original source is
            // still installed; releaseDispatch() reuses this same deadline.
            AcceptanceScheduler::awaitFixtureReady($directory);
            $replacement = (string) file_get_contents($config) . "\nif (\n";
            $temporary = $config . '.new';
            file_put_contents($temporary, $replacement, LOCK_EX);
            rename($temporary, $config);

            $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
            self::assertNotSame(0, $health->getExitCode(), 'health must fail for a stale planner');
            self::assertSame('planner.source_stale', trim($health->getErrorOutput() . $health->getOutput()));

            $staleCapability = AcceptanceScheduler::capabilityFromRecord($admitted, 'ledger');
            $worker = AcceptanceScheduler::invoke('run', 'external-task', $config, $directory, $staleCapability);
            self::assertSame(ExecutionWorkerExitCodeMapper::PROTOCOL_FAILURE, $worker->getExitCode(), 'claim rejection must retain the protocol exit class');
            // The S15 runtime must expose only the result's bounded stable reason.
            self::assertSame("worker.claim_rejected\n", $worker->getErrorOutput());
            self::assertSame('', $worker->getOutput());
            AcceptanceScheduler::releaseDispatch($directory, $admitted, $config);
            self::assertSame("succeeded\n", file_get_contents($directory . '/external.completed'));

            $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
            self::assertNotSame(0, $health->getExitCode(), 'health must fail for a stale planner');
            self::assertSame('planner.source_stale', trim($health->getErrorOutput() . $health->getOutput()));
        } finally {
            $planner->stop(1);
            self::assertFalse($planner->isRunning(), 'controlled parent termination must be observable');
        }
    }
}
