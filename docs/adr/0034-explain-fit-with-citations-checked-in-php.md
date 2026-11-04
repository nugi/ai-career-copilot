# 34. Explain fit with citations checked in PHP

- **Status:** Accepted
- **Date:** 2026-11-04

## Context

A ranked list says which jobs come first, not why. A user who reads "strong fit" needs to see the part of their own resume that makes it so, and needs an honest "weak fit" when nothing does. A model asked "why does this job fit?" will find a reason for any job, so the answer has to be tied to the resume and checked by code that does not trust the model.

The resume is already cut into chunks with vectors ([ADR 0028](0028-chunk-resumes-by-section-and-embed-them-after-the-parse.md)), and postings have vectors of the same model ([ADR 0026](0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md)).

## Decision

**"Why is this a fit?" retrieves the five chunks of the primary resume nearest to the posting, numbers them, and asks `FitExplainer` for a verdict, reasons that cite excerpt numbers, and missing requirements. PHP then removes every citation of a number that was not sent, drops every reason left with no citation, and lowers a fit verdict with no reason left to a weak fit.**

1. **Retrieval:** exact cosine distance between the posting's vector and the chunks of the user's primary resume only, nearest first, 5 chunks. A posting without a current vector is embedded on the spot. A resume without embedded chunks gets "still being processed", and a user without a parsed resume gets "upload a resume".
2. **Agent:** `FitExplainer`, prompt `fit-explainer/v1.md`, on the cheap tier. Strict structured output: `verdict` (`strong_fit`, `partial_fit`, `weak_fit`), `summary`, `reasons[]` of `{claim, excerpt_ids[]}`, `missing_requirements[]`. The prompt asks for claims that the cited excerpts state directly, and for a weak fit when the job is in another field. The output is validated, and an invalid answer is retried once with its errors.
3. **Post-check:** a citation counts only when its number names a sent excerpt. A reason without one is dropped and counted. At most 5 reasons and 5 missing requirements are kept. If no reason is left and the verdict is not a weak fit, the verdict becomes a weak fit and the page says so. The model cannot claim a fit that the resume does not back.
4. **Display:** each reason is shown with the full text of the excerpts it cites, and the count of dropped reasons is shown under them. The page draws the posting at once and loads the explanation as a deferred prop.
5. **Cost and limits:** the call counts in the user's daily budget and in the global budget, and a spent budget gives "used today's AI budget". The route shares the advisor's rate limit (10 a minute). The answer is cached for 24 hours under the hash of the user, the posting text, the excerpts and the prompt version.
6. **Faithfulness sample:** a citation proves that the excerpt was retrieved, not that it supports the claim. `faithfulness_sample_rate` (10% by default) of new answers queue `CheckFitFaithfulness` on the batch queue. `FaithfulnessJudge` judges each claim against the text of the excerpts it cites, and the share of supported claims is logged as `fit.faithfulness` with the unsupported claims. The user never waits for it, and it stops at the batch budget.

## Consequences

**Benefits**

1. Every reason on the page points to a real excerpt of the user's own resume, and the user can read it.
2. A fit claim with nothing behind it cannot reach the page.
3. The faithfulness score gives a number to watch per prompt version, at about a tenth of the cost of judging every answer.

**Drawbacks**

1. **Five excerpts can miss the evidence.** A skill listed only in a chunk outside the nearest five is invisible, and the answer may list it as missing. The skills chunk is usually near a posting with skills, but this is not guaranteed.
2. **The post-check cannot read.** A claim that cites a real excerpt and misstates it passes. Only the sampled judge catches it, after the user saw it.
3. **A second model judging the first** shares its blind spots, so the score is an estimate, not ground truth. Hand labels on a few answers would calibrate it.
4. **The log is the only store.** Scores are not in a table yet, so a trend needs a log query.
5. **Resume text goes to the model provider.** It is the redacted text ([ADR 0006](0006-redact-contact-data-before-resume-text-is-stored.md)), the same as for the advisor.

**Alternatives considered**

1. **Quote spans instead of excerpt numbers:** the model would copy sentences, and PHP would check them with `QuoteGrounding`. Stronger, but long quotes cost output tokens, and a paraphrase fails the check. Kept as an option if the faithfulness score is low.
2. **Judge every answer:** doubles the cost of each answer. Rejected for a sample.
3. **Send the whole resume:** no retrieval step, but more tokens, and the citations would point to the whole document. Rejected.

**Revisit when** the faithfulness score falls below about 0.9 for a prompt version, or the fit score arrives and should share its reasons with this page.
