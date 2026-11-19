# 38. Rank skill gaps by fit and recency, and plan from the top five

- **Status:** Accepted
- **Date:** 2026-11-19

## Context

"What am I missing?" should come from the postings the user actually matches, not from a general skills list. A skill can look important only because many postings name it: Kubernetes appears in most backend postings, but usually as nice-to-have. A count of postings would rank it first for a user who never needs it.

The gap list also feeds a paid call. The learning plan is one cheap-tier structured call, and the user pays for every call ([ADR 0014](0014-cap-the-daily-ai-spend-of-all-users.md)). A plan that names skills the user does not lack, or courses and URLs the model made up, costs money and misleads.

The [agent design](../agent-design.md) lists `SkillGaps` as read-only, and the plan as a fixed workflow: SkillGaps first, then the planner.

## Decision

**A skill gap is a skill that the user's top 30 matches ask for and the user does not have. Each match adds its fit score, halved for every 14 days since it was posted, and a nice-to-have skill adds a quarter of that. The planner gets the top five gaps, and the user keeps one plan, made at most once in 24 hours.**

1. **Matches:** the top 30 openings of the hybrid match, without reranking, so the list costs no AI call except, on a cache miss, the profile embedding. Each one is scored in memory with `FitScorer` ([ADR 0036](0036-score-fit-in-php-over-the-factors-it-can-measure.md)). Nothing is stored: `ScoreJob` would overwrite the stored `ExplainFit` reasons of a match.
2. **Weight:** `score / 100 × 0.5^(age_days / 14) × factor`, where the factor is 1 for a required skill and 0.25 for a nice-to-have skill. One SQL query sums the weight per skill and ranks the skills with `rank()`. The half-life and the factor are in `config/skill-gaps.php`.
3. **Missing:** a skill is missing when no skill of the user has the same name in lower case, the comparison `FitScorer` makes. So the gap list agrees with each match's `missing_skills`.
4. **"N of M":** M counts only the matches whose skills are extracted. A posting still waiting for extraction has no skill list, so counting it would make every gap look rarer.
5. **Plan:** `LearningPlanner` gets the top five gaps, the target roles and the current skills, and returns 4 weeks, one practice project and a resume and LinkedIn text. PHP validates the shape (exactly 4 weeks, 2 to 4 tasks a week) with one retry, keeps only skills that are sent gaps, and fails a plan with no gap skill left. The prompt forbids courses, books, websites, URLs and prices, and says the project is a personal project, not a job.
6. **Limits:** one plan per user (`learning_plans.user_id` is unique), and a new plan at most 24 hours after the last one. The budgets are checked in the order of `EnsureAiBudget` before the call, and a per-user lock stops a double click from paying twice. A failed attempt stores nothing and does not start the 24 hours, because the user got nothing for it. The per-user budget caps what failures can cost.

## Consequences

**Benefits**

1. A skill that is required by a few strong, recent matches ranks above a skill that many weak or old postings list as nice-to-have.
2. The gap list costs nothing to show, so the Grow page and the `SkillGaps` tool can call it freely.
3. The plan names only real gaps, and every stored plan keeps the gaps it was made from and its prompt version.

**Drawbacks**

1. **The 0.25 factor is a judgement, not a measurement.** The check: for a set of profiles, compare the top 5 gaps at factors 0, 0.25 and 0.5, and ask whether each change matches what a reviewer would study first. Until that runs, the factor can be wrong in either direction.
2. **The weight follows the fit score.** A skill that would open many low-scoring jobs ranks low, because those jobs score low. The list answers "what would improve my best matches", not "what would widen my search".
3. **Each Grow page view scores 30 matches again**, about 60 small queries. The gaps load as a deferred prop, so the page draws first.
4. **A plan goes stale.** New postings and new user skills change the gaps, but the plan stays until the user makes a new one, and at most once a day.
5. **A bad first output costs a second call.** The retry doubles the cost of that plan, about $0.001 at most on the cheap tier.

**Alternatives considered**

1. **Required skills only.** Simpler, but a skill that every match lists as nice-to-have would never show.
2. **A count of postings.** Cheapest to explain, but it ranks the Kubernetes case first.
3. **The model ranks the gaps.** It adds a call and variance to a sum that SQL computes exactly.
4. **Every plan as history.** More to show, but more rows and more calls for a plan that is meant to be followed, not compared.
