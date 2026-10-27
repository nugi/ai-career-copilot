# 24. Extract job skills once per posting text

- **Status:** Accepted
- **Date:** 2026-10-26
- **Amended:** 2026-10-27 (resolution apart from extraction, unknown names keep their group, prompt v2)

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
5. **Level:** `extracted_seniority` and `years_required` are separate columns. They do not overwrite the `seniority` that the source sends, because a source value is data and an extraction is a reading of the text. The jobs list chooses which one it shows.
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

## Amendment (2026-10-27)

The first live run left two gaps. A skill added to the catalogue did not reach the postings that already named it (drawback 1), and the unknown list was led by areas of work ("backend development", "data pipelines", "dataset quality") instead of skills.

1. **Two steps, two triggers.** Extraction and resolution are now separate:

    | Step       | What it does                                | Runs when                                     | Model call |
    | ---------- | ------------------------------------------- | --------------------------------------------- | ---------- |
    | Extraction | The model reads the posting text            | The `text_hash` or the prompt version changes | Yes        |
    | Resolution | Stored names are mapped to catalogue skills | A skill is verified, or an alias is added     | No         |

    A catalogue change re-runs resolution only. A new prompt version would pay to read every posting again, although the readings have not changed.

2. **Unknown names keep their group.** Each entry of `extracted_skills.unknown` is `{name, normalized, required}`, so a name resolved later gets the right `is_required`. A migration filled in the stored entries from the `required` list, which already held every grounded required name. `skills:unknown` groups by the normalized name and counts required and nice-to-have postings apart.
3. **`skills:rematch`** attaches the unknown names that the catalogue now resolves to verified skills, with the stored group, and removes them from `unknown`. A skill already attached as required stays required. The extracted `required` and `nice_to_have` lists do not change, because they are the reading of the text. Saving a verified skill or an alias queues one `RematchSkills` job, unique until it runs, so a seeder run that adds 100 skills queues one job.
4. **Prompt v2** defines a skill as a tool, language, framework, platform or named technique that postings ask for, and leaves out areas of work. It replaces "methods" from v1, which let the areas of work in. Resume parsing will use the same definition. The new version makes every live posting pending again, about 330 texts at the measured $0.000115 each.
5. **Grounding** lives in `SkillGrounding`, because it checks job postings as well as resumes. `CheckResumeGrounding` keeps the resume bullet check.
6. **A stronger tier, if one is added,** runs on a set of postings chosen at extraction time by a fixed rule, for example fewer than 3 catalogue skills, and the tier is stored with the result. It never runs because a user opened or saved a job: a job's skills, and its fit scores, would then depend on who looked at it and when.

This replaces drawback 1: a promoted skill reaches old postings without a model call. A name that only the stronger reading of a new prompt would find still needs the text or the prompt version to change.

**Revisit when** a labelled set of postings measures the extractor's precision and recall, or the unknown list shows that the catalogue lags behind the postings.
