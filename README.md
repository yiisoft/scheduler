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

- Cron expressions and fixed intervals through a single `TriggerInterface` contract, written
  by hand or built a field at a time (`RecurringTask::daily()->at('05:00')`)
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
use App\Token\TokenStorage; // a class of your application
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Scheduler\RecurringTask;

return [
    'yiisoft/scheduler' => [
        'tasks' => [
            RecurringTask::cron('0 5 * * *', 'report:daily'),
            RecurringTask::every('10 minutes', [TokenStorage::class, 'purgeExpired']), // a static method
            RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('metrics.collect', [])),
        ],
    ],
];
```

A task is one of:

- the name of a console command, with its arguments and options if any;
- a callable other than a string, since a string is always read as a command. It is called
  with the `TaskContext` of the run, as is: the container does not resolve its arguments, and
  a configuration file has no services to capture in a closure — so a task that needs them
  is better off as a console command;
- a queue message, pushed to the queue when the container has a
  [yiisoft/queue](https://github.com/yiisoft/queue) producer (`QueueProducerInterface`).

## Building the expression

A cron expression says when a task runs in five positional fields, which is terse to write and
slow to read. `RecurringTask` also opens a builder that sets them one at a time:

```php
use Yiisoft\Scheduler\RecurringTask;

RecurringTask::daily()->at('05:00')->run('report:daily');
RecurringTask::everyFiveMinutes()->between('08:00', '17:00')->run('cache:warm');
RecurringTask::weekly()->on('monday')->at('09:30')->run('report:weekly');
RecurringTask::monthly()->onLastDayOfMonth()->at('23:00')->run('invoice:issue');
```

The chain starts with how often the task runs — `everyMinute()`, `everyMinutes()`,
`everyFiveMinutes()`, `everyTenMinutes()`, `everyFifteenMinutes()`, `everyThirtyMinutes()`,
`hourly()`, `everyHours()`, `daily()`, `weekly()`, `monthly()`, `quarterly()` or `yearly()` —
and narrows it down from there:

| Method | What it sets |
| --- | --- |
| `at('09:30')` | the time of day, on a chain that already runs once a day |
| `atHours(1, 13)` | the hours of the day |
| `atMinutes(15, 45)` | the minutes of every hour |
| `on('monday', 'thursday')` | the days of the week, named or numbered the way cron numbers them |
| `weekdays()` / `weekends()` | Monday to Friday / Saturday and Sunday |
| `onDays(1, 15)` | the days of the month |
| `onLastDayOfMonth()` | the last day of the month, whichever day that is |
| `inMonths(6, 7, 8)` | the months |
| `between('08:00', '17:00')` | keeps only the runs within those hours |
| `unlessBetween('22:00', '06:00')` | keeps only the runs outside those hours |
| `timezone('Europe/Skopje')` | the timezone the expression is read in |

The chain ends with `run($task)` for a task, `toTrigger()` for a trigger alone, or
`getExpression()` for the expression as a string. A task built this way is the one the same
expression passed to `RecurringTask::cron()` would give, identifier included, so a schedule can
be rewritten into the builder without any task being treated as new.

Every step returns a new builder, leaving the one it was called on alone, so a half-built
expression can be kept and finished in more than one way:

```php
$nightly = RecurringTask::daily()->at('02:00');

$tasks = [
    $nightly->run('cache:clear'),
    $nightly->weekdays()->run('report:daily'),
];
```

A time window is compiled into the expression rather than checked when the task is about to
run, so `schedule:list` and the next run date of the trigger account for it. That is why
`between()` takes whole hours: a cron field cannot say "from 08:30". The start hour is included
and the end hour is not, so `everyFiveMinutes()->between('08:00', '17:00')` last runs at 16:55,
and a window whose end is earlier than its start crosses midnight.

`between()` and `unlessBetween()` narrow the hours the expression already runs at rather than
replacing them, so `everyHours(6)->between('08:00', '17:00')` keeps the one run of the four
that falls in the window instead of turning the task into an hourly one. A window that would
leave no run at all is refused rather than given hours nobody asked for.

Each step guards the frequency the step before it set. `at()` replaces both the hour and the
minute, so it needs a chain that already runs once a day — on `hourly()` or
`everyFiveMinutes()` it would quietly drop the repetition, and is refused; use `atMinutes()`
and `atHours()` to place the runs of a repeating chain. A day of the week and a day of the
month cannot both be set, because cron runs a task on a day matching *either* field once both
are narrowed: `weekly()->onLastDayOfMonth()` would run on every Monday as well as on the last
day of the month, so it is refused too. Write the expression by hand and pass it to
`new CronBuilder(...)` when that union is what you mean.

An interval has to divide its unit evenly — `everyMinutes()` takes 1, 2, 3, 4, 5, 6, 10, 12, 15,
20 or 30, and `everyHours()` takes 1, 2, 3, 4, 6, 8 or 12 — so that the last run of one hour or
day and the first of the next are an interval apart like every other pair. Anything else is
refused rather than quietly left with a short gap at the boundary.

`weekly()` runs on Mondays, the day `every('1 week')` runs on as well. Pass the day to `on()`
rather than rely on the default when it matters which one it is.

Run them either with a single system cron entry:

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
and to the log as an error, while the other tasks carry on. Make sure the application has a
PSR-3 logger, or handle `FailureEvent`, to hear about it.

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

An interval is counted from a fixed point in time rather than from the moment the scheduler
was started, so `every('10 minutes')` runs at :00, :10, :20 and so on, `every('1 day')` at
midnight and `every('1 week')` on Mondays. To count from another moment, pass a
`PeriodicTrigger` with its own `from` date to `RecurringTask::trigger()`.

When building a schedule by hand, persist its checkpoint, and add a mutex to prevent
overlapping runs across processes:

```php
use Yiisoft\Scheduler\Schedule;

$schedule = (new Schedule())
    ->stateful($cache) // any PSR-16 implementation
    ->lock($mutex)     // any yiisoft/mutex implementation
    ->task(...);
```

## Task identifiers

Every task gets an identifier generated from the cron expression or the interval of its
trigger and from what it runs: the command name, the class of an object, the type of a queue
message, or the file and line a closure is declared at. Two tasks alike in all of that cannot
share a schedule until they are given identifiers of their own. Such are closures created in a
loop, queue messages differing only in their payload, and tasks whose triggers differ only in
their timezone or the date they count from:

```php
$tasks = [];

foreach (['eu', 'us'] as $region) {
    $tasks[] = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('metrics.collect', ['region' => $region]))
        ->withId('metrics-' . $region);
}
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
