# 1. Use PostgreSQL with pgvector for vector storage

- **Status:** Accepted
- **Date:** 2026-10-13
- **Amended:** 2026-10-19 (changing the embedding model)

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
