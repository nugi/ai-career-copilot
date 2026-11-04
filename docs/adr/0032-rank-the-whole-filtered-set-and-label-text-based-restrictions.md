# 32. Rank the whole filtered set and label text-based restrictions

- **Status:** Accepted
- **Date:** 2026-11-04
- **Supersedes in part:** [0029](0029-read-the-countries-a-posting-accepts-from-its-text.md) (points 2 and 5), [0030](0030-match-openings-by-exact-distance-to-the-profile-text.md) (point 6), [0031](0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md) (points 3, 6 and 10)

## Context

The review of the matches page and the country filter found four faults. Each one costs the user a job they could take or gives them a number that misleads.

1. **The leg cap discards ranks.** [ADR 0031](0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md) keeps the first 50 postings of each leg. A cap per leg pays off when each leg is a separate, costly retrieval. Here both legs are computed over the same materialized set of filtered rows, so the cap saves nothing and only drops rank information. It is why "Software Engineer(s) - Poland" (BeMyGuest), which asks for Laravel and PHP, gets no keyword credit at keyword rank 51 (ADR 0031, drawback 1).
2. **Technical names collide.** The `simple` parser turns "C#", "C++" and "C" into the token `c`, and ".NET" into `net` (ADR 0031, drawback 2). For a job app that is a false keyword hit on some of the most common skills.
3. **A grounded quote is not a restriction.** The first live run read "Join our close-knit LATAM remote team" as Latin America only ([ADR 0029](0029-read-the-countries-a-posting-accepts-from-its-text.md), drawback 1). The quote is in the posting, so the grounding check passed, but nothing in it restricts who can apply. The posting was hidden from every user in Indonesia.
4. **The match percent is a raw cosine similarity** ([ADR 0030](0030-match-openings-by-exact-distance-to-the-profile-text.md), point 6; ADR 0031, point 10). In hybrid mode the order comes from the fused ranks, so a row with 61% can sit above a row with 74%. The values sit in a narrow band (56% to 82% in the baseline), so a sound match reads as a weak one. A keyword-only row has no value at all. The coming fit score gives a calibrated number, and two numbers on one card would contradict each other.

## Decision

**Both legs rank every filtered posting. Technical names are rewritten before the text is parsed. An eligibility reading needs a restricting word, and a reading that excludes the user's country labels the posting instead of hiding it. The matches page shows no match percent until the fit score exists.**

1. **No leg cap:** the semantic rank and the keyword rank cover the whole filtered set. A posting at keyword rank 51 gets `1 / (60 + 51)` and not zero. The page still shows 30 rows.
2. **Technical names:** `normalize_search_text(text)`, an `IMMUTABLE` SQL function of `regexp_replace` calls, runs on the title, the skills and the description in the generated column, and on each term in the query. `c#` becomes `csharp`, `c++` becomes `cpp`, `.net` becomes `dotnet` (`asp.net` becomes `asp dotnet`), and `node.js` becomes `nodejs`. Each replacement is padded with spaces, so "C/C++" gives `c` and `cpp`, not one path token. A `.net` after a letter is part of a host name ("example.net") and stays. A function is used and not a custom text search dictionary, because RDS does not let you install dictionary files.
3. **Restricting word:** the reading is kept only when its quote appears in the posting (as before) and contains a restricting word: "only", "must", "require(s/d)", "authorized"/"authorised", "eligible", "eligibility", "limited to", "restricted to", "open to candidates" (or applicants, residents, people, those), "resident(s)", "residing", "citizen(s)", "citizenship" (`RestrictionWording`). "Based in" alone does not count, because it also describes where the company sits. The prompt already asks for these words; the check makes the rule hold when the model ignores it. A migration applies the same check to the readings saved before it.
4. **Four states:** open, unclear, restricted and not open. A source-silent posting whose reading excludes the country is **restricted**: it stays in the list with the label "May not accept your country" and the quote. Only a posting whose source excludes the country is **not open** and hidden. The reading comes from a model, and a hidden job is a loss the user never sees; a labelled one costs one click.
5. **No match percent:** the rows carry no `match_percent`. The order is the signal until the fit score arrives.

## Consequences

**Benefits**

1. A posting that names a profile skill always gets keyword credit, however many postings name more terms.
2. A C# profile no longer matches a C job, and a .NET posting matches ".NET", "ASP.NET" and "dotnet".
3. The LATAM posting is unclear again for a user in Indonesia, and a wrong restriction that passes the check is still visible.
4. The card no longer shows a number that disagrees with the order.

**Drawbacks**

1. **The word list is English.** A restriction written in another language is dropped, and the posting shows as unclear. This errs towards showing the job.
2. **A restricting word is necessary, not sufficient.** "Only senior engineers apply" passes the check. The labelled state limits the cost of such a reading to one click.
3. **The normalizer is a fixed list.** Other names that the parser splits ("Vue.js", "F#") need a new migration, and changing the function regenerates the column for every row.
4. **Ranking the whole set costs a sort of every filtered row in each leg.** Both legs were already computed for every row; only the cut is gone. Measure with the 50,000-row performance test.
5. **No number on the card** until the fit score exists. The baseline searches were not run again for this change; the retrieval golden set measures it.

**Alternatives considered**

1. **A larger cap:** it moves the cliff and keeps the cost of a cap with none of its savings. Rejected.
2. **A custom text search dictionary or synonym file:** RDS does not install dictionary files. Rejected.
3. **A new prompt version with a stricter rule:** a re-extraction of every posting, and the model can still ignore the rule. The check is cheaper and deterministic. Rejected for now.
4. **Rescale the cosine similarity into a percent:** the scale would be tuned to today's corpus, and the order would still come from ranks. Rejected.

**Revisit when** the retrieval golden set compares a plain term count with rarity weights (IDF) and with rarity times proficiency, or the fit score exists.
