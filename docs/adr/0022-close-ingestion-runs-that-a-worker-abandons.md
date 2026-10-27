# 22. Close ingestion runs that a worker abandons

- **Status:** Accepted
- **Date:** 2026-10-25

## Context

[ADR 0020](0020-ingest-job-sources-on-a-schedule.md) records every run of `IngestJobSource` in `ingestion_runs`. Two gaps remained:

1. **Stuck rows.** A worker killed by the timeout, a deploy or the operating system leaves a row at `running` that nothing closes. The dashboard would show a run that never ends.
2. **Rate limits used up the tries.** A rate limit calls `release()`, and a release counts as an attempt. With `$tries = 3`, three rate limits in a row failed the job. The job had no `failed()` method, so the last row said `rate_limited` and no row showed that the run gave up.

A separate sweeper, a scheduled job that closes old `running` rows, would fix the first gap, but it is one more process to schedule and watch.

## Decision

**A run closes the rows that earlier runs of its source left open. Rate limits retry within a time window, unexpected errors retry a fixed number of times, and every way the job can end leaves a closed row.**

1. **Self-healing:** `IngestJobSource` is unique per source, so only one run of a source is active at a time. When a run starts, any `running` row of its source comes from a killed worker. The run marks those rows `abandoned`, with a `finished_at`, before it creates its own row. This costs one `UPDATE` and no extra process.
2. **Retries:**
    - Rate limits release the job until `retryUntil()`, 55 minutes after the dispatch. The window ends before the 3600-second unique lock expires, with room for one more attempt.
    - Unexpected errors fail the job after 3 exceptions (`$maxExceptions`). A release is not an exception.
    - An unavailable source still fails the job at once ([ADR 0020](0020-ingest-job-sources-on-a-schedule.md), point 5).
    - A run that passes the 120-second timeout fails at once (`$failOnTimeout`). Its worst case is about 75 seconds, so another attempt would wait the same way; the next scheduled run tries again.
3. **`failed()`** closes the `running` row of the source as `failed` with the error. When no row is running and the job ran out of time or attempts, it writes a `failed` row, so the last row of a run that gave up says so.
4. **Backstop for the dashboard:** a source that never runs again keeps its last `running` row. The dashboard treats a `running` row older than the timeout plus a margin as failed.

This supersedes points 5 and 6 of ADR 0020 where they say "up to 3 tries" and that a stuck `running` row means the worker was killed, and replaces its third drawback.

## Consequences

**Benefits**

1. Run history closes itself without a sweeper, and the next run of the source does it.
2. A source that rate limits for a few minutes no longer fails the run.
3. Every run that gives up leaves a `failed` row, so the dashboard counts it.

**Drawbacks**

1. A stuck row stays `running` until the next run of its source, which is up to a day later. The dashboard backstop covers that gap.
2. `abandoned` hides why the worker died. The queue's failed-job record and the worker logs keep that.

**Revisit when** runs of one source can overlap, for example per-role runs in parallel. The self-healing rule then needs a run key, not only the source.
