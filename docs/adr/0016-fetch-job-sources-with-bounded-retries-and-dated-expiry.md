# 16. Fetch job sources with bounded retries and dated expiry

- **Status:** Accepted
- **Date:** 2026-10-23

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
5. **Dates:** `postedAt` is the date the source sends, which for Himalayas is the last update. Ranking by age uses the time the app first saw a posting.
6. **Tests:** tests never call a source (`Http::preventStrayRequests()`). Fixtures start from a real response, cut to a few jobs, with the company, title and description text replaced, because the source terms forbid passing jobs on and the repository is public. A daily `jobs:check-source` run against the live API catches the field changes that fixtures cannot.

## Consequences

**Benefits**

1. A run has a known maximum cost in requests, and stays well inside the public allowance.
2. A slow or rate-limited source never ties up a queue worker for longer than about 45 seconds.
3. One bad posting costs one posting, and one bad field costs one field. The counts show in every run summary.
4. Postings expire on a date the user can see, and a short or capped feed never removes open jobs.

**Drawbacks**

1. A capped run does not see every job in a large feed. Jobs updated during a run can move ahead of the cursor and be missed until the next run.
2. A dropped field is lost until the source fixes it. Only the warning log shows it.
3. The 30-day fallback expiry for Himalayas is a guess. A job can be listed after it closed, or hidden while it is still open.
4. Anonymized fixtures can drift from the live shape; the daily check is the guard.

**Revisit when** a source offers an "updated since" filter, a paid key raises the limits enough to read a full feed, or the share of dropped fields in a run summary grows.
