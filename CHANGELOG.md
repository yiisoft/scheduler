# Yii Scheduler Change Log

## 1.0.0 under development

- Enh #25: Make the core usable without a framework: `Schedule::lock()` takes a `LockInterface` of this package rather than a yiisoft/mutex one, with `MutexLock` adapting a mutex for it; `symfony/console` moves out of the package's dependencies and into its suggestions, and the handling of a console command task moves out of `CallableTaskHandler` into a `ConsoleTaskHandler` in front of it. A handler now passes on what it does not recognise, so the bundled configuration builds a chain from whatever is installed, and a schedule of callables runs with neither a console application nor a queue producer. Removed the unused `ScheduleProviderInterface` (@KalimeroMK)
- Initial release.
