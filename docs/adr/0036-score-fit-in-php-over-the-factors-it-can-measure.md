# 36. Score fit in PHP over the factors it can measure

- **Status:** Accepted
- **Date:** 2026-11-12

## Context

The copilot needs a number for "how well does this job fit me?", and the user must be able to trust it and ask why. `FitExplainer` writes reasons with citations ([ADR 0034](0034-explain-fit-with-citations-checked-in-php.md)), but a model asked for a number gives a different one on each call and cannot say how it was made. The search's `match` is a rank, not a fit ([ADR 0031](0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md), drawback 4).

Much of the data is missing. Of 187 live postings, 93 have no salary and 84 have no nice-to-have skills. The user has no seniority field; the parsed resume gives years of experience.

## Decision

**A pure PHP scorer computes the fit score (0–100) from five factors with weights in `config/fit-scoring.php`. A factor whose data is missing on either side is unknown: it has no value and its weight is shared out over the known factors. The reasons come from `ExplainFit` and never change the number.**

1. **Factors** (each 0 to 1; weights in version `v1`):

    | Factor            | Weight | Value                                                                                                 | Unknown when                                 |
    | ----------------- | ------ | ----------------------------------------------------------------------------------------------------- | -------------------------------------------- |
    | `required_skills` | 40     | matched required skills ÷ required skills                                                             | the posting has no required skills           |
    | `nice_skills`     | 10     | matched nice-to-have skills ÷ nice-to-have skills                                                     | the posting has none                         |
    | `seniority`       | 20     | resume years ÷ years needed, capped at 1. Years needed: `years_required`, else the level (0, 2, 5, 8) | no parsed resume, or no years and no level   |
    | `salary`          | 15     | 1 at or above the floor, 0 below 80% of it, linear between                                            | no profile floor, no posting pay, or no rate |
    | `location`        | 15     | 0 when the arrangement is not one the user wants, else 1 open, 0.5 unclear, 0 restricted or excluded  | never                                        |

2. **Score:** `round(100 × Σ(weight × value) ÷ Σ(known weights))`. A posting with no salary is scored on the other four factors, not marked down for hiding its pay.
3. **Inputs:** skills match by name, without case or surrounding spaces. The salary floor is converted to an annual amount in the posting's currency with the ECB rates, as the floor filter does ([ADR 0004](0004-compare-salaries-as-annual-gross-in-the-users-currency.md)), but without the filter's 5% margin. Eligibility is read with the same rules as the jobs list ([ADR 0032](0032-rank-the-whole-filtered-set-and-label-text-based-restrictions.md)).
4. **Storage:** `job_matches` keeps one row per user and posting, with the score, the breakdown (value, weight and detail per factor), the inputs, the matched and missing skills, `scoring_version`, `profile_version`, `resume_id` and up to 5 reason texts. The row alone explains the score.
5. **Versioning:** `fit-scoring.version` changes with any weight or rule, so a stored score shows which rules made it.
6. **Copilot tool:** `ScoreFit(job_id)` returns the score, the breakdown as 0–100 per factor (null when unknown), the skills and the reasons. It refuses a fourth call in one answer.

## Consequences

**Benefits**

1. The same inputs always give the same score. Scoring all 187 live postings twice gave 0 differences.
2. Every score can be recomputed by hand from its breakdown, and a complaint can be checked against the stored inputs without a model call.
3. A missing salary or skill list does not push a posting down or up.

**Drawbacks**

1. **Few known factors make a confident number.** A posting with only required skills and location known can score 100 on two factors. The breakdown shows the unknowns, but the number alone does not.
2. **Skills match by exact name.** "Postgres" does not match "PostgreSQL" unless both are normalised to the same skill.
3. **Years are not relevant years.** Eight years of any experience count fully for a senior Go role.
4. **No overqualification.** A lead applying to a junior role scores 1 on seniority.
5. **The posting's own pay is used.** The search shows the pay of the opening's pay posting (ADR 0025); when the representative posting has no pay but a sibling has, the score treats salary as unknown.
6. **The weights are a guess.** They are not tuned against user judgements.

**Alternatives considered**

1. **Ask the model for the number.** Rejected: not reproducible, not explainable, and it costs a call per score.
2. **Half credit for an unknown factor.** Rejected: two postings with the same skills would differ only because one hides its pay.
3. **Zero credit for an unknown factor.** Rejected: it punishes the half of postings that omit pay.
