# Upgrading Instructions for Yii Scheduler

This release has a breaking configuration, CLI, state, and execution contract. Rebuild
integrations around these contracts rather than the pre-release scheduling API or runtime.

## 1.0.0

- Define schedules with the typed, package-neutral `SchedulerConfig` DSL and pass them to
  `ConfigurationConverter::convert()` to obtain a `PreparedConfiguration`. It is an
  in-process, non-serializable result; only bounded scalar identity, fingerprints, and
  protocol records cross a process boundary.
- Use the standalone `bin/scheduler` planner (the default), `bin/scheduler run` for the
  worker protocol, and `bin/scheduler healthcheck` for a read-only probe. State resolution
  is strict: `--state-dir` > `SCHEDULER_STATE_DIR` > `$XDG_STATE_HOME/scheduler` >
  `$HOME/.local/state/scheduler`; unavailable state fails explicitly.
- Treat state as a source-scoped local filesystem namespace. Source identity selects the
  ownership namespace, while the raw configuration revision pins a run. A revision change
  is fatal; it is not adopted by a live planner or old worker.
- Expect async execution by default; use explicit `sync()` for local execution. Literal
  shell `argv`, fresh-worker bootstrap, and application service/command resolution are
  separate boundaries. A fresh worker reconstructs its own prepared configuration from
  the source and checks its revision and `configurationFingerprint`; callable bodies and
  containers remain process-local. Framework-neutral bootstrap and resolver composition
  remain application-owned.
- Command timeout measurement starts at launched process start and is separate from
  `WorkerLifecyclePolicy`, which governs parent worker supervision. The canonical
  `ShellCommandExecutor` replaces the removed simple callback/proxy path and delegates
  to the shell runtime for process execution, polling, and timeout handling.
- Stable parser and configuration boundaries now use immutable typed value objects,
  while dynamic metadata, payload, environment, variable, wire, and persistence maps
  remain arrays. Use `SchedulerFactory`'s exact typed planner and fresh-worker methods
  for composition; defaults are factory-owned, `bin/scheduler` is a thin dispatcher,
  and fresh workers build isolated process-local graphs without a service locator.
- Expect overlap to be allowed by default and per-source async capacity to default to 16.
  Recovery and lifecycle supervision are fenced and fail closed; unresolved ownership or
  ambiguous completion is not success, and retained records are not automatically deleted.
- Health is read-only and stale after 15 seconds by default. Blocking synchronous work can
  prevent progress and make health stale. Exit classes are `0` success, `1` execution
  failure, `2` argument/protocol/catch failure, and `3` unresolved or ambiguous completion.
- The CLI does not restore status/history, `--once`, detach, framework adapters, queue
  integration, distributed backends, legacy behavior, or destructive retention cleanup.

The deprecated `MAX_AGE` compatibility constant has been removed; use `DEFAULT_MAX_AGE`
or pass the probe threshold explicitly.

Application bootstrap, process supervision, worker runtime composition, and service
resolution are still application-owned. YAML, JSON, Symfony, Laravel, and other format or
framework adapters are not included; do not assume a Yii adapter is available.
