# 31. Fuse keyword and semantic ranks with Reciprocal Rank Fusion

- **Status:** Accepted; partly superseded by [0032](0032-rank-the-whole-filtered-set-and-label-text-based-restrictions.md) (points 3, 6 and 10: technical names are normalized, each leg ranks the whole filtered set, and no match percent is shown)
- **Date:** 2026-11-04

## Context

[ADR 0030](0030-match-openings-by-exact-distance-to-the-profile-text.md) ranks the matches page by meaning only. Its first two drawbacks are the reason for this record: a posting that names the wanted skill can rank low, and a query can find a neighbour of the wanted skill. In the baseline, "LAMP Stack Senior Developer + Laravel" ranks 35th of 158 for a profile with Laravel, below generic "Full Stack" titles ([`docs/baseline-searches.md`](../baseline-searches.md)).

[ADR 0001](0001-use-postgresql-with-pgvector.md) says that hybrid search stays in plain SQL. [ADR 0026](0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md) keeps the description out of the vector and sends it to full-text search.

## Decision

**Rank the openings that pass the filters twice, once by meaning and once by the profile terms that the posting contains. Fuse the two ranks with Reciprocal Rank Fusion (RRF) in one SQL statement.**

1. **Text column:** `job_postings.search_vector` is a stored generated `tsvector`: the title with weight A, the skill names with weight B and the description with weight C, all with the `simple` configuration. `simple` does no stemming, so it does not mangle text in a language other than English, and the app serves users in any country.
2. **Skill source:** a generated column cannot read the pivot table, so the skill names come from the `Skills:` line of `embedded_text`, the text that the vector was made from. The two legs therefore see the same skills. The column reads `split_part`, not a regular expression, so a posting without the line gets an empty part and not a NULL vector.
3. **Terms:** the target roles and the top 10 skills of the profile, the same choice as the vector text (`ProfileEmbeddingText`). Each term becomes a phrase with `phraseto_tsquery('simple', term)`. No query syntax passes from the profile into the query. A term that has no tokens is dropped.
4. **Keyword rank:** the number of distinct terms that a posting contains, then `ts_rank(..., 32)`, then the newest ID. `ts_rank` alone is not used: it has no inverse document frequency, so a generic word that a posting repeats ("engineer") beats a skill named once. A trial query gave 0.271 for a title that repeats "engineer" and 0.049 for "Laravel" named once in the skills.
5. **Semantic rank:** unchanged. The exact cosine distance of [ADR 0030](0030-match-openings-by-exact-distance-to-the-profile-text.md).
6. **Fusion:** the score is the sum of `1 / (60 + rank)` over the legs where a posting has a rank. Each leg keeps its first 50 postings, and the page shows 30. A posting in one leg keeps its place; a posting in both legs rises. Ties go to the smaller distance, then to the newer ID.
7. **Filters first:** the hard filters run inside the filtered set, before both legs. The filters do not run after the fusion, as a hybrid design often does. ADR 0030 measured what a cut before the filters costs: the nearest 30 postings keep 1 of the 14 passing Indonesian rows, and the nearest 120 keep 2.
8. **Modes:** keyword, semantic and hybrid are the same statement with different inputs. Keyword mode passes no vector and makes no provider call. Semantic mode passes no terms and returns what it returned before. Hybrid mode passes both, and when the profile embedding fails it still returns the keyword leg.
9. **Default:** hybrid, because it was at least as good as the better single mode on the baseline searches (`docs/baseline-searches.md`, "Hybrid ranking").
10. **Match percent:** still the cosine similarity of the profile text and the posting text. It shows for every row that has a vector, in any mode that uses the vector. RRF scores are not shown: a rank carries no size of match.

**Why ranks and not scores.** `ts_rank` has no upper bound, and it depends on the length of the text, the weights and the normalization flag. A cosine similarity sits in a narrow band: here the top 10 run from 56% to 82%. If the two numbers are added, the leg with the wider spread decides the order, and the balance changes when the corpus, a weight or a flag changes. Each leg would need its own scaling, tuned to the data. A rank has no scale. RRF needs only the order inside each leg, and the constant 60 keeps the top rank of one leg from outweighing agreement between the legs.

## Consequences

**Benefits**

1. A posting that names a profile skill rises: "LAMP Stack Senior Developer + Laravel" goes from rank 35 to rank 13.
2. The hard filters hold for both legs, and semantic mode returns what it returned before.
3. Keyword mode works when the provider is down.

**Drawbacks**

1. **Terms are equal and the leg is short.** The keyword leg keeps 50 postings. A posting that names Laravel and PHP and no other profile term ("Software Engineer(s) - Poland") is keyword rank 51 of 106, one past the cut: 27 postings name more of the eight terms, and 23 name as many and rank higher on `ts_rank`. Hybrid mode then gives it no keyword credit, and it stays outside the first 50 rows, as it is in semantic mode (rank 42). The profile has no weights per skill.
2. **The `simple` configuration matches only the same token.** "Backend Engineer" does not match "Backend Software Engineer", because a phrase needs adjacent words. "C++" and "C#" both become the token `c`, ".NET" becomes `net`, and "Node.js" does not match "NodeJS".
3. **Skills reach the column only after the posting is embedded.** `embedded_text` is written by the embedding job. While it is behind, a new posting is searchable by its title and description only.
4. **Rank hides size.** The first posting of a weak list scores as well as the first posting of a strong list. The list is still always full, as in ADR 0030, drawback 3.
5. **No full-text index.** The statement reads the materialized set of filtered rows, so a GIN index on the column could not be used. Computing the vector for each row took about 0.6 ms, so the column is stored. Revisit with the 50,000-row performance test.
6. **The constants are not tuned.** The 60, the 50 and the equal weight of the legs are the usual defaults. The retrieval evaluation decides whether other values help.
7. **The query is the profile.** A user cannot ask for "Python" from a Laravel profile on the matches page. The copilot's `SearchJobs` tool takes a typed role, which replaces the target roles in both legs; the skills stay the profile's ([agent design](../agent-design.md)).

**Alternatives considered**

1. **Add `ts_rank` and the cosine similarity:** rejected, for the reason above.
2. **Filters after the fusion:** rejected, for the loss in point 7 of the decision.
3. **The `english` configuration:** it stems English well and damages other languages. Rejected for a global app.
4. **`ts_rank` as the keyword rank:** rejected, for the generic words in point 4 of the decision.

**Revisit when** the retrieval golden set measures the three modes, a typed query exists, or live postings reach about 20,000.
