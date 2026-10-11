# Proposal: yiisoft/scheduler — recurring tasks for Yii 3

> Draft for https://github.com/orgs/yiisoft/discussions (new package proposal)

## Motivation

Yii 3 has no way to define recurring tasks in PHP. Every Yii 3 application that needs cron-like behavior falls back to the system crontab plus hand-rolled logging, locking, and missed-run handling.

The demand is proven by the Yii 2 ecosystem:

- [omnilight/yii2-scheduling](https://github.com/omnilight/yii2-scheduling) — 316 stars, **1.16M downloads** (~18k/month)
- [yii2tech/crontab](https://github.com/yii2tech/crontab) — 184 stars, 287k downloads
- yiisoft/docs#235 (open) asks for a crontab recipe in the app templates
- yiisoft/yii-sentry#38 (by @samdark) sketched crontab monitoring — a scheduler with check-ins support closes that loop

Prior art outside Yii: Laravel's scheduler and Symfony's Scheduler component (stable since 6.4, heavily iterated through 8.2).

## Proposal

A new framework-agnostic package, **`yiisoft/scheduler`** (per the packages guide, it has no Yii-specific dependencies in its core), plus console integration through `yiisoft/yii-console` params.

### Core concepts (adapted from Symfony Scheduler, mapped onto Yii packages)

```php
interface TriggerInterface extends \Stringable
{
    /**
     * Returns the next run date strictly after $run. Null means the trigger is exhausted.
     */
    public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable;
}
```

The trigger as a **pure function** (`last run → next run`, strictly-after invariant) is the key design decision worth imitating: no mutable state, trivially testable, calendar bugs contained.

```php
final class Schedule
{
    public function task(RecurringTask $task, RecurringTask ...$tasks): static;
    public function lock(MutexInterface $mutex): static;        // yiisoft/mutex — multi-process safety
    public function stateful(CacheInterface $cache): static;    // PSR-16 — survive restarts, catch up missed runs
    public function processOnlyLastMissedRun(bool $onlyLast = true): static;
}

RecurringTask::cron('0 5 * * *', new GenerateDailyReport());
RecurringTask::every('10 minutes', new PurgeExpiredTokens());
RecurringTask::trigger($myTrigger, $message);
```

- `CronExpressionTrigger` builds on `dragonmantank/cron-expression` (the de-facto standard, 559M downloads)
- A due task either runs a handler directly or is **pushed into yiisoft/queue** (`QueueProducerInterface::push()`, with `DelayEnvelope` already available for delay semantics) — so heavy tasks run on queue workers, and the scheduler stays light
- Next-run ordering via a min-heap keyed by `[time, index]`; a `(time, index)` checkpoint gives crash-resume and missed-run catch-up without storing occurrences
- Optional PSR-14 events (`PreRunEvent` / `PostRunEvent` / `FailureEvent`) — this is where Sentry cron check-ins attach naturally
- PSR-20 clock everywhere, so tests can simulate days of runs

### Console commands (registered via `config/params.php`, zero app wiring)

```php
return [
    'yiisoft/yii-console' => [
        'commands' => [
            'schedule:run' => RunCommand::class,   // one tick — for classic system cron: * * * * * yii schedule:run
            'schedule:work' => WorkCommand::class, // daemon loop with 1s granularity
            'schedule:list' => ListCommand::class, // show tasks, triggers, next run dates
        ],
    ],
];
```

`schedule:run` keeps the familiar Yii 2 workflow (omnilight users migrate by changing one crontab line); `schedule:work` gives the modern single-process option.

### Config-first task definition (Yii style)

Tasks can be declared in `config/params.php` under `yiisoft/scheduler` or provided by a `ScheduleProviderInterface` service for dynamic (DB-driven) schedules — both feed the same `Schedule`.

## What the MVP includes / excludes

**In:** triggers (cron, periodic, callback), schedule collection, heap + checkpoint engine, direct handlers and queue push, mutex locking, PSR-16 statefulness, the three commands, PSR-14 events.

**Out (later or never):** hashed cron expressions (`#daily`), jitter/exclusion decorators, sub-second granularity (the loop ticks once per second, like Symfony).

## Notes

- I have working context from both sides: recent contributions to Symfony (incl. its Console) and to yiisoft (db, router, http-middleware, yii-sentry cron check-ins).
- If you prefer, I'm happy to build this as a community package first (per the yii3-symfony-messenger precedent in discussion #496) and transfer it to the yiisoft org once it proves itself. Either way I can start immediately.

Looking forward to your feedback — especially on naming (`yiisoft/scheduler`), the queue integration boundary, and whether the config-first task definition matches how you'd want apps to declare schedules.
