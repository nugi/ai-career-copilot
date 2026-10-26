# 24. Extract job skills once per posting text

- **Status:** Accepted
- **Date:** 2026-10-26

## Context

Matching, fit scores and skill gaps need the skills each job asks for, split into required and nice to have, and the level it asks for. Job sources send free text, so a model reads it. The extraction runs on every new posting every day, so it must be cheap, and a repeat run must not pay again.

Three things can go wrong with the answer:

- The model lists a skill that the text does not name, because the role usually needs it.
- The model spells a known skill another way: `postgres`, `k8s`.
- The model names a real skill that the catalogue does not hold yet.

Resume parsing ([ADR 0007](0007-treat-parsed-resume-data-as-unconfirmed.md)) creates an unverified skill for an unknown name, visible only to that user. A job posting has no owner, so an unverified skill created from it would be visible to nobody, and the catalogue would fill with spellings that no one reviews.

## Decision

**`JobSkillExtractor` reads each distinct posting text once, on the cheap tier. Only catalogue skills are attached. Unknown names are kept on the posting for review, and no skill is created from a posting.**

1. **Agent:** structured output with `required_skills`, `nice_to_have_skills`, `seniority` and `years_required`, on the cheap tier ([ADR 0012](0012-route-ai-tasks-to-model-tiers-and-record-every-call.md)) as the `skill_extraction` task. Descriptions are cut at 12,000 characters. An invalid answer gets one more attempt with the validation errors.
2. **Key:** an extraction is current while `skills_text_hash` equals the posting's `text_hash` ([ADR 0021](0021-key-ai-work-on-the-posting-text.md)) and `skills_prompt_version` equals the agent's prompt version. A changed title or description, or a new prompt version, makes it pending again. Pay, expiry and seen times do not.
3. **One call per text:** the queued job is unique per `text_hash`. Before a call, a posting with the same text and prompt version is copied. After a call, the result is copied to every pending posting with the same text, so a job posted on two boards costs one call.
4. **Checks before save:**
    - **Grounding:** a name that the posting text does not contain, as the name, the catalogue name or an alias, is dropped and kept in `extracted_skills.ungrounded`. The check is the one resume parsing uses.
    - **Canonical names:** `Skill::findByName()` maps names and aliases to catalogue skills. Only verified skills are attached to `job_posting_skill`, with `is_required`. A skill in both lists is required.
    - **Unknown names** stay in `extracted_skills.unknown` and are logged as `jobs.skills.unknown`. `skills:unknown` lists them by the number of postings, so the catalogue grows by review, with aliases where a name is another spelling.
5. **Level:** `extracted_seniority` and `years_required` are separate columns. They do not overwrite the `seniority` that the source sends, because a source value is data and an extraction is a reading of the text. The jobs list (Day 16) chooses which one it shows.
6. **Failure:** an answer that fails validation twice records `skills_failed_at` with the same hash and version, and is not sent again until the text or the prompt changes. A provider outage fails the queued job, which retries 3 times.
7. **When it runs:** a successful ingestion run queues the pending postings of its source on `ai-batch`. `skills:extract` queues pending postings by hand, with a limit. Nothing is queued, and a queued job does nothing, while the global daily budget is spent ([ADR 0014](0014-cap-the-daily-ai-spend-of-all-users.md)). The postings stay pending for the next run.
8. **Cost:** `usage:report` shows the average cost per call. One call is one posting text, or two when the first answer is invalid.

## Consequences

**Benefits**

1. A repeat ingestion run, a second board or a pay change costs no call.
2. A hallucinated skill never reaches matching, and a misspelled skill reaches it under its catalogue name.
3. The catalogue grows from evidence: the unknown names that most postings ask for.

**Drawbacks**

1. Until the catalogue holds a skill, postings that ask for it do not match on it. `skills:unknown` must be reviewed, and promoting a skill does not re-attach old postings until their text changes or the prompt version moves.
2. Grounding drops a skill the text implies but does not name, such as "SQL" for "relational databases".
3. The cheap tier misses some skills. Its recall is not measured yet.

**Revisit when** a labelled set of postings measures the extractor's precision and recall, or the unknown list shows that the catalogue lags behind the postings.
