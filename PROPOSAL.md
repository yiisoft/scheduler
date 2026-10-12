# Yii Schedule: approved command/task redesign

This package uses a framework-neutral typed DSL. A configuration factory creates a
`SchedulerConfig`; conversion produces an in-process `PreparedConfiguration`.

## Command and task model

A task contains a direct immutable `Command` handle. `ShellCommand` contains its literal
executable, `argv`, and variables; other command implementations may carry their own
process-local declaration. The executor is selected in the process that runs the task by
an exact concrete-class `CommandExecutorMap`.

`TaskDefinition` owns scheduling policy: execution mode, timeout policy, trigger, overlap,
and missed-occurrence policy. A synchronous task runs locally and cannot declare an
enforced timeout. An asynchronous task is supervised by its parent through
`WorkerLifecyclePolicy`; any process timeout starts at launched-process start and is part
of the task scheduling policy.

## Process boundary

`PreparedConfiguration` is non-serializable. The planner and fresh worker independently
run the configuration factory and build their own runtime graphs. No planner object,
prepared configuration, command object, callable body, or container crosses the boundary.
`SchedulerFactory::createPlanner()` and `createFreshWorker()` are separate typed
composition paths; the latter creates independent runtime factories and executor maps.

The admitted task transport is scalar-only and command-free. Format **3** contains the
task policy and source identity/revision. Callable declarations and their scalar inputs
are reconstructed from the source in the worker, which resolves its process-local
dependencies. Shell argument vectors are reconstructed and remain literal.

Three independent durable compatibility fences are part of this boundary:

- worker capability format **3**;
- file execution ledger format **5**;
- planner runtime marker **3**.

A fence mismatch is rejected or fails closed. The source identity and source revision are
also distinct: identity selects local state ownership, while the revision pins a run.
Revision calculation and stability are caller-owned by the configuration source; the
scheduler can compare a revision, but cannot make an unstable caller revision stable.

## Composition and ownership

The standalone binary delegates graph assembly to `SchedulerFactory`. The planner owns
local source ownership, occurrence admission, checkpoints, ledger coordination, and
parent worker supervision. A fresh worker claims its fenced ledger record before
bootstrapping application runtime and writes a fenced terminal outcome. Unresolved
ownership and ambiguous process closure are never treated as success.

The package remains framework-neutral. Application bootstrap, service resolution, worker
launch, and any configuration-source adapter beyond the PHP source are application
responsibilities.
