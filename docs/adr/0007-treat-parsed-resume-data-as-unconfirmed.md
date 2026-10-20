# 7. Treat parsed resume data as unconfirmed

- **Status:** Accepted
- **Date:** 2026-10-20

## Context

After upload, a model reads the redacted resume text ([ADR 0006](0006-redact-contact-data-before-resume-text-is-stored.md)) and returns structured data: a summary, skills, experiences, education and years of experience. The skills feed `user_skills`, which fit scoring, skill gap plans and the advisor read.

Structured output guarantees the shape of the answer, not its truth. Three kinds of error pass a JSON schema:

1. **Wrong values with the right type.** "12 years of experience" for a resume with 7, because the summary says "10+ years" or overlapping jobs are counted twice.
2. **Invented content.** A skill the resume never names, inferred from a job title or a framework's ecosystem.
3. **Schema rules the provider does not enforce.** Anthropic's structured output drops `pattern`, `minimum` and `maxLength`, and OpenAI enforces the schema only in strict mode. A fallback provider can therefore return a date such as `2021-13` or `Present`.

The parse also writes into data the user owns. Before the parser, every row in `user_skills` was typed by the user with a proficiency. A resume names skills but not how well the person knows them, and the user can upload a corrected resume later.

## Decision

**The model extracts; code checks, derives and decides what to keep. Data from the resume stays marked as such until the user confirms it.**

- **Validate in PHP, retry once.** The output goes through a Laravel validator: every key present, dates as `YYYY` or `YYYY-MM`, no date in the future, end not before start. On failure the parser asks once more with the failed field paths and rules in a `<validation_errors>` block. A second failure leaves the resume's earlier parse in place and records `parse_failed_at` and `parse_errors` (paths and rule names only, never values). A retry is for invalid output; a provider outage is a failover ([ADR 0005](0005-fail-over-on-provider-outages-only.md)) or a queue retry, not a re-prompt.
- **Derive facts the model only estimates.** Years of experience are computed from the experience dates: intervals are merged so overlapping jobs count once, and a current job ends this month. The computed value is stored; the model's value is kept in `parsed_data.checks` and a difference of more than one year is flagged and logged.
- **Ground every skill in the text.** A skill is kept only when its name, its canonical name or one of its aliases appears in the resume as a whole word. Ungrounded skills are listed in `parsed_data.checks.ungrounded_skills` and are not saved to `user_skills`. Grounded names resolve through the alias table, so `postgres` becomes `PostgreSQL`; unknown names become unverified skills, as typed ones do.
- **Record where each skill came from.** `user_skills.source` is `profile` (typed by the user) or `resume` (found by the parser). A resume skill has no proficiency, and a CHECK requires one for profile skills. Only the primary resume writes skills. A new parse replaces the resume skills and never changes or removes a profile skill. Saving the profile form, which lists every skill and asks for a level, turns the listed skills into profile skills.
- **Version the prompt with the data.** `resumes.prompt_version` records the prompt that produced `parsed_data`, so a prompt change can find and re-parse old rows.

## Consequences

**Benefits**

1. Fit scoring and the advisor can tell confirmed skills from guessed ones, and a resume skill never overrides what the user said.
2. A wrong years-of-experience value is caught by arithmetic instead of by the user.
3. Re-uploading a corrected resume removes the skills that only the old resume named.
4. Validation does not depend on which provider answered.

**Drawbacks**

1. Grounding misses a skill written differently from every known name and alias, such as "Postgre". It is dropped until an alias is added.
2. Grounding does not catch a skill that appears in the text but not as the candidate's own, for example "migrated away from PHP". The user removes it in the profile form.
3. Year-only dates are counted as whole years (January to December), which can overstate experience by up to 22 months.
4. A second invalid answer costs a second call and still leaves no parse.

**Revisit when** fit scoring needs a confidence for each skill, when the parse moves to a second model for comparison, or when users can edit parsed experiences directly.
