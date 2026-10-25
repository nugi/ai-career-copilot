# 21. Key AI work on the posting text

- **Status:** Accepted
- **Date:** 2026-10-25

## Context

[ADR 0020](0020-ingest-job-sources-on-a-schedule.md) gives each posting a `content_hash`: the SHA-256 of the normalized row. It decides `updated_at` and the created, updated and unchanged counts, and ADR 0020 also names it as the key that lets skill extraction and embeddings skip unchanged postings. That one hash has two problems:

1. **It does not always describe the stored row.** A run that sends no salary, employment type or seniority keeps the stored values ([ADR 0017](0017-store-job-pay-as-posted.md)), but it wrote a hash computed without them. The run counted the row as updated even though nothing visible changed, and the next run with the salary counted it as updated again.
2. **It changes for reasons the AI work does not care about.** Skill extraction and embeddings read the title and the description. A salary that comes and goes, or a source that moves `posted_at` or `expires_at`, changes `content_hash` while the text stays the same. Each change would pay for another LLM call and another embedding for the same text.

## Decision

**Each posting has two hashes. `content_hash` describes the stored row and detects changes. `text_hash` describes the title and the description, and per-posting AI work is keyed on it.**

1. **`content_hash` is the hash of the row as stored.** Before the hash is computed, the import fills a missing salary group, employment type or seniority from the stored row. The upsert writes that merged row, so the PHP merge replaces the SQL `CASE` expressions that kept the values before.
2. **`text_hash`** is the SHA-256 of the JSON pair `[title, description]`, as stored. It never includes the salary, the dates, the places or the duplicate keys.
3. **Uses:**

    | Hash           | Moves when                     | Used for                                                           |
    | -------------- | ------------------------------ | ------------------------------------------------------------------ |
    | `content_hash` | any stored column changes      | `updated_at`, the created, updated and unchanged counts            |
    | `text_hash`    | the title or description moves | skipping skill extraction and embeddings when the text is the same |

4. **AI work records the `text_hash` it read.** A posting needs the work again only when its current `text_hash` differs from the recorded one, or when the prompt or model version that produced the result changes.

## Consequences

**Benefits**

1. A run without salary after a run with salary counts the row as unchanged, and `updated_at` stays put.
2. A salary or date change costs no AI call. Only a change to the text does.
3. Two postings with the same text, such as one job on two boards, have the same `text_hash`, so AI results can be shared between them.

**Drawbacks**

1. The import reads nine more columns of each stored row before each chunk. At a few hundred rows a run this costs little.
2. A whitespace change in a description changes `text_hash` and pays for the work again. Normalizing the text before hashing would save that but adds a rule that has to stay stable.

**Revisit when** AI work starts to read columns other than the title and the description, such as the seniority or the location. Its key must then include them.
