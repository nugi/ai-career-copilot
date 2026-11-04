# 35. Measure retrieval on a graded golden set of profiles

- **Status:** Accepted for the method; the default mode waits for the first baseline run
- **Date:** 2026-11-04

## Context

The ranking has grown a keyword leg, a semantic leg, a fusion and a reranker ([ADR 0030](0030-match-openings-by-exact-distance-to-the-profile-text.md) to [ADR 0033](0033-rerank-the-hybrid-top-30-with-cohere-behind-a-flag.md)). Each step was judged by eye on five searches and two coarse marks (`docs/baseline-searches.md`). In two of the five searches every mode scored 10 of 10, so the marks could not tell the modes apart. The keyword leg also has an open choice: a plain count of terms, or a weight by rarity (IDF), or by rarity and proficiency (the Day 22 review).

The embedding benchmark ([ADR 0026](0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md)) measured typed queries. The matches page has no search box: it ranks by the profile, after the hard filters. A measure of the page has to use profiles and the real pipeline.

## Decision

**`eval:retrieval` runs 20 fixture profiles through the real filters and ranking in every mode, and scores each list against graded labels with recall@10, MRR and nDCG@10. nDCG@10 is the headline. The default mode changes only when the paired bootstrap interval of its nDCG@10 gain over hybrid excludes zero.**

1. **Cases:** `tests/Evals/retrieval.json`, 20 profiles (roles, skills with proficiency, arrangements, country, floor). The first five are the baseline searches. The others cover Python, React, Java, ML, mobile, DevOps, data, Rails, security, C#/.NET, Node.js, Go, QA, design, a junior profile, a lead profile and a near-empty profile. A case builds an unsaved profile (`GoldenProfile`), so nothing is written.
2. **Labels:** `source:external_id`, which survives the rebuild of opening IDs, with grade 0 (not relevant), 1 (partly) or 2 (clearly). An unlabelled result counts 0.
3. **Pooling:** `--pool` adds every unlabelled posting from the top 10 of any variant with a null grade, for grading by hand. Every variant is judged on the same pool, so no mode gains from being the one that was labelled.
4. **Eligibility:** only labels whose posting is live and passes the case's hard filters count. A filtered-out posting cannot be retrieved by any mode, so counting it would lower every mode by the same amount and hide differences.
5. **Metrics,** each over the cases that have a relevant label:
    - recall@10: relevant (grade 1 or 2) postings in the top 10, divided by all eligible relevant ones;
    - MRR: 1 over the rank of the first posting with the case's top grade;
    - nDCG@10: gain `2^grade - 1`, discount `log2(rank + 1)`, divided by the ideal order of all eligible labels.
6. **Variants:** semantic; keyword and hybrid with each weighting (`count`, `idf`, `idf_proficiency`); and with `--with-rerank`, each hybrid reranked. The weighting is a new `KeywordWeighting` option of `MatchOpenings`. `count` stays the default and gives the earlier order exactly.
7. **IDF:** a term weighs `ln(N / df)` over the filtered set, where N counts the filtered openings and df those that contain the term. With proficiency, the weight is multiplied by 1.0 (expert or a target role), 0.85, 0.7 or 0.5 (beginner). Proficiency tilts the weight; it does not override rarity.
8. **Saved runs:** `--save` writes `docs/evals/retrieval/{date}-{sha}.json` with the git SHA, the embedding and rerank models, RRF k, the weightings, the corpus size, the label coverage, the summary and the per-case scores. The files are committed, so the history is in git.
9. **Coverage:** postings expire. The command reports the share of graded labels that are still live, and warns under 80%. Below that, pool and grade again.
10. **Noise:** `--bootstrap=N` resamples the cases with a fixed seed. It gives a 95% interval for each variant's nDCG@10, and a paired interval for its gain over `hybrid:count`. With 20 cases, a gain of a few points is likely noise.

## Consequences

**Benefits**

1. The modes, the weightings and the reranker are compared on one number that uses graded labels, on the same pool.
2. Every run is reproducible from its file: the SHA, the settings and the seed.
3. A change to the ranking can be checked against the saved baseline before it ships.

**Drawbacks**

1. **One labeller.** The grades are one person's view of relevance (see `docs/learning/retrieval-eval.md`).
2. **Pool bias.** A posting that no variant put in its top 10 is never judged. A new variant that finds such postings is penalized until they are pooled and graded. The command counts the unjudged postings in each top 10.
3. **20 cases** give wide intervals.
4. **The corpus moves.** Results from different days have different postings, so a trend mixes ranking changes with corpus changes. The saved file records the corpus size, not the corpus.
5. **The rerank variants cost money** (about $0.0025 per case and variant) and need the Cohere key.

**Alternatives considered**

1. **Typed queries like the embedding benchmark:** they do not exercise the filters or the profile terms. Rejected.
2. **Binary labels:** recall and MRR only, and no way to rank a clear fit above a partial one. Rejected.
3. **A frozen corpus snapshot:** exact but heavy, and it ages. Kept as the next step if coverage falls fast.
4. **LLM-graded labels:** cheap, but they would grade with the same model family the ranking uses. Rejected as the ground truth; possible as a second opinion.

**Revisit when** the first baseline run is graded (amend this record with the table and the default), coverage falls under 80%, or a second labeller is available.
