<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;
use Yiisoft\Db\Sqlite\{Connection, Driver};
use Yiisoft\Scheduler\Integration\Async\{ExecutionLedgerRecord, ExecutionOccurrenceKey, ExecutionOutcome, UsleepPollWaiter};
use Yiisoft\Scheduler\Config\{PhpConfigurationSource, PreparedConfiguration};
use Yiisoft\Scheduler\Executor\{CommandExecutorMap, CommandExecutorRuntime, CommandExecutorRuntimeFactory};
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Executor\ExecutionContext;
use Yiisoft\Scheduler\Integration\Async\Store\{FileExecutionLedgerCodec, SQLiteExecutionLedger};
use LogicException;

use function array_replace;
use function dirname;
use function fclose;
use function fgets;
use function file_get_contents;
use function fopen;
use function function_exists;
use function fwrite;
use function getenv;
use function hash_file;
use function hrtime;
use function in_array;
use function is_array;
use function is_executable;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function min;
use function posix_mkfifo;
use function preg_match;
use function putenv;
use function realpath;
use function stream_select;
use function stream_set_blocking;
use function str_ends_with;
use function defined;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

final class AcceptanceScheduler
{
    /** @var array<string, resource> */
    private static array $readinessReaders = [];

    /** @var array<string, array{deadline:int, clock:callable, consumed:bool}> */
    private static array $fixtureReadiness = [];

    public static function executable(): string
    {
        // S01 is an acceptance test of the repository-root executable contract. Do
        // not allow an environment override to conceal a missing S15 executable.
        return dirname(__DIR__, 2) . '/bin/scheduler';
    }

    /** @return array{process: Process, directory: string} */
    public static function startPlanner(string $config, string $directory): array
    {
        $ready = $directory . '/ready.fifo';
        $release = $directory . '/release.fifo';
        if (!function_exists('posix_mkfifo') || !posix_mkfifo($ready, 0600) || !posix_mkfifo($release, 0600)) {
            Assert::fail('acceptance requires the POSIX FIFO extension for its bounded handshake');
        }
        if (!is_file(self::executable()) || !is_executable(self::executable())) {
            Assert::fail('scheduler startup failed: planned executable is missing or not executable: ' . self::executable());
        }
        // Install the parent-side reader before spawning.  The two FIFOs are
        // directional: this descriptor is never used for parent->child data.
        $readiness = fopen($ready, 'r+');
        Assert::assertIsResource($readiness);
        stream_set_blocking($readiness, false);
        self::$readinessReaders[$directory] = $readiness;
        $process = new Process([self::executable(), '--config', $config], null, self::environment($directory, $config) + [
            'SCHEDULER_ACCEPTANCE_READY' => $ready,
            'SCHEDULER_ACCEPTANCE_RELEASE' => $release,
        ]);
        $process->setTimeout(5);
        try {
            $process->start();
        } catch (Throwable $exception) {
            Assert::fail('scheduler startup failed (runtime missing or not executable): ' . $exception->getMessage());
        }
        return ['process' => $process, 'directory' => $directory];
    }

    /** Suspend a planner while an acceptance test mutates its durable state. */
    public static function suspendPlanner(Process $planner): void
    {
        Assert::assertTrue($planner->isRunning());
        $planner->signal(defined('SIGSTOP') ? SIGSTOP : 19);
    }

    /** Resume a planner after an acceptance test has finished its mutation. */
    public static function resumePlanner(Process $planner): void
    {
        Assert::assertTrue($planner->isRunning());
        $planner->signal(defined('SIGCONT') ? SIGCONT : 18);
    }

    /** Wait for readiness without opening a blocking FIFO endpoint. */
    public static function awaitPlannerReady(string $directory): void
    {
        $ready = self::$readinessReaders[$directory] ?? null;
        Assert::assertIsResource($ready);
        $read = [$ready];
        $write = $except = [];
        Assert::assertGreaterThan(0, stream_select($read, $write, $except, 5), 'planner startup gate was not reached');
        Assert::assertSame("ready\n", fgets($ready));
    }

    /**
     * Consume the fixture readiness event before any source replacement.
     *
     * The barrier owns the deadline used by releaseDispatch(), so consuming it
     * early cannot create a second, unbounded wait or leave a FIFO race.
     */
    public static function awaitFixtureReady(string $directory, ?callable $monotonicClock = null): void
    {
        $state = self::fixtureReadinessState($directory, $monotonicClock);
        if ($state['consumed']) {
            return;
        }

        $ready = self::$readinessReaders[$directory] ?? null;
        Assert::assertIsResource($ready);
        $remaining = $state['deadline'] - $state['clock']();
        Assert::assertGreaterThan(0, $remaining, 'fixture readiness wait deadline expired');
        $read = [$ready];
        $write = $except = [];
        $selected = stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1_000));
        Assert::assertGreaterThan(0, $selected, 'fixture readiness gate was not reached');
        Assert::assertSame("ready\n", fgets($ready), 'fixture readiness event was invalid');
        self::$fixtureReadiness[$directory]['consumed'] = true;
    }

    /** Complete the fixture's FIFO rendezvous without sleeping or retrying. */
    public static function releaseDispatch(string $directory, array $expectedRecord, string $source, ?callable $monotonicClock = null): void
    {
        // This endpoint is write-only in the parent. It is never read back, so a
        // parent can neither consume its own release nor mistake it for completion.
        // r+ is intentional: a write-only FIFO open can block forever when a
        // child fails before opening its reader. Keep this endpoint directional
        // (the parent only writes) while making open/configuration bounded.
        $release = fopen($directory . '/release.fifo', 'r+');
        Assert::assertIsResource($release, 'release channel could not be opened');
        Assert::assertTrue(stream_set_blocking($release, false), 'release channel could not be configured nonblocking');

        // Reuse the readiness barrier consumed before a source replacement.
        // This is idempotent when the caller already consumed the event.
        self::awaitFixtureReady($directory, $monotonicClock);
        $state = self::$fixtureReadiness[$directory];
        $deadline = $state['deadline'];
        $clock = $state['clock'];
        $remaining = $deadline - $clock();
        Assert::assertGreaterThan(0, $remaining, 'release channel wait deadline expired');
        $write = [$release];
        $read = $except = [];
        $selected = stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1_000));
        Assert::assertSame(1, $selected, 'release channel did not become writable');
        Assert::assertSame(8, fwrite($release, "release\n"));
        fclose($release);
        // The dispatch barrier may finish at the admission deadline; terminal
        // completion must have a fresh bounded window for the async worker.
        self::awaitTerminalRecord($directory, $expectedRecord, $source, $clock() + 5_000_000_000, $clock);

        // The external terminal record only proves that dispatch was released.
        // The cron command is admitted independently and may still be running;
        // bind the second barrier to the exact occurrence currently in its
        // ledger rather than to a task name or an arbitrary latest record.
        // A source replacement makes the planner stale; in that case there is
        // no currently admitted cron occurrence under the replacement source.
        if (($expectedRecord['metadata']['sourceRevision'] ?? null) !== hash_file('sha256', $source)) {
            return;
        }
        $cronRecord = self::admittedCronRecord($directory, $expectedRecord, $source, $deadline, $clock);
        // Admission may consume the readiness deadline while the async worker
        // is still running. Terminal completion gets its own bounded window.
        self::awaitTerminalRecord($directory, $cronRecord, $source, $clock() + 5_000_000_000, $clock);
    }

    /** @param array<string, mixed> $expectedRecord @return array<string, mixed> */
    public static function admittedRecord(string $directory, string $task, string $source): array
    {
        $expected = self::fixtureExpectations($task, $source, $directory);
        $sourceId = realpath($source);
        $sourceRevision = hash_file('sha256', $source);
        Assert::assertIsString($sourceId);
        Assert::assertIsString($sourceRevision);
        // Planner readiness follows a completed tick, while a periodic occurrence
        // can still be waiting for admission. Poll the exact identity below
        // against the same bounded deadline used by the acceptance barriers.
        $state = self::fixtureReadinessState($directory, null);
        $deadline = $state['deadline'];
        $clock = $state['clock'];
        do {
            $matches = [];
            foreach (self::ledgerRecords($source) as $record) {
                $identity = $record['metadata']['taskMappingIdentity'] ?? null;
                $occurrence = $record['occurrence'] ?? null;
                $correlation = $record['correlation'] ?? null;
                if (!is_array($identity) || !is_array($occurrence) || !is_array($correlation)) {
                    continue;
                }
                // The occurrence id is the fixture source's canonical timestamp
                // encoding.  This prevents an older occurrence of the same task
                // from being selected merely because its task and schedule match.
                $timestamp = self::timestampFromOccurrence($occurrence['occurrenceId'] ?? null);
                if ($occurrence['taskKey'] === $task
                    && $identity === array_replace($expected['identity'], [
                        'occurrence' => $occurrence['occurrenceId'],
                        'occurrenceTime' => $timestamp,
                        'runId' => $correlation['runId'] ?? null,
                    ])
                    && $correlation === self::fixtureCorrelation($task, $occurrence['occurrenceId'], $source)
                    && $record['configurationFingerprint'] === $expected['configurationFingerprint']
                    && ($record['taskSnapshot'] ?? null) === $expected['taskSnapshot']
                    && ($record['metadata']['sourceId'] ?? null) === $sourceId
                    && ($record['metadata']['sourceRevision'] ?? null) === $sourceRevision
                    && in_array($record['state'] ?? null, ['pending', 'admitted', 'claimed', 'running', 'launch-required', 'succeeded', 'failed', 'cancelled'], true)) {
                    Assert::assertSame(self::fixtureCorrelation($task, $occurrence['occurrenceId'], $source), $correlation);
                    Assert::assertSame($correlation['runId'], $identity['runId']);
                    $matches[] = $record;
                }
            }
            if ($matches !== []) {
                Assert::assertCount(1, $matches, 'planner must admit exactly one occurrence of ' . $task);
                return $matches[0];
            }
            self::boundedPollWait($deadline, $clock);
        } while ($clock() < $deadline);
        Assert::assertCount(1, $matches, 'planner must admit exactly one occurrence of ' . $task);
        return $matches[0];
    }

    /** @param array<string, mixed> $expectedRecord */
    public static function assertTerminalRecord(string $directory, array $expectedRecord, string $source): void
    {
        $expected = $expectedRecord['occurrence'] ?? null;
        Assert::assertIsArray($expected);
        $state = self::fixtureReadinessState($directory, null);
        // Exact admission can arrive at the end of the readiness window. Do
        // not reuse that admission deadline for asynchronous completion.
        self::awaitTerminalRecord(
            $directory,
            $expectedRecord,
            $source,
            $state['clock']() + 5_000_000_000,
            $state['clock'],
        );
    }

    /** @param array<string, mixed> $record */
    public static function assertCompleteSourceIdentity(array $record, string $source, ?array $admission = null, ?string $directory = null): void
    {
        $identity = $record['metadata']['taskMappingIdentity'] ?? null;
        Assert::assertIsArray($identity);
        foreach (['task', 'occurrence', 'occurrenceTime', 'locator', 'runId', 'mode', 'timeout', 'supervisionRequested', 'context'] as $key) {
            Assert::assertArrayHasKey($key, $identity, 'ledger source identity is incomplete: ' . $key);
        }
        Assert::assertArrayNotHasKey('schedule', $identity);
        Assert::assertSame($record['occurrence']['taskKey'], $identity['task']);
        Assert::assertSame($record['occurrence']['occurrenceId'], $identity['occurrence']);
        Assert::assertSame($record['correlation']['runId'], $identity['runId']);
        if ($admission !== null) {
            // The terminal record must preserve the exact admitted fixture
            // identity, including command/locator/timeout/context and every
            // correlation component—not merely equivalent task names.
            Assert::assertSame($admission['occurrence'], $record['occurrence']);
            Assert::assertSame($admission['correlation'], $record['correlation']);
            Assert::assertSame($admission['metadata']['taskMappingIdentity'], $identity);
            Assert::assertSame($admission['configurationFingerprint'], $record['configurationFingerprint']);
            Assert::assertSame($admission['taskSnapshot'], $record['taskSnapshot']);
        }
        // Source identity and raw source revision are distinct ledger facts.
        // A version-isolation test intentionally replaces the source after
        // admission. Terminal identity is pinned to the admitted source
        // revision, rather than to the replacement currently at this path.
        $sourceId = $admission['metadata']['sourceId'] ?? realpath($source);
        $sourceRevision = $admission['metadata']['sourceRevision'] ?? hash_file('sha256', $source);
        Assert::assertSame($sourceId, $record['metadata']['sourceId'] ?? null);
        Assert::assertSame($sourceRevision, $record['metadata']['sourceRevision'] ?? null);
        $task = $record['occurrence']['taskKey'];
        // A version-isolation test may replace the source after admission;
        // validate that terminal records preserve the admitted snapshot.
        $expected = $admission === null
            ? self::fixtureExpectations($task, $source, $directory ?? dirname($source))
            : [
                'configurationFingerprint' => $admission['configurationFingerprint'],
                'taskVersion' => hash('sha256', PreparedConfiguration::canonical($admission['taskSnapshot'])),
                'taskSnapshot' => $admission['taskSnapshot'],
                'identity' => $admission['metadata']['taskMappingIdentity'],
            ];
        Assert::assertSame($expected['configurationFingerprint'], $record['configurationFingerprint'] ?? null);
        Assert::assertSame($expected['taskSnapshot'], $record['taskSnapshot'] ?? null);
        Assert::assertSame($sourceId, $record['metadata']['sourceId'] ?? null);
        Assert::assertSame($sourceRevision, $record['metadata']['sourceRevision'] ?? null);
        $expectedIdentity = array_replace($expected['identity'], [
            'occurrence' => $record['occurrence']['occurrenceId'],
            'occurrenceTime' => self::timestampFromOccurrence($record['occurrence']['occurrenceId'] ?? null),
            'runId' => $record['correlation']['runId'] ?? null,
        ]);
        Assert::assertSame($expectedIdentity, $identity);
        Assert::assertSame($record['correlation']['taskKey'], $record['occurrence']['taskKey']);
        Assert::assertSame($record['correlation']['taskKey'], $record['occurrence']['taskKey']);
        Assert::assertSame($record['correlation']['occurrenceId'], $record['occurrence']['occurrenceId']);
        foreach (['taskKey', 'occurrenceId', 'attemptId', 'runId', 'executionId'] as $key) {
            Assert::assertArrayHasKey($key, $record['correlation']);
            Assert::assertIsString($record['correlation'][$key]);
            Assert::assertNotSame('', $record['correlation'][$key]);
        }
        Assert::assertSame('dev', $record['schema'] ?? null);
        Assert::assertIsString($record['configurationFingerprint'] ?? null);
        Assert::assertSame('dev', $record['taskSnapshot']['schema'] ?? null);
    }

    /** Snapshot all durable planner/ownership/cursor/ledger observations. */
    public static function observableState(string $directory): array
    {
        $state = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || str_ends_with($file->getFilename(), '.fifo')) {
                continue;
            }
            $state[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
        ksort($state);
        return $state;
    }

    /** Encode only the capability fields emitted by the admitted ledger record. */
    public static function capabilityFromRecord(array $record, string $ledger): string
    {
        $policy = $record['taskSnapshot'];
        if (isset($policy['task']['recoveryMaxAge'])) {
            $policy['task']['recoveryMaxAge'] = (float) $policy['task']['recoveryMaxAge'];
        }
        $capability = [
            'schema' => 'dev',
            'occurrence' => [
                'task' => $record['occurrence']['taskKey'],
                'id' => $record['occurrence']['occurrenceId'],
            ],
            'attempt' => $record['correlation']['attemptId'],
            'run' => $record['correlation']['runId'],
            'execution' => $record['correlation']['executionId'],
            'generation' => $record['fence']['generation'],
            'fence' => $record['fence']['token'],
            'configurationFingerprint' => $record['configurationFingerprint'],
            'taskVersion' => hash('sha256', PreparedConfiguration::canonical($policy)),
            'ledger' => $ledger,
            'runtime' => 'acceptance-runtime',
            'sourceRevision' => $record['metadata']['sourceRevision'] ?? '',
            'policy' => $policy,
            'recoveryFence' => [
                'generation' => $record['recoveryFence']['generation'],
                'token' => $record['recoveryFence']['token'],
            ],
        ];
        if (isset($record['launchDescriptor'], $record['dispatchReservation'])) {
            $descriptor = $record['launchDescriptor'];
            $capability['launchDescriptor'] = [
                'execution' => $descriptor['executionId'],
                'generation' => $descriptor['generation'],
                'nonce' => $descriptor['nonce'],
                'scope' => $descriptor['scopeKind'],
                'role' => $descriptor['role'],
                'occurrence' => [
                    'task' => $descriptor['occurrence']['taskKey'],
                    'id' => $descriptor['occurrence']['occurrenceId'],
                ],
            ];
            $capability['permissionId'] = $record['dispatchReservation']['permissionId'];
        }
        return json_encode($capability, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Return the production owner state directory for a canonical source id. */
    public static function sourceStateDirectory(string $source): string
    {
        $sourceId = realpath($source);
        Assert::assertIsString($sourceId, 'source must resolve to a canonical path');
        return dirname($sourceId) . '/.scheduler/sources/' . hash('sha256', $sourceId);
    }

    public static function sourceLedgerDirectory(string $source): string
    {
        return self::sourceStateDirectory($source) . '/ledger';
    }

    /** @return list<array<string, mixed>> */
    public static function ledgerRecords(string $source): array
    {
        $connection = self::sqliteConnection($source);
        new SQLiteExecutionLedger($connection);
        $rows = $connection->createCommand(
            'SELECT payload FROM execution_ledger WHERE source_identity = :source ORDER BY task_key, occurrence_id',
            ['source' => self::sourceIdentity($source)],
        )->queryAll();
        /** @var list<array<string, mixed>> $rows */
        $codec = new FileExecutionLedgerCodec();
        $records = [];
        foreach ($rows as $row) {
            if (is_string($row['payload'] ?? null)) {
                $records[] = self::decodeLedgerRecord($codec, $row['payload']);
            }
        }
        return $records;
    }

    /** @param array{taskKey: string, occurrenceId: string} $occurrence @return array<string, mixed>|null */
    public static function ledgerRecord(string $source, array $occurrence): ?array
    {
        $record = self::sqliteLedger($source)->observe(new ExecutionOccurrenceKey($occurrence['taskKey'], $occurrence['occurrenceId']));
        return $record === null ? null : self::encodeLedgerRecord($record);
    }

    public static function invoke(string $mode, ?string $task, string $config, string $directory, ?string $input = null): Process
    {
        $arguments = [self::executable(), $mode];
        if ($task !== null) {
            $arguments[] = $task;
        }
        $arguments[] = '--config';
        $arguments[] = $config;
        if ($mode === 'run') {
            $arguments[] = '--source';
            $arguments[] = $config;
            $arguments[] = '--state';
            $arguments[] = self::sourceStateDirectory($config);
        }
        $process = new Process($arguments, null, self::environment($directory, $config));
        $process->setTimeout(5);
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->run();
        return $process;
    }

    private static function sqliteConnection(string $source): Connection
    {
        return new Connection(new Driver('sqlite:' . self::sourceStateDirectory($source) . '/ledger.sqlite'));
    }

    private static function sqliteLedger(string $source): SQLiteExecutionLedger
    {
        return new SQLiteExecutionLedger(self::sqliteConnection($source));
    }

    private static function sourceIdentity(string $source): string
    {
        $sourceId = realpath($source);
        Assert::assertIsString($sourceId);
        return $sourceId;
    }

    /** @return array<string, mixed> */
    private static function encodeLedgerRecord(ExecutionLedgerRecord $record): array
    {
        $encoded = json_decode((new FileExecutionLedgerCodec())->encode($record), true, 64, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($encoded);
        return $encoded;
    }

    /** @return array<string, mixed> */
    private static function decodeLedgerRecord(FileExecutionLedgerCodec $codec, string $payload): array
    {
        $encoded = json_decode($codec->encode($codec->decode($payload)), true, 64, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($encoded);
        return $encoded;
    }

    /** @return array{deadline:int, clock:callable, consumed:bool} */
    private static function fixtureReadinessState(string $directory, ?callable $monotonicClock): array
    {
        if (!isset(self::$fixtureReadiness[$directory])) {
            $clock = $monotonicClock ?? static fn(): int => hrtime(true);
            self::$fixtureReadiness[$directory] = [
                'deadline' => $clock() + 5_000_000_000,
                'clock' => $clock,
                'consumed' => false,
            ];
        }
        return self::$fixtureReadiness[$directory];
    }

    /**
     * Find the cron admission belonging to the same planner run as the external
     * admission. This deliberately reads the durable admission rather than
     * recompiling the source: ConfigurationVersionTest replaces that source
     * before releasing the planner.
     *
     * @param array<string, mixed> $externalAdmission
     * @return array<string, mixed>
     */
    private static function admittedCronRecord(string $directory, array $externalAdmission, string $source, int $deadline, callable $monotonicClock): array
    {
        $sourceId = $externalAdmission['metadata']['sourceId'] ?? null;
        $sourceRevision = $externalAdmission['metadata']['sourceRevision'] ?? null;
        $runId = $externalAdmission['correlation']['runId'] ?? null;
        $configurationFingerprint = $externalAdmission['configurationFingerprint'] ?? null;
        Assert::assertIsString($sourceId);
        Assert::assertIsString($sourceRevision);
        Assert::assertIsString($runId);
        Assert::assertIsString($configurationFingerprint);
        // Validate the fixture correlation contract once. The occurrence is
        // supplied by the ledger below, so polling uses the non-counting
        // equivalent builder instead of repeating this assertion.
        self::fixtureCorrelation('cron-task', 'o0000000000', $sourceId, $sourceRevision);
        do {
            $matches = [];
            foreach (self::ledgerRecords($source) as $record) {
                $occurrence = $record['occurrence'] ?? null;
                $correlation = $record['correlation'] ?? null;
                $identity = $record['metadata']['taskMappingIdentity'] ?? null;
                if (!is_array($occurrence) || !is_array($correlation) || !is_array($identity)) {
                    continue;
                }
                $occurrenceId = $occurrence['occurrenceId'] ?? null;
                self::timestampFromOccurrence($occurrenceId);
                if ($occurrence['taskKey'] === 'cron-task'
                    && $correlation === self::fixtureCorrelationValues('cron-task', $occurrenceId, $sourceId, $sourceRevision)
                    && $correlation['runId'] === $runId
                    && $record['configurationFingerprint'] === $configurationFingerprint
                    && ($record['metadata']['sourceId'] ?? null) === $sourceId
                    && ($record['metadata']['sourceRevision'] ?? null) === $sourceRevision
                    && $identity === [
                        'task' => 'cron-task', 'occurrence' => $occurrenceId,
                        'occurrenceTime' => self::timestampFromOccurrence($occurrenceId),
                        'locator' => $sourceId, 'runId' => $runId,
                        'mode' => 'async', 'timeout' => null, 'supervisionRequested' => false, 'context' => [
                            'sourceId' => $sourceId, 'sourceRevision' => $sourceRevision,
                        ],
                    ]
                    && in_array($record['state'] ?? null, ['pending', 'admitted', 'claimed', 'running', 'launch-required', 'succeeded', 'failed', 'cancelled'], true)) {
                    $matches[] = $record;
                }
            }
            if ($matches !== []) {
                Assert::assertCount(1, $matches, 'planner must admit exactly one occurrence of cron-task');
                return $matches[0];
            }
            self::boundedPollWait($deadline, $monotonicClock);
        } while ($monotonicClock() < $deadline);
        Assert::assertCount(1, $matches, 'planner must admit exactly one occurrence of cron-task');
        return $matches[0];
    }

    /** Wait against the exact admitted occurrence, with one bounded deadline. */
    private static function awaitTerminalRecord(string $directory, array $expectedRecord, string $source, int $deadline, callable $monotonicClock): void
    {
        $expected = $expectedRecord['occurrence'] ?? null;
        Assert::assertIsArray($expected);
        do {
            if (($terminal = self::terminalRecord($directory, $expected, $source)) !== null) {
                self::assertSameAdmissionIdentity($expectedRecord, $terminal);
                self::assertCompleteTerminalIdentity($terminal, $expectedRecord, $source, $directory);
                return;
            }
            // There is no completion event in the readiness FIFO; wait for the
            // next bounded poll of the exact durable ledger record.
            self::boundedPollWait($deadline, $monotonicClock);
        } while ($monotonicClock() < $deadline);

        $terminal = self::terminalRecord($directory, $expected, $source);
        if ($terminal === null) {
            Assert::fail('expected occurrence did not reach a terminal state');
        }
        self::assertSameAdmissionIdentity($expectedRecord, $terminal);
        self::assertCompleteTerminalIdentity($terminal, $expectedRecord, $source, $directory);
    }

    /** Wait for the next bounded ledger poll without treating readiness as completion. */
    private static function boundedPollWait(int $deadline, callable $monotonicClock): void
    {
        $remaining = $deadline - $monotonicClock();
        if ($remaining <= 0) {
            Assert::fail('terminal ledger wait deadline expired');
        }
        // Ledger files have no completion descriptor in this protocol. Poll the
        // exact record instead, with a short wait clipped to the single deadline.
        (new UsleepPollWaiter())->wait(min(0.01, $remaining / 1_000_000_000));
    }

    /** Validate the complete identity on every terminal assertion path. */
    private static function assertCompleteTerminalIdentity(array $terminal, array $admission, string $source, string $directory): void
    {
        $occurrence = $terminal['occurrence'] ?? null;
        Assert::assertIsArray($occurrence);
        Assert::assertIsString($occurrence['taskKey'] ?? null);
        Assert::assertIsString($occurrence['occurrenceId'] ?? null);
        // Validate the terminal correlation against the fixture contract itself,
        // independently of the admitted record, before preserving admission checks.
        Assert::assertSame(
            self::fixtureCorrelation(
                $occurrence['taskKey'],
                $occurrence['occurrenceId'],
                $admission['metadata']['sourceId'] ?? $source,
                $admission['metadata']['sourceRevision'] ?? hash_file('sha256', $source),
            ),
            $terminal['correlation'] ?? null,
            'terminal correlation does not match the fixture contract',
        );
        self::assertCompleteSourceIdentity(
            $terminal,
            $source,
            $admission,
            $directory,
        );
    }

    /** Compare every admitted identity fact, not only the occurrence key. */
    private static function assertSameAdmissionIdentity(array $admission, array $terminal): void
    {
        foreach (['occurrence', 'correlation', 'configurationFingerprint', 'taskSnapshot'] as $key) {
            Assert::assertArrayHasKey($key, $admission);
            Assert::assertSame($admission[$key], $terminal[$key] ?? null, 'terminal record changed ' . $key);
        }
        Assert::assertSame($admission['metadata']['taskMappingIdentity'] ?? null, $terminal['metadata']['taskMappingIdentity'] ?? null);
    }

    /** @return array<string, mixed>|null */
    private static function terminalRecord(string $directory, array $expected, string $source): ?array
    {
        $record = self::ledgerRecord($source, $expected);
        return $record !== null && in_array($record['state'] ?? null, ['succeeded', 'failed', 'cancelled'], true) ? $record : null;
    }

    /**
     * The acceptance fixture uses a deterministic correlation contract. These
     * values are derived from the fixture occurrence, not copied from the
     * record under test, so admission cannot make an arbitrary correlation
     * look valid.
     *
     * @return array{taskKey:string,occurrenceId:string,attemptId:string,runId:string,executionId:string}
     */
    private static function fixtureCorrelation(string $task, string $occurrence, string $source, ?string $revision = null): array
    {
        $sourceId = realpath($source) ?: $source;
        $revision ??= hash_file('sha256', $sourceId);
        Assert::assertIsString($revision, 'source revision must be readable');
        return self::fixtureCorrelationValues($task, $occurrence, $sourceId, $revision);
    }

    /** Build the deterministic correlation without assertions for polling. */
    private static function fixtureCorrelationValues(string $task, string $occurrence, string $source, string $revision): array
    {
        $sourceId = realpath($source) ?: $source;
        $sourceRevisionKey = $sourceId . "\0" . $revision;
        return [
            'taskKey' => $task,
            'occurrenceId' => $occurrence,
            'attemptId' => 'attempt-1',
            'runId' => 'planner-' . substr(hash('sha256', $sourceRevisionKey), 0, 32),
            'executionId' => 'e' . hash('sha256', $sourceRevisionKey . "\0" . $task . "\0" . $occurrence),
        ];
    }

    /** @return array{configurationFingerprint:string,taskVersion:string,taskSnapshot:array<string,mixed>,identity:array<string,mixed>} */
    private static function fixtureExpectations(string $task, string $source, string $directory): array
    {
        // The fixture reads these values while it is evaluated. Reproduce the
        // child's exact configuration inputs in a scoped parent environment.
        $keys = ['SCHEDULER_ACCEPTANCE_DIR', 'SCHEDULER_ACCEPTANCE_SOURCE', 'SCHEDULER_ACCEPTANCE_EXTERNAL_COMMAND', 'SCHEDULER_ACCEPTANCE_SYNC', 'SCHEDULER_ACCEPTANCE_LEDGER'];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = getenv($key);
        }
        putenv('SCHEDULER_ACCEPTANCE_DIR=' . $directory);
        putenv('SCHEDULER_ACCEPTANCE_SOURCE=' . $source);
        putenv('SCHEDULER_ACCEPTANCE_EXTERNAL_COMMAND=' . (realpath(__DIR__ . '/AcceptanceExternalCommand.php') ?: __DIR__ . '/AcceptanceExternalCommand.php'));
        putenv('SCHEDULER_ACCEPTANCE_SYNC=' . $directory . '/sync.effect');
        putenv('SCHEDULER_ACCEPTANCE_LEDGER=' . self::sourceLedgerDirectory($source));
        try {
            $configuration = (new PhpConfigurationSource($source))->prepare(self::fixtureRuntimeFactory());
        } finally {
            foreach ($previous as $key => $value) {
                $value === false ? putenv($key) : putenv($key . '=' . $value);
            }
        }
        $definition = $configuration->task($task);
        $sourceId = realpath($source);
        $sourceRevision = $configuration->sourceRevision;
        Assert::assertIsString($sourceId);
        Assert::assertIsString($sourceRevision);
        $compiledTask = (string) $definition->id();
        $compiledMode = $definition->mode->value;
        return [
            'configurationFingerprint' => $configuration->fingerprint(),
            'taskVersion' => $configuration->taskFingerprint($definition),
            'taskSnapshot' => $configuration->taskPolicyProjection($definition),
            'identity' => [
                'task' => $compiledTask, 'occurrence' => null,
                'occurrenceTime' => null,
                'locator' => $sourceId, 'runId' => null, 'mode' => $compiledMode,
                'timeout' => null, 'supervisionRequested' => false, 'context' => [
                    'sourceId' => $sourceId,
                    'sourceRevision' => $sourceRevision,
                ],
            ],
        ];
    }

    private static function fixtureRuntimeFactory(): CommandExecutorRuntimeFactory
    {
        return new class implements CommandExecutorRuntimeFactory {
            public function create(ContainerProvider $containerProvider): CommandExecutorRuntime
            {
                return new class implements CommandExecutorRuntime {
                    public function commandExecutorMap(): CommandExecutorMap
                    {
                        return new CommandExecutorMap([]);
                    }

                    public function execute(ExecutionContext $context): ExecutionOutcome
                    {
                        throw new LogicException('Acceptance expectation runtime is not executable.');
                    }
                };
            }
        };
    }

    private static function timestampFromOccurrence(mixed $occurrence): ?int
    {
        if (!is_string($occurrence) || !preg_match('/^o(\\d{10})$/D', $occurrence, $matches)) {
            return null;
        }
        return (int) $matches[1];
    }

    /** @return array<string, string> */
    private static function environment(string $directory, string $config): array
    {
        return [
            'SCHEDULER_ACCEPTANCE_DIR' => $directory,
            'SCHEDULER_ACCEPTANCE_SOURCE' => $config,
            'SCHEDULER_ACCEPTANCE_SYNC' => $directory . '/sync.effect',
            'SCHEDULER_ACCEPTANCE_LEDGER' => self::sourceLedgerDirectory($config),
            'SCHEDULER_ACCEPTANCE_EXTERNAL_COMMAND' => realpath(__DIR__ . '/AcceptanceExternalCommand.php') ?: __DIR__ . '/AcceptanceExternalCommand.php',
            // Keep acceptance state deterministic while exercising the same
            // resolver used by production planner and healthcheck invocations.
            'SCHEDULER_STATE_DIR' => dirname($config) . '/.scheduler',
        ];
    }
}
