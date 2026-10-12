<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Acceptance;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Yiisoft\Scheduler\Tests\Support\AcceptanceScheduler;

use const JSON_THROW_ON_ERROR;

final class SourceIsolationTest extends TestCase
{
    public function testSourcesHaveIndependentPlannersAndRepeatedSourceIsRejected(): void
    {
        $root = sys_get_temp_dir() . '/yii-schedule-isolation-' . bin2hex(random_bytes(8));
        mkdir($root . '/one', 0700, true);
        mkdir($root . '/two', 0700, true);
        $one = $root . '/one/config.php';
        $two = $root . '/two/differently-named.php';
        copy(__DIR__ . '/../Support/AcceptanceConfiguration.php', $one);
        copy(__DIR__ . '/../Support/AcceptanceConfiguration.php', $two);

        $first = AcceptanceScheduler::startPlanner($one, $root . '/one')['process'];
        $second = AcceptanceScheduler::startPlanner($two, $root . '/two')['process'];
        try {
            self::assertTrue($first->isRunning());
            self::assertTrue($second->isRunning());
            AcceptanceScheduler::awaitPlannerReady($root . '/one');
            AcceptanceScheduler::awaitPlannerReady($root . '/two');
            $oneAdmission = AcceptanceScheduler::admittedRecord($root . '/one', 'external-task', $one);
            $twoAdmission = AcceptanceScheduler::admittedRecord($root . '/two', 'external-task', $two);
            AcceptanceScheduler::releaseDispatch($root . '/one', $oneAdmission, $one);
            AcceptanceScheduler::releaseDispatch($root . '/two', $twoAdmission, $two);

            $duplicateSentinel = $root . '/duplicate-evaluated.effect';
            $duplicate = new Process([AcceptanceScheduler::executable(), '--config', $one], null, [
                'SCHEDULER_ACCEPTANCE_DIR' => $root . '/one',
                'SCHEDULER_ACCEPTANCE_SOURCE' => $one,
                'SCHEDULER_ACCEPTANCE_SYNC' => $root . '/one/sync.effect',
                'SCHEDULER_ACCEPTANCE_LEDGER' => AcceptanceScheduler::sourceLedgerDirectory($one),
                'SCHEDULER_ACCEPTANCE_EXTERNAL_COMMAND' => realpath(__DIR__ . '/../Support/AcceptanceExternalCommand.php') ?: __DIR__ . '/../Support/AcceptanceExternalCommand.php',
                'SCHEDULER_ACCEPTANCE_DUPLICATE_SENTINEL' => $duplicateSentinel,
                'SCHEDULER_STATE_DIR' => $root . '/one/.scheduler',
            ]);
            $duplicate->setTimeout(5);
            $beforeRejectedPlanner = AcceptanceScheduler::observableState($root . '/one');
            $duplicate->run();
            $afterRejectedPlanner = AcceptanceScheduler::observableState($root . '/one');
            self::assertNotSame(0, $duplicate->getExitCode(), 'a second planner for one source must be rejected');
            self::assertSame(
                $beforeRejectedPlanner,
                $afterRejectedPlanner,
                'a rejected planner must not create independent durable task effects',
            );
            // Duplicate planner startup has a stable, exact binary error contract.
            self::assertSame("planner.owner_conflict\n", $duplicate->getErrorOutput());
            self::assertSame('', $duplicate->getOutput());
            // The sentinel is unique to the rejected process. Its absence
            // proves ownership rejection preceded source evaluation and task
            // side effects; the first planner cannot create this path.
            self::assertFileDoesNotExist($duplicateSentinel);

            self::assertFileExists($root . '/one/external-effect.effect');
            self::assertFileExists($root . '/two/external-effect.effect');
            $oneIdentity = json_decode((string) file_get_contents($root . '/one/external-effect.identity.json'), true, 8, JSON_THROW_ON_ERROR);
            $twoIdentity = json_decode((string) file_get_contents($root . '/two/external-effect.identity.json'), true, 8, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('schedule', $oneIdentity);
            self::assertArrayNotHasKey('schedule', $twoIdentity);
            self::assertSame('external-task', $oneIdentity['task']);
            self::assertSame('external-task', $twoIdentity['task']);
            self::assertNotSame($oneIdentity['source'], $twoIdentity['source']);
            self::assertSame(realpath($one), $oneIdentity['source']);
            self::assertSame(realpath($two), $twoIdentity['source']);
        } finally {
            $first->stop(1);
            $second->stop(1);
        }
    }
}
