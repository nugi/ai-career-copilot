# 1. Use PostgreSQL with pgvector for vector storage

- **Status:** Accepted
- **Date:** 2026-10-13

## Context

Fit scoring, semantic job search and RAG need embeddings, and most queries mix them with relational filters (location, salary, jobs already applied to). Expected scale is up to a few hundred thousand jobs, on a small budget.

Options: PostgreSQL with pgvector, or a dedicated vector database (Pinecone, Qdrant) next to PostgreSQL.

## Decision

Use PostgreSQL 18 with pgvector as the single datastore, with HNSW indexes. Pin the same pgvector version in development, CI and production.

## Consequences

**Benefits**

1. One source of truth: filters, joins and similarity ranking run in one query and one transaction, with no sync pipeline to drift.
2. Hybrid search (full-text + vector, fused with RRF) stays in plain SQL.
3. One managed database (RDS) to run and pay for, with native Laravel 13 support for vector columns and queries.

**Drawbacks**

1. Lower ceiling at large scale: HNSW wants its index in memory, and vector queries compete with transactional load.
2. Fewer vector-specific features: selective filters can reduce recall, and indexed `vector` columns are capped at 2,000 dimensions.

**Revisit when**, after tuning, vectors exceed about 10 million, p95 vector latency misses the product target, or vector load slows transactional queries. Vector search stays behind a small service boundary so a move is contained.
