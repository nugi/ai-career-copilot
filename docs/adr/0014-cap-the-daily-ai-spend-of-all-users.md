# 14. Cap the daily AI spend of all users

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

Each user has a daily AI budget ([ADR 0012](0012-route-ai-tasks-to-model-tiers-and-record-every-call.md)). It was $0.50, set when chat ran on the strong tier. On the cheap tier ([ADR 0013](0013-run-chat-on-the-cheap-tier-until-evaluations-show-a-gain.md)) a typical user asks about 3 questions a day, about $0.0006. A $0.50 budget is several hundred times that, so it no longer protects the owner's money. With 1,000 users the worst case is $500 a day.

Nothing limited the spend of all users together. One scripted account, or a few, could spend far more than the expected monthly cost in one day.

## Decision

**The per-user budget becomes a fairness limit of $0.02 a day. A global budget of $1.00 a day stops the advisor for every user when the spend of all users reaches it.**

- `AI_DAILY_BUDGET_USD=0.02` is about 30 times typical use, or about 100 cheap-tier answers.
- `AI_GLOBAL_DAILY_BUDGET_USD=1.00` is checked first by `ai.budget`. At the cap, the advisor returns 429 with the code `ai_global_budget_exceeded` until midnight UTC, and a warning `ai.budget.global_exceeded` is logged.
- Resume parsing is essential, cheap and deduplicated, so it never stops. Its cost counts in both sums.
- Null turns either check off. Both checks can go over by the calls in flight, as ADR 0012 notes.

## Consequences

**Benefits**

1. The worst-case daily spend is about the global budget, whatever the number of users.
2. One user cannot use up the global budget alone, because the per-user limit is 50 times smaller.

**Drawbacks**

1. When the global cap is reached, every user loses the advisor for the rest of the UTC day, including users who asked nothing.
2. Both values are set by hand, so they need a review when the tier, the prompt size or the number of users changes.

**Revisit when** real usage gives a measured per-user distribution, or when paid plans need their own budgets.

## Notes

**2026-10-23: fake accounts.** The global budget can be spent by accounts made only to spend it. At $0.02 each, 50 accounts reach the $1.00 cap and switch the advisor off for every user. Email verification does not stop this, because throwaway addresses are free. The planned fix is to reserve part of the global budget for established accounts, such as accounts older than a few days or with an uploaded resume, so new accounts cannot use all of it. Per-plan quotas replace this later.
