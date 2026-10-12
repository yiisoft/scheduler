# Internals

## Runtime contract

`bin/scheduler` starts the planner unless the first command is `run`; `healthcheck` is a
read-only probe. The planner prepares one pinned source, acquires local ownership, admits
occurrences, supervises workers, and writes progress. The worker receives a scalar
capability, claims the fenced ledger record, bootstraps its own runtime, executes, and
writes a fenced terminal outcome.

The planner and worker are independent processes and independent compositions. Neither a
prepared configuration nor a planner graph is transported. `SchedulerFactory` exposes
`createPlanner(PlannerCompositionInput): SourcePlannerRuntime` and
`createFreshWorker(WorkerCompositionInput): WorkerAdapter`. The fresh-worker factory
creates its own clock, poller, ledger locator, runtime factory, launcher dependencies, and
exact concrete-class command executor map. It accepts typed composition inputs, not a
service locator or parent-process objects.

## Command/task boundary

`TaskDefinition` is the owner of scheduling policy: mode, timeout, trigger, overlap, and
missed-occurrence handling. Its command is a direct immutable `Command` handle. Execution
selects an executor by exact concrete command class through `CommandExecutorMap`.

The configuration converter retains command handles and callable bodies only in the
process-local `PreparedConfiguration`. It does not resolve services. A fresh worker
re-evaluates the source, validates the pinned source revision and fingerprints, then
recreates the command and resolves dependencies in its own process-local container.

The sole admitted task projection is command-free transport format **3**. It contains
scalar scheduling policy and source identity/revision. Commands and callable bodies are
absent. Callable declarations and their scalar inputs are reconstructed from the source.
Shell `argv` is reconstructed literally.

## Fences and durable formats

The worker capability has version **3**. The file execution ledger codec has version **5**.
The planner state marker has runtime version **3**. These are independent fences for
worker admission, ledger persistence, and planner state. Unknown or mismatched versions
are rejected; the runtime never silently migrates them.

Source identity and `sourceRevision` have different jobs. Identity chooses the local
state namespace and ownership; the caller-owned revision pins the prepared run and is
compared by planner and worker. The source implementation owns revision calculation and
must return a stable bounded value. A revision change stops the planner or rejects a
worker using the old revision.

## Timeout and supervision

Synchronous work is local and cannot declare an enforced timeout. Async work is supervised
by the parent using `WorkerLifecyclePolicy`. Where a process timeout is configured in the
`TaskDefinition` policy, its clock begins at the launched process start recorded by
`ProcessLaunchResult`; worker preparation does not consume it. Timeout policy belongs to task scheduling policy, not to the command handle.

`ShellCommandExecutor` implements `ExecutorInterface` and delegates to a fresh shell
runtime. The runtime owns variable resolution, process launch, polling, and soft/hard
process-timeout handling. Parent lifecycle supervision remains a separate concern.

## State and failure behavior

State resolution is `--state-dir`, `SCHEDULER_STATE_DIR`, `$XDG_STATE_HOME/scheduler`,
then `$HOME/.local/state/scheduler`; no unsafe temporary fallback is used. State is local
and source-scoped, not a distributed ownership backend. Overlap is allowed by default and
async capacity defaults to 16 per source. Recovery and terminal writes are fenced and
fail closed: unresolved ownership or ambiguous process closure is not successful work.
Retained process observations are an application-owned boundary.

The health snapshot default maximum age is 15 seconds
(`PlannerHealthSnapshot::DEFAULT_MAX_AGE`). Blocking synchronous code can make it stale.
Worker exit classes are 0 (success), 1 (execution failure), 2 (argument/protocol/catch
failure), and 3 (unresolved ownership or ambiguous completion).

## Unit testing

The package is tested with [PHPUnit](https://phpunit.de/):

```shell
./vendor/bin/phpunit
```

## Static analysis

The package is statically analyzed with [Psalm](https://psalm.dev/):

```shell
./vendor/bin/psalm
```
