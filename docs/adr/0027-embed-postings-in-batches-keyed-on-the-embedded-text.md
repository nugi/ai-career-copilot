# 27. Embed postings in batches keyed on the embedded text

- **Status:** Accepted
- **Date:** 2026-10-30

## Context

[ADR 0026](0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md) chose the text of a posting's vector: the title and the verified skill names. Every live posting needs that vector before semantic matching (Day 21), and a posting that has not changed must not pay for a second call.

The text depends on two things that change at different times:

- The posting text, which changes on import and is keyed by `text_hash` ([ADR 0021](0021-key-ai-work-on-the-posting-text.md)).
- The skill rows, which change without any import. Skill extraction writes them, `RematchUnknownSkills` adds rows when a skill is verified, renamed or given an alias, and a skill merge moves them ([ADR 0024](0024-extract-job-skills-once-per-posting-text.md)). One verified skill can change the text of many postings at once.

`text_hash` therefore cannot key the vector, and a hook in each writer would break the first time a new writer is added.

## Decision

**A posting's vector is current while its model and the hash of its embedded text, built now, match the stored ones. An hourly scan finds the postings that differ and embeds them in batches of 100 texts per request.**

1. **Text:** the title, then `Skills: ` and the posting's skill names in name order, or the title alone when it has none. `PostingEmbeddingText` builds it, and the embedding benchmark uses the same class, so the product and the benchmark cannot drift.
2. **When:** only a settled posting is embedded: live, with a skill extraction or a recorded failure for its current text (`skills_text_hash = text_hash`). A vector made before the skills arrive would be paid for twice.
3. **Key:** `embedding_model` and `embedding_text_hash` (sha256 of the text). `embedded_text` stores the text itself, so each vector shows what it holds.
4. **Finding pending postings:** `jobs:embed` runs hourly. It builds the text of every settled posting in chunks and compares the hash. That is about 20,000 rows at the ceiling in [ADR 0001](0001-use-postgresql-with-pgvector.md), with no provider call when nothing differs. It catches every writer of skill rows, including ones not written yet.
5. **One call per text:** before a request, a posting copies the vector of any posting with the same text hash and model. Inside a batch, each distinct text is sent once. The vector column is the cache: the SDK embedding cache is not used, because it would store every vector a second time.
6. **Batching and limits:** one request per queued job, up to 100 texts (`ai-routing.embeddings.batch_size`), on the batch queue. A `RateLimited` middleware allows 60 requests per minute. A rate-limit release does not count as a failure: the job retries for up to an hour and fails after 3 exceptions.
7. **Budget:** the scan and the job stop while the batch or global daily budget is spent ([ADR 0014](0014-cap-the-daily-ai-spend-of-all-users.md)). The postings stay pending, and the next run picks them up.
8. **Cost record:** every embedding call writes an `ai_usages` row with the feature `embedding`, the agent `Embeddings`, no user and no tier, from the SDK's embedding events.
9. **Backfill:** `jobs:embed --backfill` runs the same batches in the command process, with a progress bar. A second run reports nothing to embed and makes no call.
10. **Model change:** the provider, model and dimension size are in `config/ai-routing.php`. A new model makes every vector pending. The column and index steps are in [`docs/embedding-benchmark.md`](../embedding-benchmark.md), "Changing the model later".

## Consequences

**Benefits**

1. A posting is embedded once per distinct text and model. A skill verified for 500 postings costs 5 requests of about 20 tokens per text.
2. No writer of skill rows needs to know about embeddings.
3. A new posting has a vector within an hour of its skill extraction.

**Drawbacks**

1. The hourly scan reads every settled posting with its skills, even when nothing changed. At 20,000 postings that is one pass over the rows and the pivot. Revisit if it takes more than a few seconds.
2. A posting waits up to an hour after its skills are saved before it can match.
3. A vector can be stale for up to an hour after a skill change.

**Alternatives considered**

1. **Mark the vector stale in each writer:** no scan, but every writer of skill rows needs a hook, and a missed one leaves a stale vector that nothing finds.
2. **Embed in the skill extraction job:** no wait, but one request per posting instead of per 100, and `RematchUnknownSkills` changes would still need their own path.
3. **The SDK embedding cache:** the same saving, with a second copy of each vector in the cache table and a 30-day expiry that the column does not need.

**A role summary in the text (not now).** ADR 0026, alternative 4 asks whether a one-line summary of the description, made by the skill extractor, should join the text.

- **For:** it brings the product, the domain and the team back into the vector, for a few output tokens and no extra call.
- **Against:** it is model-written text, so it can drift between prompt versions. A new prompt version would re-embed every posting. It also adds back some of the dilution that made the short text win.

The Day 25 golden set measures it: title + skills against title + summary + skills, on the same pooled, blind labels. It joins the text only on a measured gain in Recall@10 above the noise of the set.

**Revisit when** the scan takes more than a few seconds, matching needs a vector sooner than the hourly run, or the golden set favours another text.
