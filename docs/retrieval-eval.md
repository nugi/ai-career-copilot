# Retrieval evaluation

`eval:retrieval` measures how well the matches page ranks openings for 20 fixture profiles ([ADR 0035](../adr/0035-measure-retrieval-on-a-graded-golden-set-of-profiles.md)).

## Workflow

1. Pool the candidates. Every variant's top 10 that has no label gets a `null` grade in `tests/Evals/retrieval.json`:

    ```bash
    php artisan eval:retrieval --pool
    ```

2. Grade each `null` by hand: 0 (not relevant), 1 (partly relevant), 2 (clearly relevant). Judge the posting against the case's profile, not against the mode that found it. Set `labelled_on` and `labelled_by`.
3. Score the variants, with intervals, and save the run:

    ```bash
    php artisan eval:retrieval --bootstrap=10000 --save
    ```

4. Add the reranker when the Cohere key is set (about $0.15 for 20 cases and 3 weightings):

    ```bash
    php artisan eval:retrieval --with-rerank --bootstrap=10000 --save
    ```

5. Pool again after a ranking change, because a new variant can surface postings that nobody graded. The "Unjudged in top 10" column shows how many.

## Reading the table

- **nDCG@10** is the headline: it is the only metric that uses grade 1 and grade 2.
- **vs hybrid:count** is the paired difference in nDCG@10 and its 95% interval. An interval that includes 0 means the gain may be noise.
- **Label coverage** under 80% means many graded postings expired. Pool and grade again.

## Drill: one labeller

The golden set is labelled by one person, who is also the user and the builder. That creates these biases:

1. **Taste is the target.** The grades encode one person's idea of a good fit (their seniority, their stack, the companies they like). A user with another background would grade differently, and the ranking would be tuned to the labeller.
2. **The labeller knows the system.** A builder who knows what the keyword leg finds can, without meaning to, grade its results higher: "this names Laravel, so it is relevant".
3. **Pool bias.** Only what the current variants retrieve is judged. Relevant postings that no variant finds are invisible, which flatters the current variants and penalizes a new one.
4. **Drift within the session.** The 300th grade is given more quickly and less carefully than the 30th, and the grading of 1 versus 2 moves over time.
5. **Small and correlated cases.** 20 profiles from one mind share blind spots, so the cases are not 20 independent draws.

Ways to make it trustworthy on a small budget:

1. **Write a rubric before grading.** For example: grade 2 needs the role and the main skill to match; grade 1 needs one of the two; seniority more than one level away caps it at 1. Read it again every 50 grades.
2. **Grade blind.** Shuffle the pool and hide which variant found each posting. The pool format already has no variant column.
3. **Re-grade a sample.** A week later, grade 10% of the labels again without looking. Agreement (Cohen's kappa) below about 0.6 means the rubric is too vague.
4. **A second labeller on a slice.** Ask a peer to grade 2 or 3 cases (about 60 postings, an hour). Their agreement with you is the ceiling of what the eval can tell.
5. **An LLM as a second opinion, not ground truth.** Disagreements between it and you are the postings to look at again. It shares blind spots with the ranking, so it never replaces you.
6. **Report intervals.** The bootstrap interval keeps a 2-point gain on 20 cases from being read as a win.
