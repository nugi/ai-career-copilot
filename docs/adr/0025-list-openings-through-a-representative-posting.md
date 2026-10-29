# 25. List openings through a representative posting

- **Status:** Accepted
- **Date:** 2026-10-27
- **Amended:** 2026-10-29 (order by the time the app first saw the opening)

## Context

The jobs list shows one row per opening ([ADR 0019](0019-give-each-job-a-stable-identity.md), [ADR 0023](0023-name-the-job-identity-openings.md)). An opening can have live postings on several boards, and they disagree: one board leaves out the pay, another names the countries, a third was posted later. ADR 0019 chose the presentation rule (point 5) but not how a list query applies it, and which posting each filter reads.

The list filters by work arrangement, the country the user lives in, a skill, and the user's salary floor. Each filter must give the same answer that the row shows, so a row never says "Canada only" in a list filtered to Indonesia, or "Rp10 juta" in a list that meets a Rp20 juta floor.

## Decision

**Each list query chooses two postings per opening with window functions: the representative posting, which every filter and the row read, and the pay posting, which the floor filter and the pay read. Pages use a cursor.**

1. **Representative posting:** the live posting of the most preferred source, then the newest, then the highest ID. The source order is the key order of `JobSourceRegistry`. A source that the registry does not hold, such as `manual`, comes last.
2. **Pay posting:** the first live posting in the same order that has an amount. When the representative has pay, it is the pay posting. Both choices are `row_number()` windows over the live postings, in one subquery that the list joins twice.
3. **Chosen at read time, not stored.** An ingestion run, an expiry or a merge changes the choice with no extra write, and no stored ID can point at an expired posting. At the current size (335 live postings) the subquery runs in about 1.3 ms.
4. **Filters:**
    - **Arrangement** is any of remote, hybrid and on-site. None selected means all.
    - **Country** is where the user lives. A posting matches when its country is that country, its list of countries includes it, or it is remote with no list of countries and is open worldwide or to a region that includes the country. Regions follow the UN M49 lists in `JobRegion::includes()`.
    - **Skill** is one verified skill, required or nice to have.
    - **Meets my minimum** applies ADR 0004, point 6, to the pay posting: the annual floor is converted once per search into each currency that has a rate, and a row is removed only when its stored annual top ([ADR 0017](0017-store-job-pay-as-posted.md)) is under 95% of it. A row with no pay, no currency, no rate, no period or no top stays.
5. **Converted pay** is the annual pay in the floor's currency, divided by the floor's own annual factor: 13 for a monthly IDR floor, 12 for another monthly floor, 1 for a yearly floor. The row shows it with the rate and its date, and says when the rate is stale.
6. **Cursor pages:** 20 rows a page, ordered by the opening's `created_at`, then the posting ID, both descending. `created_at` is when the app first saw the opening: a merge keeps the lowest opening ID, so it keeps the earliest time. `posted_at` does not order the list, because for Himalayas it is the last update ([ADR 0016](0016-fetch-job-sources-with-bounded-retries-and-dated-expiry.md), point 5), and an edited posting would move back to the top. Inside one opening, `posted_at` still breaks the tie between two postings of the same source, because it only chooses which posting to show. The page loads the skills of its 20 rows with one query, so the query count does not change with the number of rows. Lazy loading throws outside production.

## Consequences

**Benefits**

1. A row and the filters always read the same posting, so a filtered list never shows a row that the filter would reject.
2. An opening keeps its pay when the preferred board leaves it out.
3. A scheduled ingestion run between two scrolls does not repeat or skip rows. With offset pages, a new posting at the top moves every row one place down, so the next page starts with the last row of the previous one. Infinite scroll shows that as a duplicate card.

**Drawbacks**

1. The windows read every live posting on each request, whatever the filters. The cost grows with the number of live postings, not with the page size.
2. The pay can come from another board than the title and the apply link. The row does not say which board posted the pay.
3. A cursor gives no page numbers and no total. The order columns must stay unique together, so the ID is always the last one.
4. A monthly IDR figure is 12/13 of a plain monthly conversion, because the floor includes THR. A user who compares with a ×12 calculation sees a lower number.
5. The country filter trusts the posting's list of countries. A remote posting that names no country and no region counts as worldwide, also when its text says otherwise.

**Alternatives considered**

1. **Store the representative and pay posting IDs on `openings`:** an indexed join instead of two windows, but every ingestion, expiry and merge must update them, and a missed update shows an expired posting. Rejected while the windows are cheap.
2. **Offset pages:** page numbers and a total, but repeated or skipped rows when ingestion adds postings, and a cost that grows with the page number. Rejected for infinite scroll.

**Revisit when** live postings reach about 20,000, or the list query takes more than 100 ms at the 95th percentile. Then store the two IDs on `openings` (alternative 1).
