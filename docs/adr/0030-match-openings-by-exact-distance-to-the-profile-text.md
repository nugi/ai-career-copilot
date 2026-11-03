# 30. Match openings by exact distance to the profile text

- **Status:** Accepted
- **Date:** 2026-11-03

## Context

[ADR 0026](0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md) embeds a posting as its title and its skill names, and says the query side must look the same: the target roles and the top skills, not the resume summary in full. A long profile against a short job text would bring back the dilution that the short posting text removes. [ADR 0027](0027-embed-postings-in-batches-keyed-on-the-embedded-text.md) keys a vector on the model and the hash of its text.

The matches page ranks openings by meaning, with the hard filters still enforced: the work arrangement, the salary floor, live postings, and the country states of [ADR 0029](0029-read-the-countries-a-posting-accepts-from-its-text.md). [ADR 0001](0001-use-postgresql-with-pgvector.md) warns that an approximate index scan followed by a selective filter returns too few rows: about 1 posting in 300 passes the filters for a user in Indonesia.

## Decision

**Rank the openings that pass the filters by their exact cosine distance to a vector made from the profile's roles and top skills. Keep that vector on the profile, keyed on the hash of its text and the model.**

1. **Query text:** the target roles, then `Skills: ` and up to 10 skills, chosen by proficiency and then by name and listed by name. `ProfileEmbeddingText` builds it in the same shape as `PostingEmbeddingText`. The resume summary is not in it.
2. **Cache:** `profiles.match_embedding`, `match_embedding_model` and `match_embedding_hash`. The vector is current while the model and the hash of the text built now match the stored ones. `profiles.version` is not the key: it does not change with the model, and it changes for edits that leave the text as it was, such as a salary or a location. `match_embedding` is hidden from the model's array form, because the shared page props carry the whole user with its profile.
3. **When:** on the first visit to the matches page after the text or the model changes, inside the request, through `EmbedTexts`. There is no job and no pending state. A tripped global budget or a provider failure gives the page a `failed` state, and the failure is reported. The per-user budget is not checked: the usage row of an embedding call has no user, so that sum never counts it.
4. **Ranking:** `MatchOpenings` takes the filtered query of `SearchOpenings`, keeps the postings that have a vector, puts the result in a `MATERIALIZED` common table expression, and sorts it by `embedding <=> query` with a limit of 30. A materialized expression stops the planner from using the HNSW index on a filtered set, so the sort is exact. The HNSW index stays in place, and nothing uses it yet.
5. **One row per opening:** the vector of the representative posting ([ADR 0025](0025-list-openings-through-a-representative-posting.md)) is the one that counts. An opening whose representative posting has no vector does not appear, even if another posting of that opening has one.
6. **Match percent:** `round(100 × cosine similarity)`, between 0 and 100, on each row. It is not rescaled. In the five baseline searches the top 10 ran from 56% to 82% with a median of 73% ([`docs/baseline-searches.md`](../baseline-searches.md)), so the value separates a good match from a weak one. It is a similarity, not a probability.
7. **Country:** the profile's country ([ADR 0029](0029-read-the-countries-a-posting-accepts-from-its-text.md)), or the page's country select. An empty select means any country.

## Consequences

**Benefits**

1. The list is ordered by fit, and the hard filters hold exactly, because the filter runs before the sort and the sort does not use an approximate index.
2. A profile costs one embedding of about 30 tokens each time its text changes, and no call otherwise.
3. The vector cannot be stale: the model or the text decides, and the page checks both.

**Drawbacks**

1. **A posting that names the skill can rank low.** In the baseline, a posting titled "LAMP Stack Senior Developer + Laravel" is rank 35 of 158 for a profile with Laravel, below "Full Stack" titles. The vector places a posting by the meaning of its title and skill names, and it cannot require a word.
2. **A query can find a neighbour of the wanted skill.** A "Python developer" query can return a PHP developer. The two are backend roles with the same neighbours (SQL, REST, Docker, a web framework), and a vector expresses nearness, not a must-have. Hybrid search adds a keyword leg on the skills, and rank fusion lets a posting that has the word rise above one that is only near. In the baseline, the Python postings are at rank 4 and 11 of 14 for a Laravel profile.
3. **The list is always full.** With 14 postings passing the filters, the top 10 ends at 56–58% with front-end and DevOps roles. There is no minimum match. The golden set decides whether a cutoff helps.
4. **The first visit after a change waits for one embedding call**, and a provider failure shows an error state.
5. **The sort reads every filtered row.** That is cheap at this size. Revisit at about 20,000 live postings, with an iterative index scan or an over-fetch of the nearest rows before filtering, and measure the recall change.
6. **Only 10 skills reach the text.** A profile with a long list of skills loses its less proficient ones.
7. **The Indonesian results are mostly seeded postings** (7 of 10 in search 1), which say little about real text.

**Alternatives considered**

1. **A queued job on profile save:** it matches the resume pipeline, but it needs a pending state and polling on the page, and a path for the visit that comes before the job runs. Rejected for one embedding call.
2. **The resume summary in the text:** more of the person, but it brings the dilution of ADR 0026 to the query side, and a summary change would not change `profiles.version`. Rejected.
3. **The HNSW index with a post-filter:** fast, but it returns fewer than 30 rows when the filter is selective. Rejected for now.

**Revisit when** the retrieval golden set, which has 5 profile-shaped queries, measures another query text or a cutoff, live postings reach about 20,000, or hybrid search changes the ranking.
