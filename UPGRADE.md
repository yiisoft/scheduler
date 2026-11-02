# Upgrading Instructions for Yii Scheduler

This file contains the upgrade notes. These notes highlight changes that could break your
application when you upgrade the package from one version to another.

## 1.0.0

- `symfony/console` is no longer a dependency of this package. A schedule that runs tasks named
  as console commands, or that uses the bundled `schedule:run`, `schedule:work` and
  `schedule:list` commands, now requires it in the application: `composer require
  symfony/console`. An application on `yiisoft/yii-console` already has it.

- `CallableTaskHandler` no longer runs console commands and no longer takes an `Application`.
  The new `ConsoleTaskHandler` does, with the handler for everything else behind it:
  `new ConsoleTaskHandler($application, new CallableTaskHandler())`. The bundled configuration
  builds that chain by itself; only an application constructing the handler by hand has to
  change. `QueueTaskHandler` now takes any `TaskHandlerInterface` as its fallback rather than a
  `CallableTaskHandler`.

- `ScheduleProviderInterface` is removed. Nothing in the package implemented, consumed or
  registered it. A schedule built at run time, from a database or anywhere else, is passed to
  `Scheduler` as any other schedule is.

- `Scheduler::run()` now forks a child process per due task by default. Anyone calling it
  directly gets the forked behaviour described in the README: `PostRunEvent` and the `after`
  listeners receive `null` instead of the handler's result, a task failure surfaces as a
  `RuntimeException` about the child's exit status rather than the original exception, and a
  handler must not reuse a connection opened before the fork. Pass `false` as the second
  argument to keep running due tasks one after another in the scheduler process, which is what
  `schedule:work --sequential` does.
