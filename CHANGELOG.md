# Yii Schedule Change Log

## 1.0.0 under development

This release is a breaking rewrite of the scheduling contracts, CLI, local state, and
execution model. It is not a drop-in update for the previous API or runtime.

### Delivered

- Add the standalone `bin/scheduler` planner default, `run` worker mode, and read-only
  `healthcheck` mode with strict state-directory precedence and explicit failure.
- Define source-scoped local filesystem state, one local owner per source identity, and a
  separate raw pinned configuration revision. Version changes fail closed.
- Make async execution the default, with explicit local sync, literal shell `argv`, fresh
  worker bootstrap/resolver boundaries, allow-overlap defaults, and per-source capacity 16.
- Add fenced admission, lifecycle supervision, recovery, unresolved/ambiguous outcomes,
  and application-owned retention with no automatic deletion.
- Add cursor-based missed-occurrence handling: `skip` is the default, `last` requires
  history, and bounded `all` replay retains the newest missed occurrences.
- Define the 15-second health threshold, its synchronous blocking limitation, and stable
  worker exit classes (`0` success, `1` execution failure, `2` argument/protocol/catch
  failure, `3` unresolved or ambiguous completion).
- Keep framework-neutral application bootstrap and service resolution application-owned;
  no framework adapter or queue/distributed backend is implied.
- Remove the deprecated `MAX_AGE` compatibility constant in favor of `DEFAULT_MAX_AGE` or an
  explicit probe threshold.

### Explicit non-goals

Status/history commands, `--once`, detach mode, framework adapters, queue integration,
distributed backends, legacy restoration, and destructive retention cleanup are not part
of this contract.

### Release validation status

The documentation contract is recorded here. This entry does not claim that release,
packaging, static-analysis, mutation, or full end-to-end gates have been run.
