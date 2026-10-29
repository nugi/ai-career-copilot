# Embedding benchmark

Two OpenAI embedding models, each at two dimension sizes, on the live job postings. The result chooses the model, the dimension size and the posting text for semantic matching ([ADR 0026](adr/0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md)).

Run on 2026-10-29 with `php artisan eval:embeddings --details` and `--text=short --details`. OpenAI is the only embedding provider with a key in this project, so the comparison is between OpenAI models.

## Set-up

- **Corpus:** 267 live postings in 265 openings from Himalayas and Jobicy. Seeded postings (source `manual`) are left out. An opening scores as its best posting.
- **Queries:** 10 queries in [`tests/Evals/embedding-benchmark.json`](../tests/Evals/embedding-benchmark.json), 5 in English and 5 in Bahasa Indonesia, each with 3 to 6 relevant openings. Two queries are the same search in both languages ("remote backend developer PHP Laravel", "programmer PHP kerja remote"). The file names postings by source and external ID and holds no posting text ([ADR 0015](adr/0015-take-jobs-only-from-sources-whose-terms-allow-it.md), point 5).
- **Posting text:**
    - `full`: the title, the extracted skills, and the description cut at 12,000 characters.
    - `short`: the title and the extracted skills.
- **Metrics:** Hit@5 is the share of queries with a relevant opening in the top 5. Recall@10 is the mean share of relevant openings in the top 10. MRR is the mean of 1 / the rank of the first relevant opening.

## Results

**Full text** (281,162 tokens per configuration):

| Model                  | Dimensions | Hit@5 | Recall@10 | MRR  | Recall@10 en | Recall@10 id | Cost (USD) |
| ---------------------- | ---------- | ----- | --------- | ---- | ------------ | ------------ | ---------- |
| text-embedding-3-small | 1536       | 0.90  | 0.51      | 0.73 | 0.63         | 0.40         | 0.0056     |
| text-embedding-3-small | 512        | 0.70  | 0.50      | 0.74 | 0.57         | 0.43         | 0.0056     |
| text-embedding-3-large | 1024       | 1.00  | 0.63      | 0.81 | 0.76         | 0.50         | 0.0366     |
| text-embedding-3-large | 3072       | 1.00  | 0.62      | 0.83 | 0.74         | 0.50         | 0.0366     |

**Short text** (5,337 tokens per configuration):

| Model                  | Dimensions | Hit@5 | Recall@10 | MRR  | Recall@10 en | Recall@10 id | Cost (USD) |
| ---------------------- | ---------- | ----- | --------- | ---- | ------------ | ------------ | ---------- |
| text-embedding-3-small | 1536       | 1.00  | 0.88      | 0.85 | 0.80         | 0.95         | 0.0001     |
| text-embedding-3-small | 512        | 1.00  | 0.88      | 0.85 | 0.85         | 0.90         | 0.0001     |
| text-embedding-3-large | 1024       | 1.00  | 0.74      | 0.88 | 0.76         | 0.73         | 0.0007     |
| text-embedding-3-large | 3072       | 1.00  | 0.74      | 0.88 | 0.76         | 0.73         | 0.0007     |

Recall@10 per query, text-embedding-3-small at 1536 dimensions:

| Query                                      | Language | Full | Short |
| ------------------------------------------ | -------- | ---- | ----- |
| remote backend developer PHP Laravel       | en       | 0.67 | 1.00  |
| programmer PHP kerja remote                | id       | 0.67 | 1.00  |
| senior Java backend engineer with Kafka …  | en       | 1.00 | 0.67  |
| lowongan machine learning engineer …       | id       | 0.25 | 1.00  |
| React frontend engineer with TypeScript    | en       | 0.40 | 0.60  |
| developer aplikasi mobile Android atau iOS | id       | 0.67 | 1.00  |
| lowongan DevOps atau SRE, pengalaman …     | id       | 0.00 | 0.75  |
| data engineer building pipelines with …    | en       | 0.50 | 0.75  |
| dicari programmer Ruby on Rails            | id       | 0.40 | 1.00  |
| security engineer for offensive security … | en       | 0.60 | 1.00  |

The whole run, both texts and all four configurations, cost about USD 0.086. The SDK caches each embedding for 30 days, so a rerun costs nothing.

## What the benchmark shows

1. **The posting text matters more than the model.** On the short text, the small model finds 88% of the relevant openings in the top 10; on the full text, 51%. A description mixes the role with the company, the benefits and the hiring process, and a short query matches that average less well than the title and skills.
2. **Indonesian queries suffer most from long text.** On the full text, Recall@10 is 0.40 for Indonesian queries and 0.63 for English ones. On the short text, the Indonesian queries score 0.95. The DevOps query in Indonesian finds no relevant opening in the top 10 on the full text, and 3 of 4 on the short text.
3. **The large model helps only on the full text.** It lifts Recall@10 from 0.51 to 0.63 there, at 6.5 times the price. On the short text it scores lower than the small model (0.74 against 0.88).
4. **Fewer dimensions cost almost nothing.** The small model at 512 dimensions scores the same as at 1536 on the short text. The large model scores the same at 1024 and 3072.

**Limits.** The relevant openings were chosen from the title and the extracted skills, which is the short text. That favours the short text, so finding 1 is likely smaller than it looks here. Ten queries are a small sample: one query moves Hit@5 by 0.10, so a difference of less than about 0.10 is a tie. The short text also depends on the skill extractor: a posting with no extracted skills is embedded as its title only. The retrieval golden set (Day 25) must be labelled from the full posting to check finding 1 without this bias.

## Decision

text-embedding-3-small at 1536 dimensions, on the title and the extracted skills. The reasons and the consequences are in [ADR 0026](adr/0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md).

## Changing the model later

Vectors from two models cannot be compared, so a new model means a new embedding for every posting and every profile, and a new HNSW index ([ADR 0001](adr/0001-use-postgresql-with-pgvector.md), drawback 3). The change takes five steps:

1. **Add, do not replace.** Add a second vector column with its own model name, and its HNSW index, built with `CREATE INDEX CONCURRENTLY` and a raised `maintenance_work_mem` for that session. Reads stay on the old column.
2. **Write both.** The embedding job writes both columns for new and changed postings. Each vector is keyed by the text hash and the model, so a posting that has a current vector for a model is not embedded again.
3. **Backfill.** Queue the live postings on `ai-batch` under the batch budget ([ADR 0014](adr/0014-cap-the-daily-ai-spend-of-all-users.md)). Expired postings need no vector. At today's size, the backfill is about 5,000 tokens on the short text.
4. **Compare.** Run this benchmark and the golden set against both columns. Switch only on a measured gain.
5. **Switch and clean up.** Move reads to the new column with one config value, keep the old column for a rollback window, then drop it and its index. Profile embeddings are cached per profile version and model, so they are rebuilt on the first read after the switch.

A smaller size of the same model needs no new embeddings. OpenAI's `text-embedding-3` vectors can be shortened by keeping the first dimensions and normalizing again, which gives almost the same vector as the `dimensions` option: a cosine similarity of 0.9998 in a check on two queries. Storing 1536 dimensions keeps that option open; storing 512 would close it.
