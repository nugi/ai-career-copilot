# 16. Fetch job sources with bounded retries and dated expiry

- **Status:** Accepted
- **Date:** 2026-10-23
- **Amended:** 2026-10-24 (run deadline, search by target roles, daily drift check)

## Context

[ADR 0015](0015-take-jobs-only-from-sources-whose-terms-allow-it.md) puts every job board behind one `JobSource` contract. The first real source, Himalayas, shows what each source call has to handle:

- **Size:** the feed holds about 123,000 jobs at 20 a page, so a full read is about 6,000 requests. Requests without a key share one public allowance with every other caller.
- **Failures:** a source can time out, fail with a 5xx, or answer 429 with a `Retry-After` header. A run on a queue worker must not sleep for minutes, and must not retry a request that can never succeed.
- **Bad data:** one posting can lack a title, or have a minimum salary above its maximum. The `JobPostingData` constructor throws on both, so inside a generator one bad posting would end the whole run, and a typo in a salary would lose an otherwise good job.
- **Expiry:** a capped run sees only the newest pages, and Jobicy returns only the last 7 days. A posting missing from a run is not proof that it closed.
- **Dates:** Himalayas' `pubDate` is the time of the last update, not of the first posting.

## Decision

**A source reads a bounded number of pages through one shared HTTP client, skips postings it cannot use, keeps postings with one bad field, and gives every posting an expiry date.**

1. **Bounded runs:** each source has a page cap in `config/job-sources.php`. Himalayas reads 5 pages (100 jobs) a run. A run also stops at an empty page, at the last page, or at a cursor it has already seen.
2. **One HTTP client:** `SourceHttpClient` sends a User-Agent that names the app and its URL, with a 10-second timeout and a 5-second connect timeout. It tries a request at most 3 times:
    - A connection error or a 5xx waits 1 second, then 2 seconds, plus up to 250 ms of jitter.
    - A 429 waits for `Retry-After` when it is 30 seconds or less. A longer wait throws `JobSourceRateLimited` with the seconds, so a queued run can release itself and try later instead of holding a worker.
    - Any other 4xx fails at once with `JobSourceUnavailable`, because a retry gets the same answer.
3. **Postings vs fields:** a source builds each posting through `PostingBuilder`.
    - A posting without an ID, a title, a company, a valid http(s) apply link or a valid date is skipped and logged as `jobs.source.posting_skipped`.
    - A bad optional field (a negative salary, a minimum above the maximum, a currency that is not three capital letters) is dropped, the posting is kept, and a warning `jobs.source.field_dropped` is logged.
    - `fetch()` returns a `FetchReport` (pages, yielded, skipped, dropped fields) as the generator's return value, for the run summary.
4. **Expiry by date only:** every posting carries an `expiresAt` set by its source. Himalayas uses `expiryDate`, or the last update plus 30 days when it is missing. Jobicy uses `pubDate` plus 7 days. Ingestion expires postings by this date and never because a run did not see them.
5. **Dates:** `postedAt` is the date the source sends, which for Himalayas is the last update. Ranking by age uses the time the app first saw a posting, stored as `first_seen_at` ([ADR 0020](0020-ingest-job-sources-on-a-schedule.md)).
6. **Tests:** tests never call a source (`Http::preventStrayRequests()`). Fixtures start from a real response, cut to a few jobs, with the company, title and description text replaced, because the source terms forbid passing jobs on and the repository is public. A daily `jobs:check-source` run against the live API catches the field changes that fixtures cannot.

## Consequences

**Benefits**

1. A run has a known maximum cost in requests, and stays well inside the public allowance.
2. A slow or rate-limited source never ties up a queue worker for longer than the run deadline plus one attempt, about 75 seconds (see the amendment).
3. One bad posting costs one posting, and one bad field costs one field. The counts show in every run summary.
4. Postings expire on a date the user can see, and a short or capped feed never removes open jobs.

**Drawbacks**

1. A capped run does not see every job in a large feed. Jobs updated during a run can move ahead of the cursor and be missed until the next run.
2. A dropped field is lost until the source fixes it. Only the warning log shows it.
3. The 30-day fallback expiry for Himalayas is a guess. A job can be listed after it closed, or hidden while it is still open.
4. Anonymized fixtures can drift from the live shape; the daily check is the guard.

## Amendment (2026-10-24)

A review of the first source changed what a bounded run means.

1. **The page cap did not bound time.** One request can try 3 times, each up to 15 seconds (10 seconds to answer, 5 to connect), with up to two 30-second `Retry-After` waits between them: about 105 seconds, not 45. Five pages could take several minutes. Each run now has a deadline, `job-sources.max_run_seconds` (60 by default):
    - A source starts no new request after the deadline. It ends the run and reports `stoppedEarly` in its `FetchReport`.
    - `SourceHttpClient` starts a retry only when the wait and one more attempt end before the deadline. Otherwise it throws as if the tries were used up: `JobSourceRateLimited` for a 429, `JobSourceUnavailable` for the rest.
    - The worst run is the deadline plus one attempt, about 75 seconds. The queued ingestion job (Day 14) gets a 120-second timeout.
2. **Search by what users want, not the newest of every job.** Five pages of the Himalayas browse feed gave about 100 jobs a day across every category, about a quarter of them tech roles, and matching cannot rank a job the app never fetched. A run now searches each source with queries built from the target roles on user profiles (`TargetRoleQueries`): the most common roles first, at most `job-sources.max_queries` (10), cleaned to letters, digits and spaces, at most 60 characters each. With no profiles, it uses `job-sources.default_queries`. Role titles are not personal data, so they may go to the source.
    - Himalayas: `/jobs/api/search?q=…&sort=recent&page=N`, `pages_per_query` pages per query (1 by default). Search pages by page number, not cursor. The pages can overlap, which is harmless because postings are unique by ID.
    - A source yields each external ID at most once per run, because two queries can return the same job.
    - A bounded run is now queries × pages per query, under the deadline.
3. **The drift check runs daily and alerts on a repeat.** The check ran hourly although Himalayas refreshes once a day, so a page that failed a threshold failed all day. With 20 postings, a 50% threshold and a real fill rate of 60%, the check also failed about 13% of the time by chance. Now:
    - `jobs:check-source` runs once a day, after the feed refresh, and reads `check_pages` pages (3 by default) for a larger sample.
    - A field under its threshold fails the check only when it was also under it in the previous run. The first miss logs `jobs.source.schema_drift_suspected` and passes. No postings, too many skipped postings, or an unavailable source still fail at once.
    - Every run logs `jobs.source.field_shares`. After a week of runs, the thresholds are set from that data.
    - A drift alert can now take up to two days. The per-run summary of ingestion is the faster second net.

**Revisit when** a source offers an "updated since" filter, a paid key raises the limits enough to read a full feed, or the share of dropped fields in a run summary grows.
