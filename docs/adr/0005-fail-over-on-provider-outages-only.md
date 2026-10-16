# 5. Fail over between AI providers on outages only

- **Status:** Accepted
- **Date:** 2026-10-15
- **Amended:** 2026-10-16 (exhausted credits, circuit breaker)

## Context

The career advisor agent sends each question to OpenAI first and to Anthropic second. The Laravel AI SDK moves to the next provider only when a call raises a `FailoverableException`:

- rate limits (429) and exhausted credits (402);
- a provider that is overloaded or unreachable (502, 503, 504, 520, 522, 524, and 529 for Anthropic);
- connection errors.

A rejected key (401) and a bad request (400) do not fail over. They raise an error at once.

Failover can also hide faults. A wrong key is our own configuration error. If a second provider answers in its place, the fault stays unnoticed until the second provider's quota runs out. The two models also write in different voices. A change of voice is acceptable for a single answer, but it is noticed in a conversation.

Exhausted credits are also our own fault, but a billing fault: the account must be topped up, and users still need an answer meanwhile. OpenAI reports them as status 429 with the code `insufficient_quota`, not as 402.

## Decision

**Fail over** when a provider cannot serve the request: outages, rate limits and exhausted credits. This applies to single-turn answers, where a different model's tone is acceptable.

**Fail loudly** in these cases:

- Our own errors: a missing or wrong key, a bad request, an unknown prompt version. These are reported as exceptions and never retried on another provider. Exhausted credits are the one exception: they fail over, and the failover is logged at error level so that monitoring can alert us.
- Structured output that the fallback model has not been tested against, once agents return structured data.
- Turns inside a conversation, once agents hold conversations, where a change of voice would be noticed. A "try again" message is better there than a different model.

Every failover follows these rules:

- It is logged as `ai.agent.failed_over` with the agent, the provider, the model, the exception class and a reason. Exhausted credits log at error level, and other failovers at warning level. The exception message is not logged, because it can echo the request.
- The prompt is written to work on both models.
- A failover sends the same request to the second provider, so both providers must be acceptable for the data in it. The advisor sends the question and the user's target roles, locations, work arrangements and skills, never the headline or the salary.
- The answer records the provider and the model that produced it, and the log records the prompt version.
- When every provider fails, the advisor reports the exception and responds with status 503.
- A circuit breaker counts the failures of each provider. After 3 failures in a row within 60 seconds, the provider moves to the end of the list for 120 seconds, so requests do not wait for its timeout. After the cooldown the provider is tried again. One more failure opens the circuit again, and one success closes it. Every open and close is logged.

## Consequences

**Benefits**

1. A user gets an answer during an outage at one provider.
2. Our own faults, such as a wrong key, stop the request at once and show as errors.
3. Every failover is visible in the log, so a provider that fails often can be found.
4. A provider that hangs costs 3 slow requests when it starts to fail, and then 1 slow request after each cooldown, not every request.

**Drawbacks**

1. Answers can come from either model. Until evaluations exist, we cannot measure how the two models differ on the same prompt.
2. A failing provider still costs the first requests up to the 30-second timeout before the circuit opens.
3. Structured output and multi-turn conversations are not covered by this decision. Each needs its own decision before it uses failover.

**Revisit when** evaluations can compare both models on the same prompt version, or when an agent starts to return structured output or hold conversations.
