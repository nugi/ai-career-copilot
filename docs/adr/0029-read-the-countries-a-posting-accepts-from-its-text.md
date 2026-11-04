# 29. Read the countries a posting accepts from its text

- **Status:** Accepted; partly superseded by [0032](0032-rank-the-whole-filtered-set-and-label-text-based-restrictions.md) (points 2 and 5: the quote also needs a restricting word, and a reading that excludes the country labels the posting as restricted instead of hiding it)
- **Date:** 2026-11-03

## Context

[ADR 0025](0025-list-openings-through-a-representative-posting.md), drawback 5: the country filter trusts the posting's list of countries, so a remote posting with no country and no region counts as worldwide, also when its text says "US only". The baseline in [`docs/baseline-searches.md`](../baseline-searches.md) showed that the country filter removes more rows than any other filter for a user outside the United States and Europe. In a sparse market, a job that is wrongly hidden costs the user as much as a job that is wrongly shown. So the filter needs a third state, "unclear", and not only yes or no.

A missing location is parsed to `region = worldwide`, the same value as an explicit "Anywhere", and a worldwide region includes every country. The region therefore cannot tell "the source said worldwide" from "the source said nothing".

The state depends on where the user lives, and the profile had no country.

Live data on 2026-11-02: 190 live postings, 161 of them remote. Only 14 remote postings have no country from the source: 10 seeded ones, and 4 from Himalayas with no location text. None of the 4 real ones states a restriction. The rule below is therefore proven on fixtures, not yet on a real restricted posting.

## Decision

**The skill extractor also reads where the worker may live, and the country filter has three states: open, not open and unclear. A reading is used only when the source sent no countries.**

1. **Same call:** prompt `job-skill-extractor/v3.md` (`v2.md` stays unchanged) returns `eligible_countries` (ISO 3166-1 alpha-2), `eligible_regions` (`JobRegion` values) and `evidence`, one quote from the posting. `PROMPT_VERSION` is `v3`, so every live posting is extracted again once. There is no second model call.
2. **Grounding:** the quote must appear word for word in the title and description, after the same normalization as the resume bullet check (`QuoteGrounding`). If it does not, the countries, regions and quote are dropped, like an ungrounded skill ([ADR 0024](0024-extract-job-skills-once-per-posting-text.md)).
3. **Storage:** `eligible_countries`, `eligible_regions` and `eligibility_evidence` on `job_postings`. They are not written into `country_codes` or `region`, because every import overwrites those two columns with the source's values.
4. **Source silent:** a posting is source silent when it is remote, has no `country_code`, an empty `country_codes`, and a blank `location`. The location, not the region, decides this, for the reason in Context.
5. **States** for a user's country:
    - **Open:** the source names the country, or its region includes it, or (source silent) the reading includes it.
    - **Not open:** the source names other countries, or its region excludes the country, or (source silent) the reading excludes it.
    - **Unclear:** source silent and no reading. The row stays in the list with the label "Country not stated". It is not treated as worldwide.
    - The source wins. A posting that names countries is never source silent, so its reading is not used. Every posting that is not source silent keeps the earlier rule exactly.
6. **Profile country:** `profiles.country_code`, set in onboarding. The jobs page uses it when the query string has no `country`, and an empty `country` means any country. A user without a country sees no states, as before.
7. **Preference or restriction:** the prompt counts only words that restrict ("only", "must be based in", "must be authorized to work in", "limited to"). "EMEA preferred" is a preference and gives empty lists. A wrong restriction hides a job from a user who could have taken it, and the user never learns about it. A missed restriction shows a job that the user can skip after one click. When the reading is unsure, the job stays visible as unclear.

## Consequences

**Benefits**

1. "Remote USA Only" is no longer open to a user in Indonesia, and a remote posting that says nothing is shown with a label instead of being passed as worldwide.
2. The reading costs a few output tokens in a call that is already made. Re-extracting 190 texts costs about $0.04.
3. The same state rule serves the jobs list and semantic matching.

**Drawbacks**

1. A grounded quote proves that the words are in the posting, not that they support the lists. The model can quote a true sentence and draw the wrong conclusion. The first run on live postings shows it: of the four postings with no source country, one was read as Latin America only from the sentence "Join our close-knit LATAM remote team", which describes the team and does not restrict who can apply. That posting is now "not open" for a user in Indonesia. The prompt rule against preferences did not stop it. The error rate is not measured yet: it needs hand labels on live remote postings (precision and recall for "not open", and the share left unclear). A stricter rule would be a new prompt version.
2. No real restricted posting exists in the live set today, so the "not open" path is covered by fixtures only. Run the check again when ingestion finds one.
3. A genuinely worldwide posting whose text never says so shows as unclear.
4. A user without a saved country sees no states until they set one.
5. All live postings are extracted again once, when `PROMPT_VERSION` changes, and the scheduled ingest would start that without a manual run.

**Alternatives considered**

1. **A second model call for eligibility:** a cleaner prompt, but a second request for each text for a few output tokens of gain. Rejected.
2. **A list of phrases as the mechanism** (`US only`, `must be based in`, `remote within`): phrases vary too much across languages. Rejected. As a check on the extractor it needs real readings, so it runs once on live postings after the re-extraction, as a query, and not as a test with a fake extractor.
3. **Write the reading into `country_codes` and `region`:** no new columns, but the next import overwrites it. Rejected.
4. **Keep treating a silent remote posting as worldwide:** no change, and the drawback of ADR 0025 stays. Rejected.

**Revisit when** hand labels show that "not open" has a precision below the level where hidden jobs cost more than shown ones, or a real restricted posting shows that the quote check is too strict or too loose.
