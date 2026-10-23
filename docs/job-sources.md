# Job sources

The app imports job postings only from job boards that publish an API or feed and whose terms allow this use. It never scrapes a site. [ADR 0015](adr/0015-take-jobs-only-from-sources-whose-terms-allow-it.md) records the decision.

Each source is one class that implements `App\JobSources\Contracts\JobSource` and one line in `config/job-sources.php`. The class yields `App\JobSources\JobPostingData` objects with the values the source sends. A normalizer maps them to the app's own enums and units before they are stored.

Terms change. Read them again before you add a source, change how postings are shown, or send postings anywhere new (an email, a feed, a public page).

## In use

### Himalayas

|           |                                                                                                                         |
| --------- | ----------------------------------------------------------------------------------------------------------------------- |
| Key       | `himalayas`                                                                                                             |
| Endpoint  | `GET https://himalayas.app/jobs/api/search?q=…&sort=recent&page=N` ([docs](https://himalayas.app/docs/remote-jobs-api)) |
| Jobs      | Remote only. `locationRestrictions` lists the countries a candidate must live in; empty means worldwide.                |
| Auth      | Optional free key in `HIMALAYAS_API_KEY`, sent as `Authorization: Bearer …`; it raises the limits.                      |
| Limits    | Keyed: 60 requests a minute and 10,000 a UTC day. Unkeyed requests share one public allowance.                          |
| Freshness | Data refreshes every 24 hours, so one pass a day is enough.                                                             |
| Pages     | 20 jobs a page, using page numbers. At most 5,000 results per query.                                                    |
| Run size  | Search each target role, newest first. Read `pages_per_query` pages (default 1), under the run deadline.                |
| Terms     | [API terms](https://himalayas.app/terms), clause 113, read on 2026-10-23 (marked current as of 2026-10-22).             |

Obligations:

1. Name Himalayas as the source, with a visible link to the job or to himalayas.app.
2. Every Apply action uses the job's `applicationLink`, never a direct employer or ATS link.
3. Show the jobs only in apps the owner runs. Do not pass them to other job boards, aggregators, job search engines, Google Jobs or LinkedIn Jobs, including through feeds or `JobPosting` structured data.
4. Remove jobs once they expire (`expiryDate`).
5. Keep the API key on the server. Never put it in browser code, URLs, source control or logs.

| `JobPostingData`  | Himalayas field                                                                                                                            |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| `externalId`      | `guid`                                                                                                                                     |
| `title`           | `title`                                                                                                                                    |
| `companyName`     | `companyName`                                                                                                                              |
| `applyUrl`        | `applicationLink`                                                                                                                          |
| `descriptionHtml` | `description` (sanitized HTML)                                                                                                             |
| `postedAt`        | `pubDate` (Unix seconds; the last update time)                                                                                             |
| `expiresAt`       | `expiryDate`, or `pubDate` + 30 days when it is missing                                                                                    |
| `location`        | `locationRestrictions`, joined                                                                                                             |
| `remoteType`      | always `remote`                                                                                                                            |
| `employmentType`  | `employmentType` (for example `Full Time`)                                                                                                 |
| `seniority`       | `seniority` (a list), joined                                                                                                               |
| `salaryMin`       | `minSalary`                                                                                                                                |
| `salaryMax`       | `maxSalary`                                                                                                                                |
| `salaryCurrency`  | `currency` (ISO 4217)                                                                                                                      |
| `salaryPeriod`    | `salaryPeriod` (hourly, weekly, fortnightly, monthly or annual); null when there is no salary, because Himalayas sends `annual` by default |

### Jobicy

|          |                                                                                                                                            |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Key      | `jobicy`                                                                                                                                   |
| Endpoint | `GET https://jobicy.com/api/jobs?count=50&tag=…` ([docs](https://jobicy.com/jobs-rss-feed))                                                |
| Jobs     | Remote only, from the last 7 days, newest first. `jobGeo` holds the region a candidate must live in.                                       |
| Auth     | None. The optional paid Commercial key returns ATS links at $0.01 a job; the app does not use it.                                          |
| Limits   | Start an automated pass at most once an hour. On 429, retry later.                                                                         |
| Pages    | 50 jobs a page here (up to 200 with `count`); pass `nextCursor` back as `cursor` while `hasMore` is true; a cursor expires after 24 hours. |
| Run size | Search each target role, read `pages_per_query` pages (default 1), under the run deadline.                                                 |
| Terms    | Usage terms on the [docs page](https://jobicy.com/jobs-rss-feed), read on 2026-10-23.                                                      |

Obligations:

1. Credit Jobicy as the source and keep the Jobicy job URL. Do not show the postings as the app's own.
2. Keep stored postings in sync with the source, so expired jobs do not stay listed.
3. Do not use the postings for spam or for services that harm employers or candidates.
4. Treat `jobDescription` as untrusted HTML and sanitize it before display.

| `JobPostingData`  | Jobicy field                                                                |
| ----------------- | --------------------------------------------------------------------------- |
| `externalId`      | `id`                                                                        |
| `title`           | `jobTitle`                                                                  |
| `companyName`     | `companyName`                                                               |
| `applyUrl`        | `url`                                                                       |
| `descriptionHtml` | `jobDescription`                                                            |
| `postedAt`        | `pubDate`                                                                   |
| `expiresAt`       | `pubDate` + 7 days (the feed covers 7 days)                                 |
| `location`        | `jobGeo` (spaces collapsed)                                                 |
| `remoteType`      | always `remote`                                                             |
| `employmentType`  | `jobType` (an array), joined                                                |
| `seniority`       | `jobLevel`                                                                  |
| `salaryMin`       | `salaryMin`                                                                 |
| `salaryMax`       | `salaryMax`                                                                 |
| `salaryCurrency`  | `salaryCurrency`                                                            |
| `salaryPeriod`    | `salaryPeriod` (for example yearly or hourly), null when there is no salary |

## Rejected

| Source           | Reason                                                                                                                                                                                                                                                 |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Remotive         | Its [API terms](https://github.com/remotive-io/remote-jobs-api) forbid showing its jobs to collect sign-ups or email addresses, and the app shows jobs only to signed-in users. It also asks for at most 4 requests a day and delays jobs by 24 hours. |
| LinkedIn, Indeed | No public jobs API for this use, and their terms forbid scraping. See ADR 0015.                                                                                                                                                                        |
| Arbeitnow        | Mostly on-site jobs in Europe. The app compares salaries only for Indonesian and remote jobs ([ADR 0004](adr/0004-compare-salaries-as-annual-gross-in-the-users-currency.md)).                                                                         |

## What every source must do

- Send a clear `User-Agent` that names the app and a contact URL.
- Stay inside the source's rate limits, and respect `Retry-After` on 429.
- Keep the source's apply link as `applyUrl`, so the Apply action meets the attribution rules.
- Never put postings in a public feed, sitemap or `JobPosting` structured data.
- Call the source through `SourceHttpClient`, which applies the retry and 429 rules of [ADR 0016](adr/0016-fetch-job-sources-with-bounded-retries-and-dated-expiry.md).
- Build each posting with `PostingBuilder`: skip a posting that cannot be used, and drop a bad optional field instead of the posting.
- Set `expiresAt` on every posting. Ingestion expires postings by this date, never because a run did not see them.
- Read a bounded number of pages per run.
- Search with the queries of `TargetRoleQueries`.
- Stop at the run deadline (`RunDeadline`) and report `stoppedEarly`.
- Yield each external ID at most once per run.
- Pass the shared contract test in `tests/Feature/JobSources/JobSourceContractTest.php`, and give the source `expected_fields` for `jobs:check-source`.
