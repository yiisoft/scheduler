<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px" alt="Yii">
    </a>
    <h1 align="center">Yii Scheduler</h1>
    <br>
</p>

[![Latest Stable Version](https://poser.pugx.org/yiisoft/scheduler/v)](https://packagist.org/packages/yiisoft/scheduler)
[![Total Downloads](https://poser.pugx.org/yiisoft/scheduler/downloads)](https://packagist.org/packages/yiisoft/scheduler)
[![Build status](https://github.com/yiisoft/scheduler/actions/workflows/build.yml/badge.svg)](https://github.com/yiisoft/scheduler/actions/workflows/build.yml)
[![Code Coverage](https://codecov.io/gh/yiisoft/scheduler/graph/badge.svg)](https://codecov.io/gh/yiisoft/scheduler)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fyiisoft%2Fscheduler%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/yiisoft/scheduler/master)
[![static analysis](https://github.com/yiisoft/scheduler/actions/workflows/static.yml/badge.svg?branch=master)](https://github.com/yiisoft/scheduler/actions/workflows/static.yml?query=branch%3Amaster)

Recurring tasks and cron-style scheduling for PHP applications: define the tasks in code,
and let a single cron entry or a long-running worker run them when due — with missed-run
catch-up, overlap prevention and failure handling included.

## Features

- Cron expressions and fixed intervals through a single `TriggerInterface` contract
- Tasks as callables, console commands, or queue messages (via [yiisoft/queue](https://github.com/yiisoft/queue))
- Catch-up of missed runs and crash-resume through persisted checkpoints (any PSR-16 cache)
- Overlap prevention across processes through [yiisoft/mutex](https://github.com/yiisoft/mutex)
- PSR-14 events and per-schedule listeners (before / after / onFailure)
- `schedule:run` (single tick, for system cron), `schedule:work` (daemon), `schedule:list` commands

## Requirements

- PHP 8.1 - 8.5.

## Installation

```shell
composer require yiisoft/scheduler
```

## General usage

Declare tasks in the configuration:

```php
// config/params.php
use Yiisoft\Scheduler\RecurringTask;

return [
    'yiisoft/scheduler' => [
        'tasks' => [
            RecurringTask::cron('0 5 * * *', 'report:daily'),
            RecurringTask::every('10 minutes', static fn () => $cleaner->purgeExpiredTokens()),
            RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('metrics.collect', [])),
        ],
    ],
];
```

and run them, either with a single system cron entry:

```shell
* * * * * php yii schedule:run
```

or as a daemon:

```shell
php yii schedule:work
```

The daemon sleeps until the next scheduled run instead of polling on a fixed interval, and
(with `ext-pcntl`) forks a child process per due task, so tasks sharing a due time run in
parallel instead of blocking one another. Pass `--sequential` to run due tasks one after
another as before, or `--max-processes` to cap how many run at once.

A failing task does not stop the daemon: the failure goes to the `FailureEvent` listeners
and, unless one of them ignores it, to the log as an error, while the other tasks carry on.
Make sure the application has a PSR-3 logger, or handle `FailureEvent`, to hear about it.

A few things to know about the forked mode:

- a task's return value cannot cross the process boundary, so `PostRunEvent` and the `after`
  listeners receive `null` as the result; a failure is reported as the child's exit status;
- a child inherits the parent's open connections and sockets, so a task that talks to a
  database or similar should acquire its own connection rather than reuse one opened before
  the fork — or push the work to the queue, which is fast enough to do in the parent. To keep
  the parent's own resources out of it, the child does not go through PHP's shutdown at all:
  no destructors, no `register_shutdown_function` callbacks, its own included, so a task that
  needs to clean up after itself should do so before it returns;
- a task whose previous run is still going is skipped instead of being started a second time,
  so one that takes longer than its own interval cannot pile up a process per due time.
  `--max-processes` caps the total as well: once that many are running, the next due task
  waits for a slot instead of being started alongside them;
- stopping the daemon asks the running tasks to stop with `SIGTERM` and waits five seconds for
  them before resorting to `SIGKILL`. That needs `ext-posix`; without it they are left running,
  to finish on their own.

`schedule:run` exits between runs, so a persisted checkpoint is the only way for it to tell
which runs already happened: it requires a stateful schedule and reports an error without one.
The bundled configuration makes the schedule stateful automatically when the application has a
PSR-16 cache. `schedule:work` keeps the position in memory and works either way, though a
checkpoint also lets it compensate the runs missed while it was down.

When building a schedule by hand, persist its checkpoint, and add a mutex to prevent
overlapping runs across processes:

```php
use Yiisoft\Scheduler\Schedule;

$schedule = (new Schedule())
    ->stateful($cache) // any PSR-16 implementation
    ->lock($mutex)     // any yiisoft/mutex implementation
    ->task(...);
```

## Running on multiple servers

The same setup scales horizontally without a single point of failure: put the `schedule:run`
cron entry (or the `schedule:work` daemon) on every application server. Two things then need
to be shared between the servers:

- the mutex needs a shared driver — [yiisoft/mutex-redis](https://github.com/yiisoft/mutex-redis),
  [yiisoft/mutex-pdo-mysql](https://github.com/yiisoft/mutex-pdo-mysql) or
  [yiisoft/mutex-pdo-pgsql](https://github.com/yiisoft/mutex-pdo-pgsql) — so that the first
  server acquiring it runs the due tasks while the others skip the tick silently;
- the checkpoint cache needs a shared PSR-16 pool — for example
  [yiisoft/cache-redis](https://github.com/yiisoft/cache-redis) — so that every server sees
  which runs already happened, and one resuming after downtime compensates only the runs
  nobody covered instead of re-running them.

The bundled configuration wires the checkpoint cache automatically as soon as the application
has a PSR-16 cache; the mutex is opt-in — add `->lock($mutex)` as shown above. With both in
place, a task is still executed exactly once no matter how many servers the schedule runs on,
and no single server going down stops the schedule.

## Documentation

- [Internals](docs/internals.md)

If you need help or have questions, [contact us](https://www.yiiframework.com/community/).

## License

The Yii Scheduler is free software. It is released under the terms of the BSD License.
Please see [`LICENSE`](./LICENSE.md) for more information.

Maintained by [Yii Software](https://www.yiiframework.com/).

## Support the project

[![Open Collective](https://img.shields.io/badge/Open%20Collective-sponsor-7eadf1?logo=open%20collective&logoColor=7eadf1&labelColor=555555)](https://opencollective.com/yiisoft)
