# 28. Chunk resumes by section and embed them after the parse

- **Status:** Accepted
- **Date:** 2026-10-30

## Context

Fit scoring and the advisor need the parts of a resume that answer a question: the job that used Kubernetes, not the whole document. The `resume_chunks` table has held a vector column since [ADR 0026](0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md), and chunks are searched inside one resume only, without an index ([ADR 0001](0001-use-postgresql-with-pgvector.md)). Nothing wrote chunks yet.

Two ways to cut a document into chunks:

- **Fixed size with overlap:** windows of N tokens that overlap by M. It works on any text and needs no parser. A window can start in one job and end in the next, so its vector mixes two roles, and a window in the middle of a bullet list does not say which job the bullets belong to.
- **By structure:** one chunk per unit the reader thinks in. It needs a parse, but each chunk is one job, one degree or one skill list.

A resume is short (one or two pages) and has a fixed structure, and the app already parses it into `parsed_data` ([ADR 0007](0007-treat-parsed-resume-data-as-unconfirmed.md)). That parse runs on the redacted text ([ADR 0006](0006-redact-contact-data-before-resume-text-is-stored.md)), so text built from it holds no contact data.

Users upload a new resume when they change one line. The new upload is a new resume row, and most of its text is the same as before.

## Decision

**Chunks are built from `parsed_data`, one per section unit, each with a prefix that makes it clear on its own. A chunk's vector is keyed on its text, and a chunk with the same text as one of the user's embedded chunks copies that vector.**

1. **Chunks:** in this order:
    - the summary (`Summary: …`);
    - one chunk per experience (`Experience as {title} at {company} ({start}–{end or present}):` and one `- bullet` per line);
    - the skills (`Skills: A, B, C`);
    - one chunk for all education entries.

    An empty section has no chunk. A typical resume gives 6 to 15 chunks.

2. **Long experiences:** an experience over 120 estimated tokens is split between bullets into groups of about 2 or 3 bullets. Each group repeats the prefix, so no chunk is a list of bullets without its job. A bullet is never cut. The limit is small on purpose. A senior engineer can list 8 projects under one employer, and one vector for all of them matches none of them well. With section chunks only, a resume with one employer gave 4 chunks. With this limit it gives 8: 5 of them are groups of 1 or 2 bullets (77 to 106 tokens each).
3. **Metadata:** `section`, `position` and `resume_id`, plus `period_start` and `period_end` in the parse's `YYYY` or `YYYY-MM` format. An experience with a null end is current. The education chunk spans the earliest start to the latest end. `token_count` is an estimate of 4 characters per token, because the provider reports usage for a whole request, not per text.
4. **Always in step with the parse:** `ParseResume` rebuilds the chunks in the transaction that saves `parsed_data`, under the user lock it already takes. A fresh parse, a parse copied to a duplicate upload, and the parse command all write chunks. Chunks never describe an older parse.
5. **Vector key:** the chunk text is the embedded text, so `content` and `embedding_model` are the key, and no hash column is needed. Before a rebuild deletes the old chunks, it loads the user's chunks (all their resumes) that have a vector for the current model. A new chunk with the same text takes that vector. If one bullet changes, only that experience is embedded again. A duplicate upload costs nothing. Chunks of other users are never read.
6. **When:** after a successful parse, `ParseUploadedResume` queues `EmbedResumeChunks` on the batch queue for the resume and for each duplicate that received a copy. The job copies any vector that appeared since the rebuild, then sends the remaining pending texts in one request (at most about 15). It shares the `embeddings` rate limit with posting batches.
7. **Budget:** resume embedding is not stopped by the daily budgets. It is part of the upload that the user waits for, like resume parsing ([ADR 0014](0014-cap-the-daily-ai-spend-of-all-users.md)), and one resume costs about 1,500 tokens ($0.00003 at current prices). Its cost still counts in the batch and global sums.
8. **Cost record:** the SDK's embedding events write the `ai_usages` row, as for postings ([ADR 0027](0027-embed-postings-in-batches-keyed-on-the-embedded-text.md)). The row has no user, because the event does not carry one.
9. **Sweep:** `resumes:embed` runs hourly. It rebuilds the chunks of parsed resumes and embeds the pending ones. A failed job, a model change or a resume parsed before this change is fixed within an hour. When nothing is pending, it makes no call. `--resume=ID` runs it for given resumes.

## Consequences

**Benefits**

1. Each chunk is one unit with its context. A search for "Kubernetes" returns the job where it was used, with the title, the company and the dates.
2. Periods are columns, so later retrieval can prefer recent experience without parsing the text again.
3. An edited upload pays only for the chunks whose text changed.

**Drawbacks**

1. Chunks are only as good as the parse. Text that the parser does not map to a field is not in any chunk: projects, certifications, publications and awards have no field in `parsed_data` yet. A free-form portfolio or an academic CV with long publication lists parses into sparse data and loses most of its text. Fixed-size chunks of the raw text would keep it.
2. A long summary or a long skills list is never split. Neither has a natural boundary. A summary of about 100 tokens and a list of 30 skills stay one chunk each.
3. Bullets are grouped in order, not by topic. Two unrelated projects can share a chunk, and two bullets about one project can end up in different chunks.
4. Usage rows for resume vectors have no user, so the per-user daily spend does not include them. At about $0.00003 per resume, that is below the precision of the budget.

**Alternatives considered**

1. **Fixed-size windows of the redacted text:** keeps all text, but windows cross job boundaries and lose the job a bullet belongs to.
2. **One chunk per bullet:** the most precise match. The prefix keeps the job and dates, but a bullet loses its neighbours, and a resume with 5 jobs would have about 28 vectors.
3. **One chunk per experience, split only near the input limit (500 tokens):** fewer vectors, but a long role becomes one vector that mixes all its projects.
4. **A jsonb `metadata` column:** more flexible, but later queries filter on periods. Columns make those filters plain SQL.

**Revisit when** the retrieval golden set shows that a different split limit finds more relevant chunks, the parse gains projects or certifications (add a section for each), retrieval evaluations show that misses come from text outside the parse (add a fallback with fixed-size chunks of the raw text), or per-user spend must include embeddings.
