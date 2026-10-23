# 15. Take jobs only from sources whose terms allow it

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

The app needs a steady supply of real job postings to match against user profiles. Postings come from job boards, and each board sets its own terms for automated access. Some publish an API or feed for other apps. Others forbid automated access completely.

Boards also differ in shape. They name fields differently, page differently, and state pay in different currencies and periods (EUR per hour, USD per year). The app's enums and database checks know only IDR and USD, and monthly and yearly pay ([ADR 0004](0004-compare-salaries-as-annual-gross-in-the-users-currency.md)).

Recruiters and users ask for the largest boards, such as LinkedIn. LinkedIn has no public jobs API for this use. Its user agreement forbids scraping, and it has taken scrapers to court. Its pages change often and block automated clients, so a scraper breaks without warning and needs constant repair.

## Decision

**The app imports jobs only through an API or feed whose published terms allow this use. It never scrapes. Every source sits behind one contract, `JobSource`, and yields postings with the source's own values.**

1. **Sources:** Himalayas and Jobicy. Both publish free JSON APIs for remote jobs and allow commercial apps to show them. Remotive is rejected, because its terms forbid showing jobs to collect sign-ups. `docs/job-sources.md` lists each source's terms, limits and field map, and the date the terms were read.
2. **Contract:** a source implements `key()` and `fetch(): iterable<JobPostingData>`. `fetch()` yields one page at a time, so a large source never sits in memory at once. A new source is one class and one line in `config/job-sources.php`; the app tags the classes and a registry finds them by key.
3. **Raw values:** `JobPostingData` keeps the source's values as plain strings and numbers: the currency as an ISO 4217 code, the period and the employment type in the source's own words. It checks only shape: required text is present, the apply URL is http or https, and the salary range is not negative or reversed. A normalizer maps the values to the app's enums before storage.
4. **Attribution:** the apply link a source requires is kept as `applyUrl`, and the app names the source on every posting it shows.
5. **No redistribution:** postings never go into a public feed, a sitemap or `JobPosting` structured data, because both sources forbid passing their jobs on to search engines and other boards.
6. **For a board without an allowed API,** the answer is a partnership or a paid feed, or the user pastes a posting they found themselves. LinkedIn, for example, offers job data only to approved partners.

## Consequences

**Benefits**

1. No legal exposure from scraping, and no scraper to repair when a site's markup changes.
2. One contract and one shared test for every source, so a new board is a small, reviewable change.
3. No source data is lost before the normalizer runs. A posting in EUR per hour arrives as it was posted, even though the app cannot compare it yet.

**Drawbacks**

1. Fewer postings than a scraper of the largest boards would give, and only remote jobs for now.
2. The terms bind the product: every Apply button must use the source's link, each posting must name its source, and postings cannot appear on public, indexable pages.
3. The DTO's strings are not type-checked against the app's enums, so a mistake in a source's mapping shows up in the normalizer, not in the source.
4. Salaries in EUR, GBP or hourly pay cannot be stored until the currency and period enums, their database checks and ADR 0004 are widened.

**Revisit when** a source changes its terms, the app adds public job pages or emails postings to users (check both sources' terms first), or a partner feed for a large board becomes available.
