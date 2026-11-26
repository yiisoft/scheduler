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
- Typed task kinds — application callables, programs, PHP scripts — each run by the executor
  registered for its class, with kinds of your own registered the same way
- Catch-up of missed runs and crash-resume through persisted checkpoints (any PSR-16 cache)
- Overlap prevention across processes through any lock, with an adapter for [yiisoft/mutex](https://github.com/yiisoft/mutex)
- PSR-14 events and per-schedule listeners (before / after / onFailure)
- `schedule:run` (single tick, for system cron), `schedule:work` (daemon), `schedule:list` commands

## Requirements

- PHP 8.1 - 8.5.

The core depends on PSR interfaces and a cron expression parser, and nothing else. A console
command task needs [symfony/console](https://github.com/symfony/console), which also brings the
three bundled commands; a queue message task needs
[yiisoft/queue](https://github.com/yiisoft/queue); locking a schedule across processes needs a lock, for which
[yiisoft/mutex](https://github.com/yiisoft/mutex) has an adapter. Install the ones the schedule
uses.

## Installation

```shell
composer require yiisoft/scheduler
```

## General usage

The bundled configuration — `config/params.php` and `config/di.php` of this package — is the
Yii integration: it reads the tasks from the application parameters, builds the handler chain
from what the container has, and registers the three commands with yiisoft/yii-console. An
application on another framework, or on none, builds the same objects itself — see
[Without a framework](#without-a-framework).

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

- a command: a value saying what runs, and run by the executor registered for its class. See
  [below](#saying-what-a-task-runs);
- the name of a console command, with its arguments and options if any;
- a callable other than a string, since a string is always read as a command. It is called
  with the `TaskContext` of the run, as is: the container does not resolve its arguments, and
  a configuration file has no services to capture in a closure — so a task that needs them
  is better off as a console command;
- a queue message, pushed to the queue when the container has a
  [yiisoft/queue](https://github.com/yiisoft/queue) producer (`QueueProducerInterface`).

Each kind is handled by a handler of its own, and each passes on what it does not recognise:
`CallableTaskHandler` runs a callable and depends on nothing outside this package,
`ConsoleTaskHandler` runs a command name and needs
[symfony/console](https://github.com/symfony/console), `QueueTaskHandler` pushes a message and
needs yiisoft/queue. The bundled configuration builds the chain from whatever is installed and
registered, so a schedule of callables runs with neither package present.

## Saying what a task runs

The three task values above are read by what they happen to be: a string is the name of a
console command, an object is a queue message if a producer is installed. That is short to
write, and it leaves an application no way to add a kind of its own without joining in the
guessing. A command says what it is instead:

```php
use Yiisoft\Scheduler\Executor\CallableCommand;
use Yiisoft\Scheduler\Executor\PhpCommand;
use Yiisoft\Scheduler\Executor\ProcessCommand;
use Yiisoft\Scheduler\RecurringTask;

// Application code, called in this process.
RecurringTask::cron('0 5 * * *', CallableCommand::call($reconcile));

// A method of a service, asked of the container of whichever process runs it.
RecurringTask::cron('0 5 * * *', CallableCommand::of(ReportSender::class, 'sendDaily'));

// A program, in a process of its own.
RecurringTask::every('10 minutes', new ProcessCommand('/usr/bin/rsync', ['-a', '/data', '/backup']));

// A PHP script, under the PHP running the scheduler.
RecurringTask::cron('0 5 * * *', new PhpCommand('bin/console', ['app:report', '--since=yesterday']));
```

What runs a command is decided by one map, by the exact class of the command:

```php
use Yiisoft\Scheduler\Executor\ExecutorMap;
use Yiisoft\Scheduler\Handler\CommandTaskHandler;

$handler = new CommandTaskHandler(
    ExecutorMap::standard($container)->with([HttpCommand::class => new HttpExecutor($client)]),
);
```

`ExecutorMap::standard()` registers the three kinds above; `with()` adds the kinds of an
application, each a class implementing `CommandInterface` and an executor implementing
`ExecutorInterface`. The bundled configuration builds this handler in front of the older chain,
so a schedule can hold commands and the older task values at once.

Three things follow from a command being matched by its exact class:

- a subclass is a kind of its own. Nothing inherits an executor, so a command class that was
  registered and a subclass of it that was not are told apart rather than run alike;
- a command class nobody registered fails saying so, naming the class and what is registered.
  It does not fall through to a handler that would read it as something else;
- what runs a task is in the map rather than spread across the handlers, so reading the map is
  reading the dispatch.

A `ProcessCommand` is given to the operating system as a program and a list of arguments, with
no shell between the two. An argument holding a space, a quote, a `$` or a `;` reaches the
program as one argument with those characters in it, and starts nothing else. A `PhpCommand` is
the same thing with the PHP binary in front of the script: by default the binary running the
scheduler, so the script runs under the PHP that planned it rather than whichever `php` a
`PATH` resolves to.

`CallableCommand::of()` names a service and a method instead of holding a callable. It is
resolved by the container the executor was built with, which is the container of the process
doing the work: a task declared in a configuration file can then use the services of the
process that runs it, which a closure written in that file cannot.

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

Both commands tell the terminal what they are doing, as the program status of
[OSC 7501](https://www.superlogical.com/rex/docs/build/program-status): `schedule:run`
reports the due batch and how it went, and `schedule:work` reports what it is waiting for, so
a live daemon between runs can be told from a dead one. A terminal that does not know the
sequence ignores it, and nothing is written at all when the output is redirected or piped,
in quiet mode, or with `--no-ansi`. Pass `--no-program-status` to turn it off everywhere else.

An interval is counted from a fixed point in time rather than from the moment the scheduler
was started, so `every('10 minutes')` runs at :00, :10, :20 and so on, `every('1 day')` at
midnight and `every('1 week')` on Mondays. To count from another moment, pass a
`PeriodicTrigger` with its own `from` date to `RecurringTask::trigger()`.

`every()` takes a number of seconds, a `DateInterval`, or a string `DateInterval` understands —
`'90 seconds'`, `'10 minutes'`, `'1 day'`, `'2 weeks'`. An interval of hours, minutes and
seconds alone is an amount of time that passes, so a change of the clock neither skips nor
repeats its runs; one with days, months or years in it follows the calendar of its anchor's
timezone instead, so `every('1 day')` stays at the same local time across a change. Two forms
are refused when the trigger is asked for a date: one that parses but names no fixed amount of
time, such as `'next tuesday'`, and one that would not move a date forward, such as `'-1 hour'`.
Neither has a next run to compute; use a cron expression for a schedule of that shape.

When building a schedule by hand, persist its checkpoint, and add a lock to prevent
overlapping runs across processes:

```php
use Yiisoft\Scheduler\Lock\MutexLock;
use Yiisoft\Scheduler\Schedule;

$schedule = (new Schedule())
    ->stateful($cache) // any PSR-16 implementation
    ->lock(new MutexLock($mutex)) // or any LockInterface of your own
    ->task(...);
```

## What became of a run

A run is claimed in the checkpoint as it is handed out and recorded as `done`, `failed`,
`skipped` or `cancelled` — the cases of `TaskStatus` — once its outcome is known. A run the
scheduler never gets to, because it is shutting down, is given back to the schedule and stays
due rather than passing for a run that happened.

A claim is left behind when the process holding it dies. Every claim is taken back after a
window, set with `reclaimAfter()` and a day by default:

```php
$schedule->reclaimAfter(900); // or a DateInterval
```

The window has to outlast a run, counted from the moment it starts: the window is restarted
when the run begins, so the wait for a free slot under `--max-processes` does not eat into it.
A claim can still be taken back during that wait, and the scheduler that was holding it then
finds it gone and leaves the run to whoever took it over rather than making it twice. Taking a
run back while its process is actually making it does run the task a second time.

A claim made by a process of this host is taken back sooner than that, as soon as the process
is gone, which needs `ext-posix`. Asking about the process only brings the window forward and
never puts it off, so a claim whose process is still there is taken back once the window runs
out as well. A process being alive is no promise that the run is: a pid is given to something
else once the process it belonged to is gone, and a claim waiting on one of those would stand
forever.

## Without a framework

The core is a few objects with explicit dependencies, so an application that has no container,
no console and no framework builds them itself:

```php
use Yiisoft\Scheduler\Clock\SystemClock;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;

$schedule = (new Schedule())
    ->stateful($cache) // any PSR-16 implementation
    ->task(
        RecurringTask::everyFiveMinutes()->run(static fn() => $reports->collect())->withId('metrics'),
        RecurringTask::daily()->at('05:00')->run(static fn() => $reports->daily())->withId('daily-report'),
    );

$scheduler = new Scheduler([$schedule], new CallableTaskHandler(), new SystemClock());

// One tick per cron entry, or $scheduler->run() to stay up and tick until stopped.
$scheduler->tick();
```

That needs `psr/clock`, `psr/simple-cache`, `psr/log`, `psr/event-dispatcher` and a cron
expression parser, and nothing else. Add
`symfony/console` and a `ConsoleTaskHandler` to run tasks named as commands, `yiisoft/queue` and
a `QueueTaskHandler` to push them, a `LockInterface` to keep two processes off one schedule, and
a PSR-14 dispatcher or the `before`, `after` and `onFailure` listeners of the schedule to watch
what happens.

That is the core: the schedule, the clock, the checkpoint and the lock. How a task is written
stays with the package that runs it — a string task is the name of a console command and needs
symfony/console, a message task needs yiisoft/queue — and declaring tasks in `config/params.php`
is the Yii integration rather than the only way to fill a schedule.

### Starting the same core from a host

A framework builds the same three objects from whatever it already has, and nothing about the
scheduler changes with the host. The bundled Yii wiring in `config/di.php` is this, written as
container definitions; another host writes it in its own:

```php
// In the service definitions of the host, whatever shape those take.
$handler = new CallableTaskHandler();

if ($container->has(Application::class)) {
    $handler = new ConsoleTaskHandler($container->get(Application::class), $handler);
}

if ($container->has(QueueProducerInterface::class)) {
    $handler = new QueueTaskHandler($container->get(QueueProducerInterface::class), $handler);
}

$scheduler = new Scheduler(
    [$container->get(Schedule::class)], // however the host declares its tasks
    $handler,
    $container->get(ClockInterface::class),
    $container->get(LoggerInterface::class),
    $container->get(EventDispatcherInterface::class),
);
```

A command of the host then calls `tick()` or `run()` on that service, as the bundled
`schedule:run` and `schedule:work` do. The handlers compose in that order because each passes
on what it does not recognise, so a host that has no console or no queue simply builds a
shorter chain, and a handler of the application goes at the back of it.

## Asking a trigger for more than the next run

A trigger answers one question: given a run, when is the next one? That is all a schedule needs
while it keeps up. It is not all it needs after an outage: a task due every minute has some
forty thousand runs behind it after thirty days, and walking them one at a time is work the
schedule does before it can run anything else.

`TriggerRangeInterface` adds two questions for that case, and both built-in triggers answer
them:

```php
$trigger = new PeriodicTrigger(600, new DateTimeImmutable('2026-01-01 00:00:00'));

// The first run at or after a date, rather than strictly after it.
$trigger->getNextRunDateAtOrAfter(new DateTimeImmutable('2026-01-01 00:10:00'));

// A hundred runs at most, from the backlog between two dates.
$batch = $trigger->getRunDates($lastHandled, $now, 100);
```

Both bounds of a range are exclusive, so a batch continued from its own last date neither skips
a run nor repeats one, and both questions take a `$lastRun`: a run already accounted for, never
answered with again. The dates are the ones the trigger gives one at a time, in the same order —
a range is a different question about the same sequence, not a different sequence.

A custom trigger implements `TriggerInterface` alone and is asked one date at a time, as before.
Implement `TriggerRangeInterface` as well when the sequence can be enumerated more cheaply than
by walking it.

Walking is what a long gap makes expensive, whichever interface a trigger implements: a task due
every minute whose last run was a year ago is a year of dates before the first one is emitted. A
tick therefore walks at most ten thousand occurrences of any one task — a week of a minute task,
so an ordinary outage still drains in one — and leaves the rest to the tick after it. Nothing is
skipped: each tick seeds from the checkpoint and carries on where the one before stopped.

## Task identifiers

Every task gets an identifier generated from the description of its trigger and from what it
runs. The trigger contributes everything that decides when the task is due: the cron expression
and the timezone it is read in, or the interval together with the date it is counted from and
the date it stops at. What the task runs contributes the command name, the class of an object,
the type and payload of a queue message, or the file and line a closure is declared at.

Two tasks alike in all of that cannot share a schedule until they are given identifiers of
their own. Two of them are easy to write by accident. A closure carries no description beyond
where it is written, so two created in a loop are one task as far as the identifier is
concerned, and moving one to another line makes it a task the checkpoint has never seen. An
object is described by its class and not by what it was constructed with, so `new
SendReport('eu')` and `new SendReport('us')` are one task too.

```php
$tasks = [];

foreach (['eu', 'us'] as $region) {
    $tasks[] = RecurringTask::every('5 minutes', static fn() => collectMetrics($region))
        ->withId('metrics-' . $region);
}
```

An identifier is also what a checkpoint records a run under, so a task that is given one keeps
its place in the schedule through any later change to its trigger or its target.

## Running on multiple servers

The same setup scales horizontally without a single point of failure: put the `schedule:run`
cron entry (or the `schedule:work` daemon) on every application server. Two things then need
to be shared between the servers:

- the lock needs a shared driver — [yiisoft/mutex-redis](https://github.com/yiisoft/mutex-redis),
  [yiisoft/mutex-pdo-mysql](https://github.com/yiisoft/mutex-pdo-mysql) or
  [yiisoft/mutex-pdo-pgsql](https://github.com/yiisoft/mutex-pdo-pgsql) — so that the first
  server acquiring it runs the due tasks while the others skip the tick silently;
- the checkpoint cache needs a shared PSR-16 pool — for example
  [yiisoft/cache-redis](https://github.com/yiisoft/cache-redis) — so that every server sees
  which runs already happened, and one resuming after downtime compensates only the runs
  nobody covered instead of re-running them.

The bundled configuration wires the checkpoint cache automatically as soon as the application
has a PSR-16 cache; the lock is opt-in — add `->lock(new MutexLock($mutex))` as shown
above. With both in place a due run is handed out to one server only, and no single server
going down stops the schedule.

That holds while a run finishes inside its reclaim window, a day by default. A run still going
when the window runs out is handed out again and the task runs a second time, whether or not
the process that holds it is alive, so set `reclaimAfter()` longer than the slowest task. The
other end of the same rule: a claim made on another server is only ever taken back by the
window, because a process of another host cannot be asked about, so a server that dies mid-run
leaves its run waiting the whole window rather than being picked up at once.

## Documentation

- [Internals](docs/internals.md)

If you need help or have questions, [contact us](https://www.yiiframework.com/community/).

## License

The Yii Scheduler is free software. It is released under the terms of the BSD License.
Please see [`LICENSE`](./LICENSE.md) for more information.

Maintained by [Yii Software](https://www.yiiframework.com/).

## Support the project

[![Open Collective](https://img.shields.io/badge/Open%20Collective-sponsor-7eadf1?logo=open%20collective&logoColor=7eadf1&labelColor=555555)](https://opencollective.com/yiisoft)
