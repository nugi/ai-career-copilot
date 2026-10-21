# 11. Run AI work on separate queues and stream chat answers

- **Status:** Accepted
- **Date:** 2026-10-21

## Context

A resume parse calls the AI provider up to twice, and each call may wait 90 seconds on each of two providers. It cannot run inside the upload request. A career advisor answer takes a few seconds, and the user waits for every word of it.

Three facts shape the design:

- A queue connection retries a job after its `retry_after`, even when the first worker still runs it. The `redis` connection retries after 90 seconds, but a parse may run for 400.
- Horizon gives each supervisor its own worker processes. Jobs in one supervisor cannot take the workers of another.
- The Laravel AI SDK streams an agent's answer as server-sent events from the request. It fails over to the next provider only before the first event ([ADR 0005](0005-fail-over-on-provider-outages-only.md)).

## Decision

**Long AI jobs run on their own Redis connection and Horizon supervisor. Chat answers stream from the request, not through a queue.**

- Resume parsing runs on the `ai-batch` queue of the `redis-batch` connection. `Queue::route` sends `ParseUploadedResume` there, set by `QUEUE_AI_BATCH_CONNECTION`.
- Every timeout is shorter than the retry that follows it: job timeout < supervisor timeout < connection `retry_after`.

    | Queue                | Job    | Supervisor | `retry_after` |
    | -------------------- | ------ | ---------- | ------------- |
    | `ai-batch`           | 400 s  | 410 s      | 420 s         |
    | `ai-high`, `default` | ≤ 60 s | 60 s       | 90 s          |

- Horizon runs three supervisors, `ai-high`, `ai-batch` and `default`, each with its own process limit. Long-wait alerts are set at 10 seconds for `ai-high` and 120 seconds for `ai-batch`.
- The parse job is unique per user and content hash. A duplicate upload reuses the parse, and the job fills in duplicates whose own dispatch was dropped by the unique lock.
- The resumes page shows the parse status and polls while a parse is pending.
- The advisor page posts the question to `advisor.stream` and renders `text_delta` events as they arrive. When every provider fails, the stream ends with one `error` event instead of a broken response.
- The `ai-high` queue is reserved for short, user-facing AI jobs. No job uses it yet.

## Consequences

**Benefits**

1. An upload returns at once, and a slow parse is never run twice by two workers.
2. A slow provider fills at most the `ai-batch` workers. Other queues keep their own workers.
3. The user sees the first words of an answer in about half the time of the full answer (1.7 s of 3.5 s in a local test), without websockets or a broadcast server.

**Drawbacks**

1. A streamed answer holds a PHP worker for the whole answer. A slow provider can use up the web workers, which a queued, broadcast answer would avoid.
2. Horizon needs `ext-pcntl` and `ext-posix`, so it does not run on native Windows. Windows development uses one `queue:listen` per connection, and Horizon runs on Linux or WSL.
3. Polling costs one small request every 3 seconds per open resumes page while a parse is pending.

**Revisit when** chat answers need tools or long reasoning, or web workers saturate under streaming load. Then move chat to `broadcastOnQueue` on `ai-high` with a websocket server.
