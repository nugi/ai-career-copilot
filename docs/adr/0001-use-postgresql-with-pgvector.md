# 1. Use PostgreSQL with pgvector for vector storage

- **Status:** Accepted
- **Date:** 2026-10-13
- **Amended:** 2026-10-19 (changing the embedding model), 2026-10-30 (measured plans and timings)

## Context

Fit scoring, semantic job search and RAG need embeddings, and most queries mix them with relational filters (location, salary, jobs already applied to).
Vector count grows with active job postings (1–5 vectors each), not with users.

Options: PostgreSQL with pgvector, or a dedicated vector database (Pinecone, Qdrant) next to PostgreSQL.

## Decision

Use PostgreSQL 18 with pgvector as the single datastore, with HNSW indexes. Require pgvector 0.8 or newer, and pin the same version in development, CI and production.

## Consequences

**Benefits**

1. One source of truth: filters, joins and similarity ranking run in one query and one transaction, with no sync pipeline to drift.
2. Hybrid search (full-text + vector, fused with RRF) stays in plain SQL.
3. One managed database (RDS) to run and pay for, with native Laravel 13 support for vector columns and queries.

**Drawbacks**

1. Lower ceiling at large scale: HNSW wants its index in memory, and vector queries compete with transactional load. Mitigation: `halfvec` halves the memory per vector.
2. Fewer vector-specific features: selective filters can reduce recall, and indexed `vector` columns are capped at 2,000 dimensions. Mitigation: iterative index scans (0.8+) restore recall under filters, and `halfvec` indexes up to 4,000 dimensions.
3. Changing the embedding model rebuilds everything. Vectors from two models cannot be compared, so every row is embedded again and a new HNSW index is built. The embedding cost is the same with a dedicated vector database, but there the index is built on separate machines. Here the build runs on the primary database and competes with transactional load, and it is fast only when the graph fits in `maintenance_work_mem`. Mitigation: store the model name with each vector, write the new vectors to a new column, build its index with `CREATE INDEX CONCURRENTLY` and a raised `maintenance_work_mem` for that session (an RDS parameter group sets only the default), and switch reads when the index is ready.

**Revisit when** the HNSW indexes take up more than about half of the database instance's RAM, after tuning. Estimate before ingesting: index size ≈ vectors × dimensions × 4 bytes (2 for `halfvec`), so 1M vectors at 1,536 dimensions ≈ 6 GB. In production, measure it with `pg_relation_size` on the HNSW indexes. Tune in this order before moving: `halfvec`, fewer dimensions, embeddings for active jobs only, a larger instance. Also revisit if p95 vector latency misses the product target or vector load slows transactional queries. Vector search stays behind a small service boundary so a move is contained. A change of embedding model is likelier than outgrowing the instance. Plan it as a rebuild (drawback 3), and check the index size estimate again for the new model's dimensions.

## Measured

Run on 2026-10-30 on a development database with `job_postings.embedding` as `vector(1536)` and an HNSW index on cosine distance with `m = 16` and `ef_construction = 64`, the pgvector defaults, kept on purpose. The table held 15,211 fake postings with random vectors, which were deleted after the run. The 369 real postings had no vectors. pgvector was 0.8.7.

The query is `select id from job_postings order by embedding <=> ? limit 10`.

1. **Size:** the index took 119 MB for 15,211 vectors, about 7.8 KB each, against 6.1 KB for the vectors alone. The table with its TOAST data took 138 MB. The estimate in "Revisit when" holds, with about 30% more for the graph.
2. **Plan:** the planner chose a sequential scan at 5,580 and at 10,580 rows, and the HNSW index scan at 15,580 rows (`Index Scan using job_postings_embedding_hnsw_index`, `Index Searches: 1`). Its own cost estimate crosses at about 13,500 rows. The estimate is probably too low for the sequential scan, because the vectors sit in TOAST and the plan does not price reading them. To see the index below the crossover, run `set local enable_seqscan = off`; this is for a test, not for the app.
3. **Time at 15,580 rows:** the index scan took 9 ms on the first run and 2 ms when the pages were cached, with 1,431 buffer hits and 151 reads. The sequential scan took 370 ms, with 46,654 hits and 16,261 reads. Over 30 random queries the mean was 8 to 14 ms with the index and 384 ms without.
4. **`hnsw.ef_search`:** recall@10 against the exact result, over the same 30 queries:

    | ef_search | Recall@10 | Mean latency |
    | --------- | --------- | ------------ |
    | 40        | 0.15      | 13.5 ms      |
    | 100       | 0.28      | 8.3 ms       |
    | 200       | 0.45      | 11.9 ms      |

    The latency differences are noise on this machine. The recall is low because uniformly random vectors in 1,536 dimensions have no clusters, which is the worst case for HNSW. Real embeddings must be measured again once Day 19 has embedded the live postings.

5. **Filtered search:** with `where seniority = 'lead' and employment_type = 'contract'` (1,283 of 15,211 rows pass, 8%) the plan stays an index scan with a `Filter`, so the filter runs after the approximate search:

    | Setting                                      | Rows returned | Recall@10 |
    | -------------------------------------------- | ------------- | --------- |
    | ef_search 40                                 | 3.4 of 10     | 0.14      |
    | ef_search 200                                | 10 of 10      | 0.42      |
    | ef_search 40, iterative scan (relaxed order) | 10 of 10      | 0.31      |

    The index returns the `ef_search` nearest candidates, and the filter then removes the ones that do not match. With 8% of rows passing, 40 candidates leave about 3 rows. Fixes: `hnsw.iterative_scan = relaxed_order`, which keeps scanning until it has enough rows (it may return rows slightly out of distance order); a higher `ef_search`; fetching more than `k` and filtering in the app; or a partial index or a partition for a filter that is always applied. The app applies hard filters in the same query (Day 21), so it must set the iterative scan.
