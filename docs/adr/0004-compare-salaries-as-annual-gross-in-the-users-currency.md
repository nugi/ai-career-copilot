# 4. Compare salaries as annual gross in the user's currency

- **Status:** Accepted
- **Date:** 2026-10-14

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
5. **Classify each job against the floor** with three states instead of a yes or no:
    - **Meets:** the bottom of the range is at or above the floor.
    - **Possible:** only the top of the range reaches the floor.
    - **Below:** the top of the range is under the floor.
6. **Keep the filter indexable.** Convert the user's floor once per search into each currency and period, then filter with `(salary_currency = 'USD' and salary_period = 'yearly' and salary_max >= ?) or …`. Each branch uses the `(salary_currency, salary_max)` index, and no stored amount is converted row by row.

When data is missing:

| Missing              | Behavior                                                                                             | What the user sees                   |
| -------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------ |
| Salary               | Kept in results by default and neutral in the fit score. The user can hide these jobs with a filter. | "Salary not listed"                  |
| One end of the range | The known end is used for both ends.                                                                 | "From Rp15 juta" or "Up to USD 60k"  |
| Period               | Inferred from size: under Rp200 juta or under USD 10k means monthly, otherwise yearly.               | "≈ … (period estimated)"             |
| Currency             | Inferred from the country (`ID` means IDR). Without a country, no comparison is made.                | "Can't compare: currency not listed" |
| User's floor         | Nothing is filtered by salary, and salary has no weight in the fit score.                            | Amounts in the posting's own unit    |

## Consequences

**Benefits**

1. One rule for every combination, with the THR difference handled explicitly instead of hidden in a monthly × 12.
2. Users see the converted amount, the rate and its date, so they can check the comparison.
3. Salary filters stay fast at scale, because conversion happens once per query and not once per row.
4. Missing data never removes a job silently, and every estimate is labelled.

**Drawbacks**

1. A daily exchange-rate job and table to run. A stale rate shifts results near the floor.
2. Gross-only comparison overstates contract pay compared with employment, because the contractor pays tax and benefits. The note mitigates this but does not quantify it.
3. The period inference is a guess and will sometimes be wrong for very high or very low salaries.

**Revisit when** postings include roles outside Indonesia that are not remote (cost of living starts to matter), when users ask to compare take-home pay, or when the gap between the ECB cross-rate and JISDOR moves "meets" or "below" for a noticeable share of jobs.
