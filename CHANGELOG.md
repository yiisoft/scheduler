# Yii Scheduler Change Log

## 1.0.0 under development

- Bug #6: Fix a single failing task stopping `schedule:work` and terminating the other running tasks; the daemon now logs the failure and carries on (@samdark)
- Bug #9: Fix `Scheduler::run()` returning at once when called again after `stop()` (@samdark)
- Bug #10: Fix the schedule mutex staying locked when the persisted checkpoint cannot be read (@samdark)
- Docs: Document the multi-server setup: a shared mutex driver and a shared checkpoint cache let every application server run the schedule without duplicate runs or a single point of failure (@KalimeroMK)
- Bug #3: Fix `schedule:work` crashing on Windows, where `time_nanosleep()` is not available (@KalimeroMK)
- Bug #3: Fix a `SIGCHLD` arriving between the fork and the bookkeeping leaving a task process uncollected (@KalimeroMK)
- Bug #3: Reset the inherited signal handlers in a forked task, so it stops on `SIGTERM` instead of ignoring it and no longer reaps the subprocesses it starts itself (@KalimeroMK)
- Bug #3: Wait only on the scheduler's own child processes, leaving those of the host application to their owner (@KalimeroMK)
- Bug #3: Keep starting the remaining due tasks when one of them fails to start, instead of dropping their already checkpointed runs (@KalimeroMK)
- Bug #3: Keep a failing task from unwinding the `schedule:work` loop out of its `SIGCHLD` handler, stranding the other task processes (@KalimeroMK)
- Bug #3: Fix the shutdown of `schedule:work` hanging without `ext-posix` (@KalimeroMK)
- Bug #3: Skip PHP's shutdown sequence in a forked task, so it no longer runs destructors and shutdown functions over what it inherited from the scheduler (@KalimeroMK)
- New #3: Add a `--max-processes` option to `schedule:work`, capping how many task processes run at once (@KalimeroMK)
- Enh #3: Skip a due run of a task whose previous run is still going, instead of starting a second process for it (@KalimeroMK)
- Enh #3: Collect finished task processes during a long sleep as well, not only when a signal interrupts it (@KalimeroMK)
- New #2: Run due tasks of `schedule:work` concurrently, forking a child process per task when ext-pcntl is available, with a `--sequential` opt-out (@KalimeroMK)
- Enh #2: Sleep until the next scheduled run in `schedule:work` instead of polling every second (@KalimeroMK)
- New #1: Add scheduling core: triggers (cron, periodic, callback), schedules with mutex and stateful checkpoints, missed-run catch-up, and `schedule:run`, `schedule:work` and `schedule:list` commands (@KalimeroMK)
- Bug #1: Fix checkpoint position moving backwards when a re-discovered run is skipped (@KalimeroMK)
- Bug #1: Fix PHP 8.1 compatibility and falsable date arithmetic (@KalimeroMK)
- Initial release.
