# 17. Store job pay as posted

- **Status:** Accepted
- **Date:** 2026-10-24
- **Amended:** 2026-10-25 (keep stored pay, dollar sign by country)
- **Amends:** [0004](0004-compare-salaries-as-annual-gross-in-the-users-currency.md)

## Context

[ADR 0004](0004-compare-salaries-as-annual-gross-in-the-users-currency.md) compares salaries as annual gross pay in the user's currency. It assumed that postings are paid in IDR or USD, per month or per year, and the `job_postings` CHECKs allow only those values.

The first two job sources break that assumption. On one live page each:

- Jobicy sent EUR and USD. Himalayas sent USD, EUR and GBP, and other pages carry CAD, AUD and more.
- Himalayas sent hourly, weekly and annual pay. A search result for backend roles had "Upto $65/hr" in its title.
- Neither source always sends an employment type, and a few amounts come with no currency or no period.

Forcing these into IDR/USD and monthly/yearly would either drop the salary or store a converted number as if the employer had posted it. ADR 0004 already says that stored amounts stay exactly as posted, and that the import work relaxes `job_postings_salary_unit_check`.

## Decision

**Store each posting's pay in the currency and period the source sent, and store its annual figures beside it, from one versioned function.**

1. **Currency:** `job_postings.salary_currency` holds any ISO 4217 code (CHECK `~ '^[A-Z]{3}$'`). The `Currency` enum (IDR, USD) stays for the user's own floor on the profile.
2. **Period:** `SalaryPeriod` gains `hourly`, `daily`, `weekly` and `fortnightly`. The profile form and `profiles_salary_period_check` keep only `monthly` and `yearly`, because a user states a floor per month or per year.
3. **Unit rule:** an amount may have a null currency or a null period, as ADR 0004 said. A currency or a period without an amount is still rejected.
4. **Annual figures:** `annual_salary_min` and `annual_salary_max` hold the annual amount in the posting's own currency, rounded to whole units, computed from the exact source value:

    | Period      | Factor                                           |
    | ----------- | ------------------------------------------------ |
    | hourly      | 2,080 (40 hours × 52 weeks)                      |
    | daily       | 260                                              |
    | weekly      | 52                                               |
    | fortnightly | 26                                               |
    | monthly     | 12, or 13 for a job in Indonesia (THR, ADR 0004) |
    | yearly      | 1                                                |

    When the period is null, the annual figures are null, and ADR 0004's ambiguity bands decide at query time.

5. **Version:** `salary_normalization_version` records the version of the function that wrote the annual figures. A change to a factor bumps the version, and a backfill recomputes rows with an older version.
6. **Employment type:** `employment_type` becomes nullable. An unknown value is stored as null and logged as a dropped field, never guessed as full time.

Currency conversion still happens at query time with the daily rates of ADR 0004. Only the period is resolved at write time, because it does not change from day to day.

## Consequences

**Benefits**

1. No posting loses its salary because of its currency or period, and the stored amounts stay checkable against the source.
2. Salary filters compare annual figures, so hourly and monthly postings meet the same index-friendly filter as yearly ones.
3. A wrong factor is fixable: bump the version and recompute.

**Drawbacks**

1. 2,080 hours a year is a full-time assumption. An hourly contract with fewer hours shows a higher annual figure than it pays.
2. The exchange-rate table must hold every currency the sources send. A currency without a rate cannot be compared and shows as such (ADR 0004).
3. Two pairs of salary columns must stay in step. Only the normalizer writes them.

## Amendment (2026-10-25)

A review of the import found two ways to store the wrong pay.

1. **A later run without pay erased stored pay.** The import replaced every column on conflict, so a run where the source left out the salary wrote nulls over a salary the app already had. That is the field drift that `jobs:check-source` watches for ([ADR 0016](0016-fetch-job-sources-with-bounded-retries-and-dated-expiry.md)). Now:
    - The seven salary columns (amounts, currency, period, annual figures, version) change as one group. A run that sends no amount keeps the stored group. A run that sends any amount replaces the whole group, so an old minimum is never paired with a new maximum or a new currency.
    - `employment_type` and `seniority` keep their stored value when a run sends none.
    - An employer who removes the pay from a posting is not seen. The stored pay stays until the posting expires.
2. **A bare `$` was always USD.** Salary text such as "$80k" on a posting in Canada was stored as USD, and "US$" matched the `S$` of Singapore. Now:
    - A prefixed symbol names its currency: `US$`, `A$`/`AU$`, `C$`/`CA$`, `S$`, `NZ$`, `HK$`, `NT$`, `MX$`, `R$`.
    - A bare `$` follows the posting's single country: CAD, AUD, NZD, SGD, HKD or MXN in those countries. In a country whose local dollar has no exchange rate (Argentina, Chile, Colombia, …) the currency is null, never a guess. Anywhere else, or with no single country, it is USD.
    - An ISO code next to the amount still wins over any symbol.

The annual factors did not change, so `salary_normalization_version` stays at 1.

**Revisit when** a source sends hours per week, users ask to compare hourly rates directly, or a currency without an ECB rate becomes common.
