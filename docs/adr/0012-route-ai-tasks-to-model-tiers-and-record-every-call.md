# 12. Route AI tasks to model tiers and record every call

- **Status:** Accepted. The chat tier is changed by [ADR 0013](0013-run-chat-on-the-cheap-tier-until-evaluations-show-a-gain.md).
- **Date:** 2026-10-21

## Context

Each agent named its models in code, and both agents used the same small models. Nothing recorded what a call cost, so there was no way to see which feature spends the money, or to tell whether a cheaper model would do.

The tasks need different models. Resume parsing extracts fields from text that is already there, so a small model is good enough. Career advice is judgment, and it is what the user reads.

The models also take different reasoning options. `gpt-6.1-sol` rejects reasoning effort `none`, `claude-sonnet-5-5` rejects `thinking: disabled`, and `claude-haiku-5-5` rejects `thinking: between_tools`. So an option that is right for one model returns a 400 on another.

The Laravel AI SDK dispatches an event before each provider attempt, and after each success, failover and final failure. Each event carries the invocation ID, the agent, the provider and the model.

## Decision

**`config/ai-routing.php` maps each task to a tier, each tier to one model per provider, and each model to its price and options. A listener on the SDK events writes one `ai_usages` row per provider attempt.**

- Tiers: `cheap` (`gpt-6-luna`, `claude-haiku-5-5`) for resume parsing, and `strong` (`gpt-6.1-sol`, `claude-sonnet-5-5`) for chat.
- Agents implement `TracksUsage` (their task and their user) and read their models and options from `ModelRouter`. The circuit breaker still orders the providers ([ADR 0005](0005-fail-over-on-provider-outages-only.md)).
- Prices are USD per million tokens, standard rates for prompts under 100K tokens, checked on 2026-10-21. Cost is estimated at write time from the token counts that the provider reports.
- A row records the user, the task, the tier, the agent, the prompt version, the provider, the model, the status (`succeeded`, `failed_over`, `failed`), the tokens, the cost and the latency of the attempt. One invocation ID links the attempts of one call.
- Recording is best effort. A failed insert is logged as `ai.usage.record_failed` and never fails the call. A model without a price is recorded at zero cost and logged as `ai.usage.unpriced_model`.
- `php artisan usage:report --days=7` shows calls, failures, tokens, cost and latency per tier, agent, provider and model, with tier totals.

## Consequences

**Benefits**

1. Moving a task to another model, or updating a price, is a config change.
2. Every call, including a streamed call and an attempt that failed over, has a cost row. Failovers become visible per provider.
3. The rows give the data for per-user budgets and for the cost side of model evaluations.

**Drawbacks**

1. Prices are copied by hand and go stale when a provider changes them. The cost is an estimate, and it ignores cached-input discounts and long-context rates.
2. Chat now runs on the strong tier, at about 20 times the price per token of the cheap tier. The quality gain has not been measured yet.
3. A model's options live in config, so a new model needs a live check of the options it accepts before it is added.

**Revisit when** the evaluation suite can compare tiers on real questions, which may move simple chat questions to the cheap tier, or when a provider bill differs from the report by more than 10%.

## Notes

**2026-10-23: failed attempts.** A failed attempt records 0 tokens. When the failure is not an error response from the provider (a dropped connection, a timeout, a broken stream), the provider can still bill tokens, so the row is marked `tokens_estimated`.

**2026-10-23: budget overshoot is an accepted risk.** The budget check reads the spend before a call and the usage row is written after it. Parallel requests can all pass the check at the limit, so the spend can go over the budget by the cost of the calls that are running at that moment. The `advisor` rate limit (10 requests a minute per user) keeps that overshoot to a fraction of a cent per user on the cheap tier, so the check takes no lock.
