# 13. Run chat on the cheap tier until evaluations show a gain

- **Status:** Accepted
- **Date:** 2026-10-22

## Context

[ADR 0012](0012-route-ai-tasks-to-model-tiers-and-record-every-call.md) put chat on the strong tier (`gpt-6.1-sol`, `claude-sonnet-5-5`). The strong tier costs about 20 times more per token than the cheap tier, and the quality gain was not measured.

The recorded advisor calls used about 920 input and 70 output tokens each. At those sizes one answer costs about $0.0025 on the strong tier and about $0.00013 on the cheap tier. The project is in development and is paid for by one person, so every manual test, demo and live check of the advisor spends that difference.

## Decision

**Chat runs on the cheap tier (`gpt-6-luna`, `claude-haiku-5-5`). The strong tier and its prices and options stay in `config/ai-routing.php`, but no task uses it by default.**

- A task moves to the strong tier only when an evaluation on real questions shows that the strong answers are better by enough to pay for the price.
- The move is a one-line change in `tasks` in `config/ai-routing.php`. A test keeps the strong models and their options working.
- The tiers, the usage rows and the daily budget of ADR 0012 do not change.

## Consequences

**Benefits**

1. A development advisor answer costs about 5% of what it did, so the $0.50 daily budget allows several thousand answers instead of about two hundred.
2. Chat and resume parsing use the same models, so one provider check covers both tasks.

**Drawbacks**

1. The advice comes from smaller models, with no reasoning step. Answers can be shorter or less specific than on the strong tier.
2. Usage rows from before this change show chat on the strong tier, so a report over that period mixes both tiers.

**Revisit when** the evaluation suite can compare the tiers on real advisor questions.

## Notes

**2026-10-23: first comparison.** 20 advisor questions for one real profile (8 simple, 8 that need judgment, 4 edge cases) were answered on both tiers. The pairs were shuffled, then scored blind on fit, usefulness, accuracy and format, 1 to 5 each.

|                            | Cheap (`gpt-6-luna`) | Strong (`gpt-6.1-sol`) |
| -------------------------- | -------------------- | ---------------------- |
| Average score of 20        | 17.5                 | 18.9                   |
| Pairs won                  | 0                    | 17 (3 ties)            |
| Cost per answer            | $0.0002              | $0.0045                |
| Median time to full answer | 5.3 s                | 10.4 s                 |

The strong tier is better in almost every pair, by about 7%, at about 22 times the cost. The gap is smaller on simple questions (0.9 points) than on questions that need judgment (1.5 points). The cheap tier's one real failure was an edge case: it declined to fake experience, but asked the user for a reply and suggested a CV line that overstated the user's use of a tool. The decision stands. There was one judge and one answer for each question, and the strong answers were about 15% longer, so the result is a baseline, not a final verdict.
