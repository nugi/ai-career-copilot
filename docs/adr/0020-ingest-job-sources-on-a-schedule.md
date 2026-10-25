# 20. Ingest job sources on a schedule

- **Status:** Accepted; partly superseded by [0021](0021-key-ai-work-on-the-posting-text.md) (point 3: what the hashes cover) and [0022](0022-close-ingestion-runs-that-a-worker-abandons.md) (points 5 and 6: retries and stuck runs)
- **Date:** 2026-10-25

## Context

`ImportJobPostings` imports one source run ([ADR 0018](0018-link-duplicate-jobs-across-sources.md)), but only when someone calls it. New jobs must arrive without a person, and a scheduler runs the same work again and again: after a deploy, on a second server, after a worker restart, or when a run is retried. Every one of those repeats must be harmless.

Before this change a repeated import created no rows, but it still rewrote every row: `updated_at` moved on each run, so "what changed" could not be told from the data. The import also reported one `stored` count, so a run that found 100 new jobs and a run that found none looked the same. The time a posting was first seen existed only by accident: Laravel's upsert leaves `created_at` alone on conflict.

## Decision

**A daily schedule queues one ingestion run per source. A run with the same source data creates no rows, changes no content and no links. Only `last_seen_at` moves.**

1. **Schedule:** `jobs:ingest` runs daily at 06:30, after the 06:00 drift check, with `withoutOverlapping()` and `onOneServer()`. Himalayas refreshes once a day, and Jobicy allows at most one run an hour ([docs/job-sources.md](../job-sources.md)). `jobs:ingest {source}` queues one source by hand.
2. **One queued job per source:** `IngestJobSource` runs on `ai-batch` with a 120-second timeout, because the worst run is the run deadline plus one attempt, about 75 seconds ([ADR 0016](0016-fetch-job-sources-with-bounded-retries-and-dated-expiry.md)). It is unique per source for one hour, so a second dispatch while a run waits or retries is dropped.
3. **Seen times and content:**
    - `first_seen_at` is set on insert and never updated. Ranking by age uses it.
    - `last_seen_at` is set on every run that returns the posting.
    - `content_hash` is the SHA-256 of the normalized row. `updated_at` moves only when the hash changes.
4. **Counts:** before each chunk is written, one query reads the stored hashes of its keys. A row is `created` (no stored key), `updated` (a different hash) or `unchanged` (the same hash).
5. **Failures:**
    - A rate limit releases the job after the source's `Retry-After` and records the run as `rate_limited`.
    - An unavailable source fails the job at once. The HTTP client has already retried, so a job retry gets the same answer; the next scheduled run tries again.
    - Any other error records a failed run and lets the queue retry it, up to 3 tries.
6. **Run history:** every run writes an `ingestion_runs` row: status, start and finish, pages, fetched, skipped, dropped fields, created, updated, unchanged, links changed, `stopped_early`, duration, and the error class and message. A run summary is also logged as `jobs.ingest.finished`. A row still `running` after the timeout means the worker was killed.
7. **No expiry by absence:** a posting is never expired because a run did not return it ([ADR 0016](0016-fetch-job-sources-with-bounded-retries-and-dated-expiry.md), point 4). A run searches the target roles of users, a few hundred postings, so most stored postings are absent from any one run while still open. `last_seen_at` records the data to revisit this.

## Consequences

**Benefits**

1. A repeated, overlapping or retried run is harmless, and its counts show that it changed nothing.
2. `updated_at` and `content_hash` mark the rows whose content changed, so later work (skill extraction, embeddings) can skip unchanged postings.
3. Ingestion health is a query on `ingestion_runs`, not a log search.

**Drawbacks**

1. Every run writes `last_seen_at` on every returned row, so a run with no changes still writes rows. At a few hundred rows a day this costs little.
2. The hash covers the normalized row, so a change to a normalization rule counts every affected row as updated on the next run.
3. A worker killed by the timeout leaves a `running` row that nothing closes.

**Revisit when** a source needs a schedule other than daily, runs grow beyond one queue timeout, or `last_seen_at` shows that absence predicts closing well enough to expire on it.
