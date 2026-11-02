# Upgrading Instructions for Yii Scheduler

This file contains the upgrade notes. These notes highlight changes that could break your
application when you upgrade the package from one version to another.

## 1.0.0

- The generated identifier of a cron task and of a queue message task has changed, because the
  timezone an expression is read in and the payload a message carries are now part of it. A
  stateful schedule holding such a task reads its new identifier as a task it has never seen:
  the record written under the old one is left behind and the task starts from the position of
  the schedule. Give those tasks an identifier of their own with `RecurringTask::withId()` to
  keep them where they were, and delete the orphaned checkpoint entries from the cache. Tasks
  identified by `withId()`, and cron tasks left to the default timezone, are unaffected.

- `Scheduler::run()` now forks a child process per due task by default. Anyone calling it
  directly gets the forked behaviour described in the README: `PostRunEvent` and the `after`
  listeners receive `null` instead of the handler's result, a task failure surfaces as a
  `RuntimeException` about the child's exit status rather than the original exception, and a
  handler must not reuse a connection opened before the fork. Pass `false` as the second
  argument to keep running due tasks one after another in the scheduler process, which is what
  `schedule:work --sequential` does.
