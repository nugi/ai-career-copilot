# Reranking

Hybrid ranking ([ADR 0031](../adr/0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md)) compares a profile and a posting through a vector of each and the profile terms that the posting contains. A reranker reads the profile query and a posting together and scores the pair. The matches page sends its hybrid top 30 to Cohere `rerank-v4.0-pro` and shows them in Cohere's order ([ADR 0033](../adr/0033-rerank-the-hybrid-top-30-with-cohere-behind-a-flag.md)).

## Pipeline

1. Filters, then hybrid ranking over the filtered set (`MatchOpenings`).
2. The first 30 openings become documents: title, company, verified skills and the first 2,000 characters of the description.
3. The query is the profile's roles and skills, the same text as the vector query.
4. `Reranking::of($documents)->timeout(3)->rerank($query, 'cohere', 'rerank-v4.0-pro')`.
5. The candidates take Cohere's order. The order is cached for 24 hours under the hash of the query and the documents.
6. Any failure, timeout or spent global budget keeps the hybrid order.

Each call writes an `ai_usages` row with feature `reranking`, its latency and its cost. The log line `matches.reranked` has the candidate count, whether the cache served it, and the latency.

## With and without reranking

Measured with `php artisan eval:rerank {email}` on the live postings, one fresh call per search, with no cache. The cost of the profile embedding is left out of both rows, because it is cached on the profile.

| Search                           | Mode            | Quality (by eye) | Latency (ms) | Cost per view (USD) |
| -------------------------------- | --------------- | ---------------- | ------------ | ------------------- |
| 1. Remote, Indonesia (Laravel)   | Hybrid          | _to measure_     | _to measure_ | 0                   |
| 1. Remote, Indonesia (Laravel)   | Hybrid + rerank | _to measure_     | _to measure_ | _to measure_        |
| 2. Remote, any country (Laravel) | Hybrid          | _to measure_     | _to measure_ | 0                   |
| 2. Remote, any country (Laravel) | Hybrid + rerank | _to measure_     | _to measure_ | _to measure_        |
| 5. Any arrangement (React)       | Hybrid          | _to measure_     | _to measure_ | 0                   |
| 5. Any arrangement (React)       | Hybrid + rerank | _to measure_     | _to measure_ | _to measure_        |

The searches are those of [`docs/baseline-searches.md`](../baseline-searches.md). For the quality column, compare the two top 10 lists that the command prints. Note which postings moved into the top 10, which left, and whether the move was right.

Expected cost: one search unit per view ($0.0025 at the listed price) for an uncached view, and nothing for a cached one.

### How to run it

```bash
php artisan eval:rerank you@example.com --dry-run
```

```bash
php artisan eval:rerank you@example.com
```

```bash
php artisan eval:rerank you@example.com --country=
```

`--dry-run` prints the candidate count and the estimated cost without a call. The command needs `COHERE_API_KEY`, and it reranks even when `AI_RERANKING_ENABLED` is off.

## Where 300 to 800 ms is acceptable

- **Matches page:** acceptable. The user asks for the page, the cache makes every later visit free, and the 3-second timeout falls back to the hybrid order.
- **Digest email:** acceptable and the best place for a reranker. It runs in a queued job, so it can rerank more candidates with a longer timeout, and nobody waits.
- **Type-ahead:** not acceptable. Show the cheap ranking while the user types, and rerank only on submit, or not at all.
- **Not chosen:** a deferred prop that redraws the list in the reranked order. Rows that move after the page is drawn are worse than a short wait.
