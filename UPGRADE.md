# Upgrading Instructions for Yii Scheduler

This file contains the upgrade notes. These notes highlight changes that could break your
application when you upgrade the package from one version to another.

## 1.0.0

- A schedule with a ledger now decides what to start from the executions that ledger holds,
  rather than only from the child processes this planner started. A task whose earlier run is
  still held by another planner, or by this one before it was restarted, is skipped instead of
  being started a second time. Give the task `withOverlap(Overlap::Allow)` to keep the older
  behaviour for it.

- `Schedule::lock()` takes a `Yiisoft\Scheduler\Lock\LockInterface` rather than a
  `Yiisoft\Mutex\MutexInterface`, so a schedule can be locked without installing yiisoft/mutex.
  Wrap an existing mutex: `->lock(new MutexLock($mutex))`. `Schedule::getMutex()` is renamed to
  `getLock()`; it is internal and read by the checkpoint.

- `symfony/console` is no longer a dependency of this package. A schedule that runs tasks named
  as console commands, or that uses the bundled `schedule:run`, `schedule:work` and
  `schedule:list` commands, now requires it in the application: `composer require
  symfony/console`. An application on `yiisoft/yii-console` already has it.

- `CallableTaskHandler` no longer runs console commands and no longer takes an `Application`.
  The new `ConsoleTaskHandler` does, with the handler for everything else behind it:
  `new ConsoleTaskHandler($application, new CallableTaskHandler())`. The bundled configuration
  builds that chain by itself; only an application constructing the handler by hand has to
  change. `QueueTaskHandler` now takes any `TaskHandlerInterface` as its fallback rather than a
  `CallableTaskHandler`, and passes on everything it does not recognise instead of refusing it,
  so a handler of the application placed behind it is reached. The handler at the back of the
  chain is the one that answers for a task nobody runs.

- `QueueTaskHandler` checks for a message before it checks for a callable, so an object that is
  both a `MessageInterface` and invokable is now pushed to the queue rather than invoked in
  place. No message class of yiisoft/queue is invokable; an application whose own message class
  is affects only that class.

- `ScheduleProviderInterface` is removed. Nothing in the package implemented, consumed or
  registered it. A schedule built at run time, from a database or anywhere else, is passed to
  `Scheduler` as any other schedule is.

- The generated identifier has changed for three kinds of task, because what decides when they
  run is now part of it: a cron task carries the timezone its expression is read in, a task
  triggered by a `PeriodicTrigger` with an anchor or an end date carries those dates, and a
  queue message task carries the payload of its message. A stateful schedule holding such a
  task reads its new identifier as a task it has never seen: the record written under the old
  one is left behind and the task starts from the position of the schedule. Give those tasks an
  identifier of their own with `RecurringTask::withId()` to keep them where they were, and
  delete the orphaned checkpoint entries from the cache. Tasks identified by `withId()`, cron
  tasks left to the default timezone and intervals left to the default anchor with no end date
  are unaffected.

  `RecurringTask::cron('0 9 * * *', $task, 'UTC')` and `RecurringTask::cron('0 9 * * *', $task)`
  are two identifiers even where the default timezone is UTC and the two run at the same times:
  the first names a timezone and the second takes whichever one the process is set to.

- `Scheduler::run()` now forks a child process per due task by default. Anyone calling it
  directly gets the forked behaviour described in the README: `PostRunEvent` and the `after`
  listeners receive `null` instead of the handler's result, a task failure surfaces as a
  `RuntimeException` about the child's exit status rather than the original exception, and a
  handler must not reuse a connection opened before the fork. Pass `false` as the second
  argument to keep running due tasks one after another in the scheduler process, which is what
  `schedule:work --sequential` does.
