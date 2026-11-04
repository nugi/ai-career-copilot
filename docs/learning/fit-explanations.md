# Fit explanations with citations

"Why is this a fit?" on the matches page explains one posting against the user's own resume ([ADR 0034](../adr/0034-explain-fit-with-citations-checked-in-php.md)).

## Pipeline

1. The five chunks of the primary resume nearest to the posting's vector become excerpts 1 to 5.
2. `FitExplainer` returns a verdict, a summary, reasons that cite excerpt numbers, and missing requirements.
3. PHP removes citations of numbers that were not sent, drops each reason left with none, and lowers a fit verdict with no reason left to a weak fit.
4. The page shows each reason with the excerpts it cites.
5. 10% of new answers queue a faithfulness check that logs the share of supported claims.

## How to test it with an unfit job

Open the matches page, pick a posting outside your field (or create one with `JobPosting::factory()` in Tinker, for example "ICU Registered Nurse"), and open `/matches/{id}/fit`. The answer should be a weak fit with the role's requirements listed as missing. If it says "strong fit" with no excerpt under a reason, the post-check failed, and the page would have lowered it.

## Drill: the citation exists, but the claim misrepresents the excerpt

The post-check proves only that a cited excerpt was retrieved. "You ran Kubernetes in production" can cite an excerpt that says "Skills: Docker". Measuring this is measuring **faithfulness**: is each claim entailed by the text it cites?

Ways to measure it automatically, cheapest first:

1. **Lexical overlap:** the share of the claim's content words (skills, numbers, nouns) that appear in the cited excerpts. Free and instant, but it misses paraphrase and a negation, and it passes "You led Kubernetes" against "Kubernetes was used by another team". Useful as a cheap alarm on new technology names that appear only in the claim.
2. **An entailment model (NLI):** a small cross-encoder scores whether the excerpt entails the claim. About 10 ms per claim on a CPU, no API cost, and good at negation. It needs a model to host.
3. **An LLM judge:** a second prompt reads each claim with its excerpts and answers supported or not. The most accurate of the three on technical text, and the easiest to start. This is what `CheckFitFaithfulness` does.

Cost of the LLM judge per answer, on the cheap tier ($0.10 per million input tokens and $0.50 per million output tokens): up to 5 claims with their excerpts are about 1,500 input tokens, and the verdicts about 100 output tokens. That is $0.00015 + $0.00005 = **about $0.0002 per judged answer**, roughly the cost of the explanation itself, so judging every answer doubles the cost. At a 10% sample it adds about 10%.

The score is per prompt version: the share of claims judged supported. A judge from the same model family shares the explainer's blind spots, so calibrate it once with hand labels on 30 to 50 claims. Measure the judge's precision and recall for "unsupported" before trusting a trend.

## Faithfulness log

`fit.faithfulness` log lines carry `job_posting_id`, `prompt_version`, `claims`, `supported`, `score` and the `unsupported` claims. To read the score for a prompt version, filter the log on `fit.faithfulness` and average `score` by `prompt_version`.
