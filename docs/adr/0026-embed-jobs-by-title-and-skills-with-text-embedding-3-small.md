# 26. Embed jobs by title and skills with text-embedding-3-small

- **Status:** Accepted
- **Date:** 2026-10-29

## Context

Semantic matching ranks openings by the distance between a query or a profile and each posting. The vector column (Day 18) needs a fixed dimension size, and the embedding job (Day 19) needs a model and a text. A change of model later means a new embedding for every posting and a new index ([ADR 0001](0001-use-postgresql-with-pgvector.md), drawback 3), so the choice is made on measurements, not on defaults.

The benchmark ([`docs/embedding-benchmark.md`](../embedding-benchmark.md)) compared text-embedding-3-small and text-embedding-3-large, each at two dimension sizes, on 10 queries in English and Bahasa Indonesia, with two posting texts: the title and the extracted skills, or the same with the description added.

## Decision

**Embed each posting as its title and its extracted skills with text-embedding-3-small at 1536 dimensions. The description is not in the vector.**

1. **Model:** text-embedding-3-small, at USD 0.02 per million tokens. On the short text it scores higher than text-embedding-3-large (Recall@10 0.88 against 0.74) at 1/6.5 of the price.
2. **Dimensions:** 1536. The benchmark shows no gain over 512, but the vectors can be shortened later by keeping the first dimensions and normalizing again, with no new call. They cannot be made longer without a new embedding. At 1536 dimensions, 20,000 live postings take about 120 MB, far under the limit in ADR 0001.
3. **Text:** the title, then `Skills:` and the verified skill names in name order. Seniority is left out until a measurement shows a gain. The description goes to full-text search (Day 22), where a long text does not dilute a match.
4. **Key:** a vector is current while its model and the hash of the embedded text match. A changed title, a new verified skill or a new model makes it pending.

## Consequences

**Benefits**

1. Recall@10 rises from 0.51 to 0.88 against the full text, and from 0.40 to 0.95 for Indonesian queries.
2. A posting costs about 20 tokens instead of about 1,000, so embedding all live postings costs less than a cent.
3. The dimension size can go down later without a new embedding.

**Drawbacks**

1. The relevant openings were chosen from the title and the skills, so the benchmark favours the short text. The gain can be smaller on a set labelled from the full posting.
2. The vector depends on the skill extractor ([ADR 0024](0024-extract-job-skills-once-per-posting-text.md)). A posting with no extracted skills is embedded as its title only, and a skill that the extractor misses is not in the vector.
3. Facts that only the description holds (the product, the domain, the team) do not reach semantic matching. Full-text search must cover them.
4. A new verified skill changes the text of many postings at once, and each of them is embedded again. At about 20 tokens each, that is cheap.

**Alternatives considered**

1. **text-embedding-3-large:** better on the full text (Recall@10 0.63 against 0.51), but the full text is the weaker text for both models. Rejected at 6.5 times the price.
2. **The full posting text:** about 50 times the tokens and the lowest recall in the benchmark. Rejected.
3. **512 dimensions:** the same scores and a third of the storage, but no way back to 1536 without a new embedding. Kept as a later option.

**Revisit when** the retrieval golden set (Day 25), labelled from the full postings, gives other results, or a second provider with an embedding key is available to compare.
