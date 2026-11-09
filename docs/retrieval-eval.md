# Retrieval evaluation

`eval:retrieval` measures how well the matches page ranks openings for 20 fixture profiles ([ADR 0035](adr/0035-measure-retrieval-on-a-graded-golden-set-of-profiles.md)).

## Workflow

1. Pool the candidates, including the reranked lists (about $0.15 for 20 cases and 3 weightings). Every variant's top 10 that has no label gets a `null` grade in `tests/Evals/retrieval.json`:

    ```bash
    php artisan eval:retrieval --pool --with-rerank
    ```

2. Fill each `null` with the model grader. It reports facts about each posting, and the code maps them to 0 (not relevant), 1 (partly relevant) or 2 (clearly relevant), as in ADR 0035. A second run continues with the grades that are still `null`:

    ```bash
    php artisan eval:retrieval --grade
    ```

3. Check the grades. Grade about 40 labels by hand, sampled evenly across the model's grades, without seeing them. The grades pass at a linearly weighted kappa of at least 0.6, with at most 2 labels where one side says 0 and the other says 2. If they fail, change the rubric in a new prompt version and grade again.
4. Score the variants, with intervals, and save the run. The rerank results are cached for 24 hours, so a run soon after the pool pays nothing more for them:

    ```bash
    php artisan eval:retrieval --with-rerank --bootstrap=10000 --save
    ```

5. Pool and grade again after a ranking change, because a new variant can surface postings that nobody graded. The "Unjudged in top 10" column shows how many.

The command writes the golden set in PHP's JSON layout. Run `npx vp check --fix` before you commit it.

A Cohere trial key allows about 10 rerank calls a minute, so a pool or a score with `--with-rerank` can stop at a rate limit. Run it again after a minute: the reranked lists that finished are cached for 24 hours.

## Reading the table

- **nDCG@10** is the headline: it is the only metric that uses grade 1 and grade 2.
- **vs hybrid:count** is the paired difference in nDCG@10 and its 95% interval. An interval that includes 0 means the gain may be noise.
- **Label coverage** under 80% means many graded postings expired. Pool and grade again.

## Where the labels can mislead

A model grades the golden set and one person checks a sample. That person is also the user and the builder. That creates these biases:

1. **Taste is the target.** The rubric and the check encode one person's idea of a good fit. A user with another background would judge differently, and the ranking would be tuned to that view.
2. **The checker knows the system.** A builder who knows what the keyword leg finds can, without meaning to, grade its results higher: "this names Laravel, so it is relevant".
3. **The grader resembles the reranker.** Both read the profile and the posting together, so their errors may agree. That can flatter the reranked lists. A grader from a different model family reduces this, and the hand check shows whether it is happening.
4. **Pool bias.** Only what the current variants retrieve is judged. Relevant postings that no variant finds are invisible, which flatters the current variants and penalizes a new one.
5. **Small and correlated cases.** 20 profiles from one mind share blind spots, so the cases are not 20 independent draws.

Ways to make it trustworthy on a small budget:

1. **Fix the rubric and the pass rule before grading.** A rule chosen after seeing the agreement proves little.
2. **Grade blind.** The grader sees each case's postings in shuffled order, with no variant. The hand check hides the model's grades, and a label that someone saw the model grade cannot be used to check the grader.
3. **Sample evenly across the grades.** A random sample is mostly 0s, which makes the agreement number unstable.
4. **A second person on a slice.** Ask a peer to grade 2 or 3 cases (about 60 postings, an hour). Their agreement with you is the ceiling of what the eval can tell.
5. **Report intervals.** The bootstrap interval keeps a 2-point gain on 20 cases from being read as a win.
