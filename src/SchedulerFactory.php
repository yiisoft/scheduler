<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use Yiisoft\Scheduler\Binary\{WorkerAdapter,WorkerCompositionInput};
use Yiisoft\Scheduler\Clock\SystemClock;
use Yiisoft\Scheduler\Config\PhpConfigurationSource;
use Yiisoft\Scheduler\Executor\{PhpProcessConfiguration,ProcessLocalCommandExecutorRuntimeFactory};
use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionWorkerEntrypoint,PreparedConfigurationExecutionWorkerRuntimeFactory,PreparedShellCommandRuntimeFactory,SystemMonotonicClock,UsleepPollWaiter};
use Yiisoft\Scheduler\Integration\Async\Process\{SameFileExecutionWorkerProcessCommandBuilder,SymfonyExecutionWorkerLauncher,SymfonyProcessLauncher};
use Yiisoft\Scheduler\Integration\Async\Store\{FileExecutionOccurrenceCheckpoint,SQLiteExecutionLedger,SQLiteExecutionLedgerLocator};
use Yiisoft\Scheduler\Planner\{FilePlannerOwner,LedgerCommandScopeProcessResolver,ParentLifecycleSupervisor,PlannerCompositionInput,SourcePlannerRuntime};
use Yiisoft\Scheduler\Integration\Async\Process\LinuxProcessObserver;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;

use function extension_loaded;

/** Process-local composition root for the planner and fresh-worker graphs. */
final class SchedulerFactory
{
    public function createPlanner(PlannerCompositionInput $input): SourcePlannerRuntime
    {
        $command = $input->command;
        $query = $input->query;
        if (($command === null) !== ($query === null)) {
            throw new InvalidArgumentException('Planner command and query ports must be supplied together.');
        }

        $source = $input->source ?? new PhpConfigurationSource($input->configurationPath);
        $sourceId = $source->sourceId();
        $owner = $input->owner ?? (new FilePlannerOwner($input->stateDirectory))->acquire($sourceId);
        $wallClock = $input->wallClock ?? new SystemClock();
        $monotonicClock = $input->monotonicClock ?? new SystemMonotonicClock();
        $waiter = $input->waiter ?? new UsleepPollWaiter();
        $processLauncher = new SymfonyProcessLauncher($monotonicClock);
        $shellFactory = $input->shellRuntimeFactory ?? new PreparedShellCommandRuntimeFactory(
            $processLauncher,
            $monotonicClock,
            $waiter,
            $input->shellVariableOverrides,
            getenv() ?: [],
            $input->stateDirectory,
        );
        $runtimeFactory = $input->runtimeFactory ?? new ProcessLocalCommandExecutorRuntimeFactory($shellFactory);
        $configuration = $source->prepare($runtimeFactory);
        if ($command === null && $query === null) {
            $ledger = new SQLiteExecutionLedger($this->createDefaultSqliteConnection($owner->stateDirectory()));
            $command = $ledger;
            $query = $ledger;
        }
        if ($command === null || $query === null) {
            throw new InvalidArgumentException('Planner command and query ports must be supplied together.');
        }
        $checkpoint = $input->occurrenceCheckpoint ?? new FileExecutionOccurrenceCheckpoint($owner->stateDirectory() . '/checkpoints');
        $recoveryObserver = $input->recoveryProcessObserver ?? new LinuxProcessObserver();
        $supervisor = $input->supervisor ?? new ParentLifecycleSupervisor(
            $monotonicClock,
            $waiter,
            new LedgerCommandScopeProcessResolver($query, $recoveryObserver),
        );
        $launcher = $input->workerLauncher ?? new SymfonyExecutionWorkerLauncher(
            new SameFileExecutionWorkerProcessCommandBuilder(
                $input->workerExecutable,
                $input->configurationPath,
                $sourceId,
                $owner->stateDirectory(),
                overrides: $input->shellVariableOverrides,
                clock: $monotonicClock,
                phpConfiguration: PhpProcessConfiguration::current(),
            ),
            $supervisor,
            $processLauncher,
        );

        return new SourcePlannerRuntime(
            $configuration,
            $source,
            $owner,
            $wallClock,
            $monotonicClock,
            $waiter,
            $launcher,
            $supervisor,
            $owner->stateDirectory(),
            $command,
            $query,
            $checkpoint,
            $recoveryObserver,
        );
    }

    public function createFreshWorker(WorkerCompositionInput $input): WorkerAdapter
    {
        if (!is_dir($input->stateDirectory)) {
            throw new InvalidArgumentException('Worker state directory is invalid.');
        }
        $source = $input->source ?? new PhpConfigurationSource($input->configurationPath);
        $ledgerLocator = $input->ledgerLocator ?? new SQLiteExecutionLedgerLocator(
            $this->createDefaultSqliteConnection($input->stateDirectory),
            'ledger',
        );
        $wallClock = $input->wallClock ?? new SystemClock();
        $monotonicClock = $input->monotonicClock ?? new SystemMonotonicClock();
        $waiter = $input->waiter ?? new UsleepPollWaiter();
        $processLauncher = $input->processLauncher ?? new SymfonyProcessLauncher($monotonicClock);
        $shellFactory = $input->shellRuntimeFactory ?? new PreparedShellCommandRuntimeFactory(
            $processLauncher,
            $monotonicClock,
            $waiter,
            $input->shellVariableOverrides,
            getenv() ?: [],
            $input->stateDirectory,
        );
        $runtimeFactory = new PreparedConfigurationExecutionWorkerRuntimeFactory(
            $source,
            $input->runtimeFactory ?? new ProcessLocalCommandExecutorRuntimeFactory($shellFactory),
            $shellFactory,
        );
        $entrypoint = new ExecutionWorkerEntrypoint($ledgerLocator, $runtimeFactory, $wallClock);

        return new WorkerAdapter($entrypoint, $ledgerLocator);
    }

    /**
     * The SQLite adapter is optional so custom command/query ports and
     * explicit worker locators remain usable without its packages.
     *
     * @return Connection
     */
    private function createDefaultSqliteConnection(string $stateDirectory): object
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new InvalidArgumentException('The default SQLite execution ledger requires the pdo_sqlite extension. Install ext-pdo_sqlite or provide custom ledger ports.');
        }
        if (!extension_loaded('mbstring')) {
            throw new InvalidArgumentException('The default SQLite execution ledger requires the mbstring extension. Install ext-mbstring or provide custom ledger ports.');
        }
        if (!class_exists('Yiisoft\\Db\\Sqlite\\Connection') || !class_exists('Yiisoft\\Db\\Sqlite\\Driver')) {
            throw new InvalidArgumentException('The default SQLite execution ledger requires yiisoft/db and yiisoft/db-sqlite. Install them or provide custom ledger ports.');
        }

        /** @var Connection $connection */
        $connection = new Connection(
            new Driver('sqlite:' . $stateDirectory . '/ledger.sqlite'),
        );
        return $connection;
    }
}
