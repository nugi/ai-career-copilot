# 4. Compare salaries as annual gross in the user's currency

- **Status:** Accepted
- **Date:** 2026-10-14
- **Amended:** 2026-10-19 (±5% tolerance band)

## Context

A user sets a minimum salary such as "Rp20 juta per month". Postings state pay in a different shape, such as "USD 40k–60k per year". To filter and score jobs fairly, the product must compare amounts that differ in four ways:

1. **Currency:** IDR or USD, and the exchange rate moves daily.
2. **Period:** monthly or yearly. In Indonesia, employees also receive THR, a religious holiday allowance of one month's salary required by law, so a year of salary is 13 monthly payments, not 12.
3. **Gross or net:** postings rarely say. Tax and BPJS differ between Indonesian employees and contractors paid from abroad.
4. **Cost of living:** USD 50k per year is a modest salary in San Francisco and a very high one in Yogyakarta.

Some postings also leave out the salary, the period or one end of the range.

Stored amounts stay exactly as posted (see `docs/erd.md`). The questions are how to compare them and what to show.

## Decision

Compare **annual gross cash in the user's currency**, and show amounts in the unit the user chose.

1. **Annualize** each side before converting. A monthly amount for a job in Indonesia (or the user's own monthly IDR floor) counts 13 times, to include THR. Every other monthly amount counts 12 times. A yearly amount stays as it is. Rp20 juta per month is therefore Rp260 juta per year.
2. **Convert** with the ECB daily reference rates, which include IDR and USD, cross-rated through EUR. A daily scheduled job stores them in an `exchange_rates` table (date, currency, rate). The product uses the latest stored rate and always shows its date: "≈ Rp54–81 juta per month at Rp16,300 per USD (13 Oct)". If the rate is more than seven days old, the label says so. Bank Indonesia's JISDOR is the local reference rate but has no stable public API; the two rates differ by much less than salary ranges do.
3. **Assume gross on both sides.** The form labels the floor "gross, before tax". The product does not estimate net pay, because PPh 21, BPJS and contractor tax depend on facts the product does not have. A contract or freelance posting gets a note: "no employer benefits or BPJS; you pay your own tax".
4. **Do not adjust for cost of living.** The user's floor already reflects where they live, and a remote job pays them where they live. Postings are Indonesian or remote, so relocation is out of scope.
5. **Classify each job against the floor** with three states instead of a yes or no, using a ±5% tolerance band around the floor:
    - **Meets:** the bottom of the range is at least 105% of the floor.
    - **Possible:** the job could pay the floor, but its range does not show that it does, or an end of the range is within 5% of the floor.
    - **Below:** the top of the range is under 95% of the floor.

    A missing end is never filled in from the known end. A missing top ("From Rp15 juta") is unbounded, so the job is Meets when the bottom is at least 105% of the floor and Possible otherwise, never Below. A missing bottom ("Up to USD 60k") is unknown, so the job is Possible when the top is at least 95% of the floor and Below otherwise, never Meets.

    The band keeps a job's state stable. An exchange rate can move 1% in a day, so without it a job paying close to the floor could change between Meets and Below from one day to the next. That looks like a bug and adds noise to alerts and digests. The band is wider than a normal daily move and much narrower than a typical salary range.

6. **Keep the filter indexable.** Convert 95% of the user's floor once per search into each currency and period. The filter removes only jobs that are certainly Below, so it also keeps every row whose top or unit is missing:

    ```sql
    (salary_currency = 'USD' and salary_period = 'yearly' and (salary_max >= ? or salary_max is null))
    or …                                            -- one branch for each currency and period
    or (salary_currency = 'IDR' and salary_period is null and (salary_max >= ? or salary_max is null))
    or …                                            -- period unknown: see below
    or salary_currency is null                      -- no salary, or a currency that cannot be compared
    ```

    Each branch uses the `(salary_currency, salary_max)` index, including the `is null` checks, and no stored amount is converted row by row. When the user hides jobs without a salary, the last branch is dropped and every other branch gets `and (salary_min is not null or salary_max is not null)`.

When data is missing:

| Missing              | Behavior                                                                                                                                                                                                                                                                                                                                                                                              | What the user sees                                 |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| Salary               | Kept in results by default and neutral in the fit score. The user can hide these jobs with a filter.                                                                                                                                                                                                                                                                                                  | "Salary not listed"                                |
| One end of the range | A missing top is unbounded and a missing bottom is unknown (rule 5). The job stays in the filter.                                                                                                                                                                                                                                                                                                     | "From Rp15 juta" or "Up to USD 60k"                |
| Period               | Read from size only outside an ambiguity band, where both readings are plausible: Rp60–200 juta and USD 10k–30k. Under the band means monthly and over it means yearly. Inside the band, the job is classified under both readings: if both give the same state, that state is used, otherwise it is Possible. The filter compares the monthly reading inside the band, because it is the higher one. | "≈ … (period estimated)" or "≈ … (period unknown)" |
| Currency             | Inferred from the country (`ID` means IDR). Without a country, no comparison is made.                                                                                                                                                                                                                                                                                                                 | "Can't compare: currency not listed"               |
| User's floor         | Nothing is filtered by salary, and salary has no weight in the fit score.                                                                                                                                                                                                                                                                                                                             | Amounts in the posting's own unit                  |

The bands come from the overlap of real salaries. Indonesian postings quote yearly pay almost only for professional roles, and those start above Rp60 juta, so anything smaller is monthly. Rp200 juta a month is far above any role the product lists, so anything larger is yearly. Between them sit junior yearly salaries (Rp120 juta) and senior monthly ones (Rp80 juta). USD has the same overlap: remote contracts pay USD 12k a month, and junior remote roles pay USD 25k a year.

Today `job_postings_salary_unit_check` requires a currency and a period whenever an amount is stored, so postings with a missing period, or a missing currency and no country, cannot be stored yet. The import work relaxes that CHECK to allow `null` in both columns, together with the tests that assert it, because an inferred period stored as if it were posted would hide the "estimated" and "unknown" labels.

## Consequences

**Benefits**

1. One rule for every combination, with the THR difference handled explicitly instead of hidden in a monthly × 12.
2. Users see the converted amount, the rate and its date, so they can check the comparison.
3. Salary filters stay fast at scale, because conversion happens once per query and not once per row.
4. Missing data never removes a job: the filter drops only jobs that are certainly below the floor, and every estimate is labelled.

**Drawbacks**

1. A daily exchange-rate job and table to run. A stale rate shifts results near the floor.
2. Gross-only comparison overstates contract pay compared with employment, because the contractor pays tax and benefits. The note mitigates this but does not quantify it.
3. Outside the ambiguity bands the period is still a guess, and it is wrong for the rare yearly salary under Rp60 juta or monthly salary over Rp200 juta. Inside the bands, more jobs are Possible instead of a definite Meets or Below.
4. Jobs with a missing top or period stay in filtered results, so a user with a high floor sees more Possible jobs.
5. The tolerance band turns jobs within 5% of the floor into Possible, including some that would pay it. A job just under the floor also stays in filtered results.

**Revisit when** postings include roles outside Indonesia that are not remote (cost of living starts to matter), when users ask to compare take-home pay, or when the gap between the ECB cross-rate and JISDOR moves "meets" or "below" for a noticeable share of jobs.
