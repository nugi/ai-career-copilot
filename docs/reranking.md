# Reranking

Hybrid ranking ([ADR 0031](adr/0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md)) compares a profile and a posting through a vector of each and the profile terms that the posting contains. A reranker reads the profile query and a posting together and scores the pair. The matches page sends its hybrid top 30 to Cohere `rerank-v4.0-pro` and shows them in Cohere's order ([ADR 0033](adr/0033-rerank-the-hybrid-top-30-with-cohere-behind-a-flag.md)).

## Pipeline

1. Filters, then hybrid ranking over the filtered set (`MatchOpenings`).
2. The first 30 openings become documents: title, company, verified skills and the first 2,000 characters of the description.
3. The query is the profile's roles and skills, the same text as the vector query.
4. `Reranking::of($documents)->timeout(3)->rerank($query, 'cohere', 'rerank-v4.0-pro')`.
5. The candidates take Cohere's order. The order is cached for 24 hours under the hash of the query and the documents.
6. Any failure, timeout or spent global budget keeps the hybrid order.

Each call writes an `ai_usages` row with feature `reranking`, its latency and its cost. The log line `matches.reranked` has the candidate count, whether the cache served it, and the latency.

## With and without reranking

Measured on 2026-11-05 on the live postings, one fresh call per search, with no cache, from a local machine. The cost of the profile embedding is left out of both rows, because it is cached on the profile.

| Search                                 | Mode            | Quality (by eye) | Latency (ms) | Cost per view (USD) |
| -------------------------------------- | --------------- | ---------------- | ------------ | ------------------- |
| 1. Remote and hybrid, Indonesia        | Hybrid          | Baseline         | 39           | 0                   |
| 1. Remote and hybrid, Indonesia        | Hybrid + rerank | Better           | 1,157        | 0.0025              |
| 2. Remote and hybrid, any country      | Hybrid          | Baseline         | 74           | 0                   |
| 2. Remote and hybrid, any country      | Hybrid + rerank | Better           | 1,610        | 0.0025              |
| 5. Any arrangement, any country, React | Hybrid          | Baseline         | 68           | 0                   |
| 5. Any arrangement, any country, React | Hybrid + rerank | Better           | 1,336        | 0.0025              |

Searches 1 and 2 ran with `php artisan eval:rerank {email}` on a profile with both Laravel and React, so they rank by the whole profile and are not limited to Laravel as in [`docs/baseline-searches.md`](baseline-searches.md). The command cannot express search 5, so it ran the same steps with the profile's skills limited to React and every arrangement selected, in memory only. The quality column compares the two top 10 lists by eye. It counts a role that fits in another programming language as a good match, so a Go backend role above PHP roles is a gain.

Ten of the twelve postings in the two top 10 lists of search 1 are seeded postings with generated text, so its quality rating says little about real postings.

Each uncached view cost one search unit, $0.0025 at the listed price. A cached view costs nothing.

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

`--dry-run` prints the candidate count and the estimated cost without a reranking call. It still embeds the profile first if the profile's vector is stale. The command needs `COHERE_API_KEY`, and it reranks even when `AI_RERANKING_ENABLED` is off.

## Where the added latency is acceptable

The expected cost was 300 to 800 ms. The three calls above took 1,047 to 1,488 ms, from one sample per search on a local machine.

- **Matches page:** acceptable. The user asks for the page, the cache makes every later visit free, and the 3-second timeout falls back to the hybrid order.
- **Digest email:** acceptable and the best place for a reranker. It runs in a queued job, so it can rerank more candidates with a longer timeout, and nobody waits.
- **Type-ahead:** not acceptable. Show the cheap ranking while the user types, and rerank only on submit, or not at all.
- **Not chosen:** a deferred prop that redraws the list in the reranked order. Rows that move after the page is drawn are worse than a short wait.
