<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Acceptance;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Tests\Support\AcceptanceScheduler;

use const JSON_THROW_ON_ERROR;

final class PlannerProcessTest extends TestCase
{
    public function testStandalonePlannerWorkerAndHealthcheckContract(): void
    {
        $directory = $this->directory();
        $config = $directory . '/scheduler.php';
        copy(__DIR__ . '/../Support/AcceptanceConfiguration.php', $config);

        $planner = AcceptanceScheduler::startPlanner($config, $directory)['process'];
        try {
            self::assertTrue($planner->isRunning(), 'planner must remain a live process');
            AcceptanceScheduler::awaitPlannerReady($directory);
            $externalAdmission = AcceptanceScheduler::admittedRecord($directory, 'external-task', $config);
            AcceptanceScheduler::releaseDispatch($directory, $externalAdmission, $config);

            self::assertFileExists($directory . '/external-effect.effect', 'external work must be dispatched by the planner');
            self::assertFileExists($directory . '/cron-effect.effect', 'cron work must be dispatched by the planner');
            self::assertFileExists($directory . '/external.completed', 'completion must be durable before the handshake returns');
            self::assertSame("succeeded\n", file_get_contents($directory . '/external.completed'));
            $ledger = glob(AcceptanceScheduler::sourceLedgerDirectory($config) . '/*.json') ?: [];
            self::assertNotEmpty($ledger, 'planner must leave a durable ledger record');
            $records = array_map(static fn(string $path): array => json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR), $ledger);
            $external = array_values(array_filter($records, static fn(array $record): bool
                => ($record['occurrence'] ?? null) === ($externalAdmission['occurrence'] ?? null)
                && ($record['correlation'] ?? null) === ($externalAdmission['correlation'] ?? null)
                && ($record['occurrence']['scheduleKey'] ?? null) === ($record['correlation']['scheduleKey'] ?? null)
                && ($record['occurrence']['taskKey'] ?? null) === ($record['correlation']['taskKey'] ?? null)
                && ($record['occurrence']['occurrenceId'] ?? null) === ($record['correlation']['occurrenceId'] ?? null)
                && ($record['occurrence']['scheduleKey'] ?? null) === 'default'
                && ($record['occurrence']['taskKey'] ?? null) === 'external-task'
                && ($record['state'] ?? null) === 'succeeded'
                && ($record['outcome']['status'] ?? null) === 'succeeded'));
            self::assertCount(1, $external, 'ledger must contain one exact completed external-task identity');
            AcceptanceScheduler::assertCompleteSourceIdentity($external[0], $config, $externalAdmission, $directory);
            self::assertIsString($external[0]['metadata']['occurrenceCursor'] ?? null);
            self::assertSame([
                'schedule' => 'default',
                'task' => 'external-task',
                'occurrence' => $external[0]['occurrence']['occurrenceId'],
            ], array_intersect_key($external[0]['metadata']['taskMappingIdentity'] ?? [], array_flip(['schedule', 'task', 'occurrence'])));
            $cronAdmission = AcceptanceScheduler::admittedRecord($directory, 'cron-task', $config);
            AcceptanceScheduler::assertTerminalRecord($directory, $cronAdmission, $config);
            // The admission snapshot predates terminalization; read the ledger
            // again after the exact occurrence wait.
            $terminalLedger = glob(AcceptanceScheduler::sourceLedgerDirectory($config) . '/*.json') ?: [];
            $records = array_map(static fn(string $path): array => json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR), $terminalLedger);
            $cron = array_values(array_filter($records, static fn(array $record): bool
                => ($record['occurrence'] ?? null) === ($cronAdmission['occurrence'] ?? null)
                && ($record['correlation'] ?? null) === ($cronAdmission['correlation'] ?? null)
                && ($record['state'] ?? null) === 'succeeded'));
            self::assertCount(1, $cron, 'ledger must record the exact admitted cron occurrence');
            AcceptanceScheduler::assertCompleteSourceIdentity($cron[0], $config, $cronAdmission, $directory);
            $plannerPid = (string) $planner->getPid();
            $workerPid = trim((string) file_get_contents($directory . '/worker.pid'));
            $externalPid = trim((string) file_get_contents($directory . '/external.pid'));
            self::assertNotSame($plannerPid, $workerPid);
            self::assertNotSame($workerPid, $externalPid);
            self::assertNotSame($plannerPid, $externalPid);
            self::assertSame((string) $planner->getPid(), trim((string) file_get_contents($directory . '/sync.effect')));
            foreach (['external-effect' => 'external-task', 'cron-effect' => 'cron-task'] as $effect => $task) {
                $identity = json_decode((string) file_get_contents($directory . '/' . $effect . '.identity.json'), true, 8, JSON_THROW_ON_ERROR);
                self::assertSame(realpath($config), $identity['source']);
                self::assertSame('default', $identity['schedule']);
                self::assertSame($task, $identity['task']);
            }

            $rejected = AcceptanceScheduler::invoke('run', 'missing-task', $config, $directory);
            self::assertNotSame(0, $rejected->getExitCode(), 'unknown claims must be rejected');
            // This exact class is the planned S15 worker-claim contract; do not
            // weaken the acceptance gate to a nonzero exit or message fragment.
            self::assertSame("worker.claim_rejected\n", $rejected->getErrorOutput());
            self::assertSame('', $rejected->getOutput());

            // Probe while the planner still owns the source. Capture the complete
            // observable state around the read-only probe, then stop ownership.
            $beforeHealth = AcceptanceScheduler::observableState($directory);
            $health = AcceptanceScheduler::invoke('healthcheck', null, $config, $directory);
            self::assertSame(0, $health->getExitCode(), $health->getErrorOutput());
            $afterHealth = AcceptanceScheduler::observableState($directory);
            self::assertSame($beforeHealth, $afterHealth, 'healthcheck must not mutate planner, ownership, cursor, or ledger state');
            $planner->stop(1);
            self::assertFalse($planner->isRunning());

        } finally {
            $planner->stop(1);
        }
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/yii-schedule-acceptance-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->addToAssertionCount(1);
        return $directory;
    }
}
