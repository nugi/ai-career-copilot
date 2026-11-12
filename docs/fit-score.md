# Fit score

The fit score says how well one job fits one user, from 0 to 100. PHP computes it from five factors; the model never produces the number. The decision and the rules are in [ADR 0036](adr/0036-score-fit-in-php-over-the-factors-it-can-measure.md).

| Part                | Where                                                                  |
| ------------------- | ---------------------------------------------------------------------- |
| Weights and rules   | `config/fit-scoring.php` (`version`, `weights`, thresholds)            |
| Scorer (pure)       | `App\Actions\Fit\FitScorer`                                            |
| Inputs and storage  | `App\Actions\Fit\ScoreJob` → `job_matches`                             |
| Copilot tool        | `App\Ai\Tools\ScoreFit`                                                |
| Reasons (not score) | `App\Actions\Fit\ExplainFit` ([fit explanations](fit-explanations.md)) |

## What a row stores

`job_matches` keeps, for each user and posting:

- `score` and `scoring_version`: the number and the rules that made it.
- `breakdown`: for each factor, the `value` (0–1, or null when unknown), the `weight`, and a `detail` such as `{matched: 3, of: 4}` or `{years: 8.2, needed: 5, from: "seniority"}`.
- `inputs`: everything the scorer read: the skill names on both sides, the resume years, the converted floor, the posting's annual maximum, the arrangement match and the eligibility.
- `matched_skills`, `missing_skills`, `reasons`.
- `profile_version` and `resume_id`: which profile and resume the score used.

The score is `round(100 × Σ(weight × value) ÷ Σ(known weights))`, so it can be recomputed from `breakdown` alone.

## On the development data

Scoring all 187 live postings for one profile twice gave the same score and breakdown every time. The spread was narrow: minimum 20, quartiles 35, 40 and 49, maximum 97. Salary was unknown for 93 postings and nice-to-have skills for 84; seniority and location were always known.

## Debugging a complaint

A user says "you gave me 45% but I'm perfect for this job." Every input and rule that produced the 45 is on the `job_matches` row for `(user_id, job_posting_id)`, so you can find the cause without re-running the scorer or calling a model:

1. **Check whether the row is stale.** Compare `profile_version`, `resume_id`, `scoring_version` and `scored_at` on the row with the user's current `profile.version`, `primaryResume.id` and `config('fit-scoring.version')`. If the user added skills, changed their salary floor or uploaded a new resume after `scored_at`, the 45 was computed from the older profile or resume. The posting can change too: compare `inputs.requiredSkills`, `inputs.niceSkills`, `inputs.salaryMax` and `inputs.yearsRequired` with the posting as it is now, because a re-import can update its skills, pay or years.
2. **Recompute the 45 from `breakdown`.** Sum `weight × value` over the factors where `value` is not null and divide by the sum of those weights. A factor with `value: null` drops out of the denominator, so the remaining known factors carry a larger share of the 100 points. This immediately shows which one or two factors pulled the total down to 45.
3. **Check each low factor's `detail` against `inputs`:**
    - **`required_skills` (weight 40) and `nice_skills` (weight 10):** `detail` gives `{matched, of}` and `missing_skills` names every unmatched required skill. Compare `inputs.requiredSkills` and `inputs.niceSkills` with `inputs.userSkills`. Because skills match by trimmed, case-insensitive name, a miss means the skill was not in `inputs.userSkills` when the score ran (for example, the resume mentions it but it never reached the user's skill list), the two sides used different names (`"Postgres"` vs `"PostgreSQL"`), or the posting extractor marked nice-to-have skills as required.
    - **`seniority` (weight 20):** `detail` gives `{years, needed, from}`, backed by `inputs.yearsOfExperience`, `inputs.yearsRequired` and `inputs.seniority`. If `years` is too low, the resume parser missed work history dates on `resume_id`; if `needed` is too high, the posting's required years or level was over-extracted.
    - **`salary` (weight 15):** `detail` gives `{floor, max}` as annual amounts in the posting's currency (`inputs.salaryFloor` and `inputs.salaryMax`). `value: 0` means `max < 0.8 × floor`: either the job really pays below the user's floor, the profile floor was entered with the wrong currency or period, or the posting's pay was parsed with the wrong period.
    - **`location` (weight 15):** `detail` gives `{arrangement_matches, eligibility}` (`inputs.arrangementMatches` and `inputs.eligibility`). `value: 0` means the posting's work arrangement is not in the user's profile (`arrangement_matches: false`) or the posting is `"restricted"` or `"excluded"` for the user's country; `value: 0.5` means country eligibility is `"unclear"`.
4. **Compare `reasons` with `breakdown`.** `reasons` come from `ExplainFit` over resume excerpts and never change `score`. When `reasons` sound strong but `score` is 45, the resume text describes the right experience while the structured fields in `inputs` (`userSkills`, `yearsOfExperience`, `salaryFloor` or `arrangementMatches`) did not reflect it.
5. **Decide what kind of cause it is.**
    - **A data problem:** wrong skill names, missed work history, a wrong currency or period, or an over-extracted posting. Fix the data and rescore; the number changes with no rule change.
    - **A known rule gap:** one of the drawbacks in [ADR 0036](adr/0036-score-fit-in-php-over-the-factors-it-can-measure.md), such as exact skill names or years that are not relevant years. Record the case against that drawback, and change the rule under a new `scoring_version`.
    - **A correct score:** the inputs are right and the job is a partial fit. Show the user the breakdown, so they see which factor holds the score down.
