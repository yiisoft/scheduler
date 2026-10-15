<p align="center">
    <a href="https://github.com/yiisoft" target="_blank"><img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px" alt="Yii"></a>
    <h1 align="center">Yii Scheduler</h1>
</p>

[![Latest Stable Version](https://poser.pugx.org/yiisoft/scheduler/v)](https://packagist.org/packages/yiisoft/scheduler)

Recurring task scheduling for PHP applications. Define schedules in a package-neutral typed PHP DSL and prepare them for execution.

## Features

- Immutable, direct `Command` handles and typed task definitions
- Cron-style and periodic scheduling primitives
- Scalar-only policy and protocol data across the async boundary
- Explicit synchronous and asynchronous execution modes
- Fenced checkpoints, overlap prevention, and execution integration

## Requirements

- PHP 8.1 - 8.5.

## Installation

```shell
composer require yiisoft/scheduler
```

## Configuration

A PHP configuration file returns a zero-argument factory that creates `SchedulerConfig`. A task owns its scheduling policy; its `Command` is an immutable, process-local handle.

```php
<?php

use Yiisoft\Scheduler\Config\SchedulerConfig;
use Yiisoft\Scheduler\Executor\ShellCommand;

return static function (): SchedulerConfig {
    $config = new SchedulerConfig();
    $config->periodic('clear-cache', new ShellCommand('/usr/bin/php', ['bin/console', 'cache:clear']))
        ->every(3600)
        ->async();
    return $config;
};
```

`PhpConfigurationLoader::loadFile()` evaluates this factory. `ConfigurationConverter::convert()` produces a `PreparedConfiguration`, an in-process and non-serializable result that may retain command objects, callables, and a container provider. Applications must provide a configuration source with a stable identity and revision; the source owns how `sourceRevision` is computed.

## Execution model

The planner and a fresh worker each evaluate the configuration source independently. A worker never receives a prepared configuration, planner services, command objects, callable bodies, or a parent container. It reconstructs its own process-local runtime, then resolves the direct command handle through an exact concrete-class `CommandExecutorMap`.

The async boundary carries only bounded scalar records. Task transport is command-free format **4**: the task policy contains identity, mode, timeout, metadata, trigger, overlap, missed-occurrence policy, and source identity/revision. Callable declarations, their scalar inputs, and dependency resolution are rebuilt in the worker from its configuration factory. Shell `argv` remains literal and is not shell-parsed.

Capability transport is version **4**. The durable file ledger format is version **6**, the checkpoint schema is version **2**, and the planner runtime state marker is runtime version **4**. These are independent compatibility fences; an unknown or mismatched fence fails closed rather than being silently upgraded.

Scheduling policy belongs to `TaskDefinition`, including execution mode, timeout, trigger, overlap, and missed-occurrence policy. Synchronous execution is local and cannot declare an enforced timeout. Asynchronous execution is supervised by the parent through `WorkerLifecyclePolicy`; a configured process timeout starts when the launched process starts, not during worker preparation. The command does not own a timeout policy.

`SchedulerFactory` provides separate typed planner and fresh-worker factories. Each builds independent process-local graphs, including runtime factories and executor maps; the fresh-worker path does not accept planner graph objects and is not a service locator. Application bootstrap, worker launch, and service resolution remain application-owned and framework-neutral.

## CLI and state

```shell
bin/scheduler --config /app/config/scheduler.php
bin/scheduler run --config /app/config/scheduler.php --state-dir /var/lib/scheduler
bin/scheduler healthcheck --config /app/config/scheduler.php --state-dir /var/lib/scheduler
```

The default command is the planner; `run` is the worker protocol entrypoint and `healthcheck` is read-only. State resolution is `--state-dir`, `SCHEDULER_STATE_DIR`, `$XDG_STATE_HOME/scheduler`, then `$HOME/.local/state/scheduler`. State is local and source-scoped: source identity selects the namespace, while the caller-owned revision pins a run. A revision change is fatal to the planner and rejects workers using the old revision.

## Healthcheck

Progress is healthy through 15 seconds by default (`PlannerHealthSnapshot::DEFAULT_MAX_AGE`). Blocking synchronous user code can make progress stale, so long-running work should be asynchronous. Worker exit classes are stable: `0` success, `1` execution failure, `2` argument/protocol/catch failure, and `3` unresolved ownership or ambiguous completion.

The CLI does not provide status/history commands, `--once`, detach mode, framework adapters, queue integration, distributed backends, legacy runtime restoration, or destructive retention cleanup.

## Documentation

- [Internals](docs/internals.md)

## License

The Yii Scheduler is free software. It is released under the terms of the BSD License. See [`LICENSE.md`](./LICENSE.md).
