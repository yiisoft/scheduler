<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Acceptance;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Tests\Support\AcceptanceScheduler;
use Yiisoft\Scheduler\Tests\Support\CallableDependencyInjectionService;

use function in_array;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const JSON_THROW_ON_ERROR;

/** End-to-end regression for container-owned typed callable services. */
final class CallableDependencyInjectionTest extends TestCase
{
    public function testPlannerDoesNotResolveLazyServicesAndFreshWorkersInvokeTypedCallables(): void
    {
        $directory = sys_get_temp_dir() . '/yii-schedule-callable-di-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $config = $directory . '/scheduler.php';
        copy(__DIR__ . '/../Support/CallableDependencyInjectionConfiguration.php', $config);

        $planner = AcceptanceScheduler::startPlanner($config, $directory)['process'];
        try {
            AcceptanceScheduler::awaitPlannerReady($directory);

            // Readiness is admission of a tick, not necessarily of each periodic
            // occurrence. admittedRecord() uses the bounded ledger poll to wait
            // for each exact callable task/source identity before effects.
            $asyncAdmission = AcceptanceScheduler::admittedRecord($directory, 'callable-async', $config);
            $syncAdmission = AcceptanceScheduler::admittedRecord($directory, 'callable-sync', $config);
            AcceptanceScheduler::assertTerminalRecord($directory, $asyncAdmission, $config);
            AcceptanceScheduler::assertTerminalRecord($directory, $syncAdmission, $config);

            // Terminal snapshots use the current ledger schema; this must not
            // regress to accepting the retired v2 assertion contract.
            foreach (glob(AcceptanceScheduler::sourceLedgerDirectory($config) . '/*.json') ?: [] as $path) {
                $record = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                if (in_array($record['state'] ?? null, ['succeeded', 'failed', 'cancelled'], true)) {
                    self::assertSame(6, $record['version'] ?? null);
                }
            }

            self::assertSame('async', $asyncAdmission['metadata']['taskMappingIdentity']['mode']);
            self::assertSame('sync', $syncAdmission['metadata']['taskMappingIdentity']['mode']);

            $plannerPid = (string) $planner->getPid();
            $resolutions = file($directory . '/container-resolutions', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertNotFalse($resolutions);
            self::assertCount(2, $resolutions);
            $resolutionPids = array_map(
                static fn(string $resolution): string => explode(':', $resolution, 2)[1],
                $resolutions,
            );
            self::assertSame(
                [CallableDependencyInjectionService::class, CallableDependencyInjectionService::class],
                array_map(
                    static fn(string $resolution): string => explode(':', $resolution, 2)[0],
                    $resolutions,
                ),
            );
            self::assertContains($plannerPid, $resolutionPids);
            self::assertCount(1, array_filter($resolutionPids, static fn(string $pid): bool => $pid !== $plannerPid));

            $async = trim((string) file_get_contents($directory . '/async.effect'));
            $sync = trim((string) file_get_contents($directory . '/sync.effect'));
            self::assertSame('7:' . substr($async, strrpos($async, ':') + 1), $async);
            self::assertSame('11:' . substr($sync, strrpos($sync, ':') + 1), $sync);
            self::assertNotSame($plannerPid, substr($async, strrpos($async, ':') + 1));
            self::assertSame($plannerPid, substr($sync, strrpos($sync, ':') + 1));
        } finally {
            $planner->stop(1);
        }
    }
}
