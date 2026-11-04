# 33. Rerank the hybrid top 30 with Cohere behind a flag

- **Status:** Accepted
- **Date:** 2026-11-04

## Context

The hybrid ranking ([ADR 0031](0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md), [ADR 0032](0032-rank-the-whole-filtered-set-and-label-text-based-restrictions.md)) compares the profile and a posting through two cheap signals: a vector of each text, made apart, and the profile terms that the posting contains. Neither reads the two texts together. A reranker (a cross-encoder) does: it scores each pair of query and document in one pass. That is slower and costs money per call, so it runs on a short list that the cheap ranking chose.

The Laravel AI SDK has reranking for Cohere, Jina, Voyage AI, OpenRouter and Bedrock. The app had keys only for OpenAI and Anthropic.

## Decision

**When `ai-routing.reranking.enabled` is on, hybrid mode sends its first 30 openings to Cohere `rerank-v4.0-pro` and shows them in Cohere's order. Keyword and semantic mode are never reranked.**

1. **Provider:** Cohere, the SDK's default reranker. It is multilingual, so an Indonesian posting is read as well as an English one.
2. **Query:** "A job for this candidate", then the same roles and skills as the vector query (`ProfileEmbeddingText`). No free text from the profile is sent.
3. **Documents:** the title, the company, the verified skills and the first 2,000 characters of the description. The cut keeps a long posting from slowing the call.
4. **Candidates:** 30, the rows that the page shows. Cohere bills a search unit per query with up to 100 documents, so reordering all 30 costs the same as reordering 10. The rows after the candidates keep their hybrid order.
5. **Flag:** `AI_RERANKING_ENABLED`, off by default. Keyword and semantic mode stay as the baselines for the comparison.
6. **Cache:** the reranked order is cached for 24 hours under the hash of the provider, the model, the query and the documents. A change to the profile's roles or skills or to a candidate's text makes a new key, so the cache cannot serve a stale order. A repeat visit pays nothing.
7. **Failure:** a timeout of 3 seconds. A timeout, a provider error or a spent global budget keeps the hybrid order and reports the error. The page never fails because of the reranker.
8. **Usage:** each call writes an `ai_usages` row with feature `reranking`, the latency and the cost. The cost is the search units times the price per 1,000 units ($2.50, from third-party listings; Cohere's own page was not reachable). A provider that reports no units is billed one unit per 100 documents.
9. **Score:** the reranker's score is not shown. Like the RRF score and the cosine similarity, it is relative to this list and is not a fit (ADR 0032, point 5).

**Latency.** A reranker adds about 300 to 800 ms. The matches page is a full page load that the user asks for, the cache removes the cost from every visit after the first, and the timeout caps the worst case. A digest email runs in a job, so it can rerank more candidates with a longer timeout. A type-ahead search must not rerank on a keystroke: it would show the cheap ranking while the user types and rerank only on submit.

## Consequences

**Benefits**

1. The top of the list is ordered by a model that reads the profile and the posting together.
2. With the flag off, nothing changes, and every failure falls back to the hybrid order.
3. Repeat visits are free, and every call is in the usage report.

**Drawbacks**

1. **A new provider and key.** Cohere is a third AI vendor, with its own outage and its own bill. The circuit breaker of the agents does not cover it; the timeout and the fallback do.
2. **The reranker cannot add a posting.** It only reorders the 30 that the hybrid ranking chose. A good posting at hybrid rank 31 stays out.
3. **Posting text leaves the app.** Titles and descriptions of public postings go to Cohere. The profile's free text does not.
4. **The cost is not per user.** The usage row has no user, so the per-user advisor budget does not count it; the global budget does.
5. **The price is unconfirmed** until it is checked on Cohere's pricing page.
6. **Quality is judged by eye for now.** The retrieval evaluation measures hybrid + rerank against the other modes with graded labels.

**Alternatives considered**

1. **Voyage AI or Jina:** billed per token, which fits the per-token price model. Cohere was chosen for its multilingual model and because it is the SDK default.
2. **Rerank in a deferred prop:** the page would show the hybrid order and then reorder the list in front of the user. Rejected: rows that move after the page is drawn are worse than a short wait.
3. **An LLM as the reranker:** a prompt that orders 30 postings costs more tokens and is slower than a cross-encoder. Rejected.

**Revisit when** the retrieval evaluation has a hybrid + rerank row, or the cache hit rate in the logs (`matches.reranked`, `cached`) shows that most visits pay.
