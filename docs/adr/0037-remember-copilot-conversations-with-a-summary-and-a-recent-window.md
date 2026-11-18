# 37. Remember copilot conversations with a summary and a recent window

- **Status:** Accepted
- **Date:** 2026-11-13

## Context

The copilot replaces the one-shot advisor ([ADR 0011](0011-run-ai-work-on-separate-queues-and-stream-chat.md)). A user asks "find remote Laravel jobs", then "why is the second one a good fit?", so the second turn needs the first turn's job ids. Every step of a turn resends the whole history, and a 30-turn conversation measured 11,581 history tokens at its last turn with the full history. The design ([agent design](../agent-design.md)) also sets turn limits that the SDK does not enforce by itself: $0.005 and 60 s per turn.

## Decision

**The copilot stores conversations in the SDK's conversation tables, replays the stored messages with their tool calls and results, and replaces the messages older than a recent window with a summary that a batch job writes. A step middleware enforces the turn limits.**

1. **Storage:** the SDK tables `agent_conversations` and `agent_conversation_messages`. The controller creates the conversation before the stream, so the first event can carry its id. A conversation that is not the user's returns 404, for the page and for the stream.
2. **Titles:** the first 50 characters of the first message. The SDK's default title is an extra model call that the usage recorder does not see, so it is off (`ai.conversations.generate_title`).
3. **Recent window and summary:** the copilot sends the summary as one message, then every message newer than `summary_through`. `SummarizeConversation` runs on the batch queue after a turn. When at least `summary_window + summary_batch` (10 + 10) messages are past the summary, it folds all but the newest 10 into it with `ConversationSummarizer` (cheap tier, feature `conversation_summary`, batch budget). The summary keeps job ids, titles and companies, the user's wishes, and `ScoreFit` scores with their job id.
4. **Turn limits:** `LimitTurn` reads the turn's accumulated usage before each step, prices it with the configured model prices, and checks the time since the first step. At $0.005 or 60 s it sends the step without tools and with an instruction to answer now, so the copilot answers with what it has.
5. **Budgets:** the copilot has its own per-user budget, $0.05, on copilot spend only. The reserve planned in [ADR 0014](0014-cap-the-daily-ai-spend-of-all-users.md) ships with it: accounts younger than 3 days or without a parsed resume share at most 30% of the global cap.

## Consequences

**Benefits**

1. Follow-up questions work across turns, because the tool results come back with the history.
2. With the summary, the history at turn 30 was 6,280 tokens instead of 11,581, and the whole 30-turn conversation cost 7% less, summary calls included.
3. No model call happens that the usage recorder cannot see.

**Drawbacks**

1. **The saving is small at this window.** Old tool results inside the 10–20 recent messages dominate the history. Summarizing after every turn saved 24%, but cost 25 summary calls in 30 turns.
2. **A summary loses detail.** In the measured run it dropped the user's "over $4,000 a month" search.
3. **The summary grows with the jobs named.** It was 381 words at turn 30, against the prompt's 200, because it keeps every job.
4. **The turn cap counts only the copilot's own calls.** A tool's `ExplainFit` or embedding call counts toward the general budget, not the turn cap.
5. **The summary lags one batch-queue job behind.** A turn that starts before the job ends sends the longer history.

**Alternatives considered**

1. **Full history up to the SDK's 100 messages.** Simple and exact, but every turn pays for every old tool result.
2. **A sliding window only.** Cheapest, but an early job id or wish is lost once it leaves the window.
3. **Retrieval over past turns.** Exact recall of old details, but an embedding per message and a retrieval per turn, for conversations that are rarely long enough to need it.
