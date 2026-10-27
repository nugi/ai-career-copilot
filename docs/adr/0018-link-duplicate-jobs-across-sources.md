# 18. Link duplicate jobs across sources

- **Status:** Accepted
- **Date:** 2026-10-24
- **Amended:** 2026-10-25 (record the reason for each link)
- **Amended by:** [0019](0019-give-each-job-a-stable-identity.md) (a stable job identity replaces the canonical row)

## Context

Himalayas and Jobicy both list remote jobs, and many employers post to both. The same job then arrives twice, with different IDs, different apply links and slightly different text: "Sr. Backend Engineer" at "Northwind Systems Inc." for "United States" on one, "Senior Backend Engineer" at "Northwind Systems" for "USA" on the other.

`unique (source, dedup_key)` ([docs/erd.md](../erd.md)) stops duplicates inside one source. It cannot see across sources, because the IDs differ.

A user must see each job once. Matching and embeddings must also handle each job once, or the user pays twice for the same work. But each source's terms require its own apply link and its own expiry sync ([ADR 0015](0015-take-jobs-only-from-sources-whose-terms-allow-it.md)), so a source's row cannot simply be dropped.

## Decision

**Store every source's row, and link the rows of one job to a canonical row.**

1. **Duplicate key:** the normalizer computes `duplicate_key` = SHA-256 of three normalized parts:
    - company: transliterated to ASCII, lowercase, punctuation removed, legal forms removed (`Inc`, `GmbH`, `PT`, `Tbk`, …);
    - title: the same cleaning, gender markers removed (`(m/w/d)`), abbreviations expanded (`Sr` → `senior`, `Eng` → `engineer`), `front end` → `frontend`;
    - location: the sorted ISO country codes, else the region, else `unknown`. Country names in any locale and common aliases resolve to codes, so "United States" and "USA" agree.
2. **Link, do not delete:** after each import, one statement sets `duplicate_of_id` on every live posting. Rows with the same key form a group, and the row with the lowest ID is its head. Reads that list, match or embed jobs use only rows where `duplicate_of_id` is null (`JobPosting::canonical()`).
3. **Near duplicates (pg_trgm):** a group head links to an earlier head when all of these hold: same company, same location key, trigram similarity of the titles at or above 0.75, the same role and level words in both titles (backend, frontend, data, mobile, …; junior, senior, staff, lead, …), and no conflicting seniority. This catches "Senior Backend Engineer, Laravel" and "Senior Backend Engineer (Laravel/PHP)" (similarity 0.89). Level words are needed because sources often leave seniority unset: on a live sample, all three links made without them joined "Software Engineer" to "Senior Software Engineer" or "Staff" to "Senior Staff".
4. **Expiry moves the link:** only live postings take part. When a canonical row expires, the next import makes the next row of its group canonical.
5. **Idempotent:** the statement changes only rows whose value differs, so a second import with the same data changes no links.

## Consequences

**Benefits**

1. Each job shows once, and the work done for each job (embeddings, fit scores) is done once.
2. Every source keeps its own row, apply link and expiry, so each source's terms are met.
3. A wrong link is cheap to undo: change the rule and run the statement again. No data was deleted.

**Drawbacks**

1. Exact keys miss reworded titles ("Backend Developer" vs "Backend Engineer") and companies with different names on each board ("Meta" vs "Meta Platforms").
2. Trigram similarity can still merge different jobs at one company that differ by a word outside the role list ("Payments" vs "Platform"). The threshold and the role list trade missed duplicates against false merges.
3. One level of linking is followed. A chain of three near-duplicates can leave two canonical rows.

**Alternatives for a later change**

- **Embedding similarity** of the title and the first part of the description, within the same company: catches rewording, and costs one embedding per posting, which the embedding pipeline computes anyway. It needs a labelled set of pairs to choose its threshold.
- **A model as the judge** for pairs in a grey band of similarity only: highest accuracy, but it costs a call per pair and needs the spend cap of [ADR 0014](0014-cap-the-daily-ai-spend-of-all-users.md).

## Amendment (2026-10-25)

The threshold and the role list need labelled pairs to tune, and the three false links in the first live sample were the first ones. Every link now records why it was made, so each review of links adds to that set.

- `duplicate_reason` is `exact` for a row whose duplicate key matches its canonical row, and `trigram` when its group head was linked by title similarity.
- `duplicate_similarity` holds the trigram similarity, rounded to three decimals, for `trigram` links only.
- CHECKs keep the columns in step: a link has a reason, and only a `trigram` link has a similarity.
- A change of reason or similarity counts as a link change, so a second run with the same data still changes no rows.

**Revisit when** users report the same job twice, a new source has companies under other names, or job embeddings make the embedding check nearly free.
