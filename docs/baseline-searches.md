# Baseline searches

Five searches on the jobs list with filters only: no keyword ranking and no semantic search. They are the baseline that later ranking must keep or beat. The filters are described in [ADR 0025](adr/0025-list-openings-through-a-representative-posting.md).

Run on 2026-10-27 against a local database with 335 live postings: remote boards plus 50 seeded Indonesian postings (source `manual`, generated company names). The user's floor is Rp20 juta per month, gross. That is Rp260 juta a year with THR, and the filter removes pay whose annual top is under 95% of it: Rp247 juta, or about USD 13,600 at the rate of the day.

Each search ran with 8 to 11 queries, whether it returned 1 row or 20. Pay is shown as posted, then converted to the floor's unit. `*` marks a required skill.

## 1. Remote jobs open to Indonesia that need Laravel and meet Rp20 juta a month

Remote, Indonesia, Laravel, meets my minimum. **1 row.**

| Title                             | Company      | Location  | Pay                                                     |
| --------------------------------- | ------------ | --------- | ------------------------------------------------------- |
| Senior Backend Engineer (Laravel) | Harris Group | Worldwide | USD 120,000–155,000 a year ≈ Rp167.7–216.6 juta a month |

The other three remote Laravel postings (search 2) are open only to Canada, the United States or Poland, so the country filter removes them. The floor filter removes nothing more. The one row is a seeded posting: no live board has a remote Laravel role open to Indonesia today.

## 2. Remote Laravel jobs anywhere

Remote, Laravel. **4 rows.**

| Title                                                   | Company          | Location      | Pay                                                     |
| ------------------------------------------------------- | ---------------- | ------------- | ------------------------------------------------------- |
| Senior Full Stack Developer                             | Knak             | Canada        | USD 130,000–160,000 a year ≈ Rp181.6–223.5 juta a month |
| LAMP Stack Senior Developer + Laravel - Remote USA Only | Nylon Technology | United States | USD 100,000–130,000 a year ≈ Rp139.7–181.6 juta a month |
| Software Engineer(s) - Poland                           | BeMyGuest        | Poland        | Not listed                                              |
| Senior Backend Engineer (Laravel)                       | Harris Group     | Worldwide     | USD 120,000–155,000 a year ≈ Rp167.7–216.6 juta a month |

Without the country filter the list shows jobs the user cannot take. Two titles say so ("Remote USA Only", "Poland"), and the third says it only in its location.

## 3. Remote Python jobs open to Indonesia that meet the floor

Remote, Indonesia, Python, meets my minimum. **8 rows.**

| Title                                             | Company      | Location     | Pay                                                    |
| ------------------------------------------------- | ------------ | ------------ | ------------------------------------------------------ |
| Software Engineer - Remote (Global), Full-Time    | Mixrank      | Worldwide    | Not listed                                             |
| Distributed Systems Engineer                      | Canonical    | Worldwide    | Not listed                                             |
| Platform Engineer - Compute Capacity              | Supabase     | Worldwide    | Not listed                                             |
| Network Engineer                                  | Nebius       | Asia-Pacific | Not listed                                             |
| Python and Kubernetes Software Engineer - Data, … | Canonical    | Worldwide    | Not listed                                             |
| Python Engineer                                   | Canonical    | Worldwide    | Not listed                                             |
| Software Engineer - App Stores                    | Canonical    | Worldwide    | Not listed                                             |
| Lead Machine Learning Engineer                    | D'Amore-Ryan | Worldwide    | USD 95,000–120,000 a year ≈ Rp132.7–167.7 juta a month |

Seven of eight rows have no pay. The floor filter keeps them, because it removes only pay that is certainly below the floor. Network Engineer is open to Asia-Pacific, which includes Indonesia.

## 4. On-site or hybrid jobs in Indonesia that meet the floor

On-site and hybrid, Indonesia, meets my minimum. **A full first page (20 rows)**, all seeded. The first five:

| Title                           | Company                         | Location        | Pay                  |
| ------------------------------- | ------------------------------- | --------------- | -------------------- |
| Lead Platform Engineer          | PT Irawan Nababan (Persero) Tbk | Yogyakarta      | Rp27–35 juta a month |
| Senior PHP Developer            | PJ Samosir Adriansyah           | Malang          | Rp29–41 juta a month |
| Lead Backend Engineer (Laravel) | PD Haryanto Suwarno             | Malang          | Rp32–42 juta a month |
| Lead Data Engineer              | PD Hastuti (Persero) Tbk        | Semarang        | Rp30–37 juta a month |
| Junior DevOps Engineer          | CV Adriansyah                   | Jakarta Selatan | Rp10–19 juta a month |

Without the floor, "Data Engineer, Rp11–16 juta a month" is on the first page. With it, that row goes. "Junior DevOps Engineer, Rp10–19 juta" stays: its top is exactly 95% of the floor, so it could still pay the floor ([ADR 0004](adr/0004-compare-salaries-as-annual-gross-in-the-users-currency.md), point 5).

## 5. React jobs anywhere that meet the floor

React, meets my minimum. **A full first page (20 rows)**, almost all remote roles in the United States. The first five:

| Title                               | Company                | Location      | Pay                                                     |
| ----------------------------------- | ---------------------- | ------------- | ------------------------------------------------------- |
| Senior Software Engineer, Consumer  | Toast                  | United States | USD 125,000–254,000 a year ≈ Rp174.6–354.9 juta a month |
| Full Stack Software Engineer        | Crisis Text Line, Inc. | United States | USD 99,704–116,990 a year ≈ Rp139.3–163.4 juta a month  |
| Principal Full Stack Engineer       | Steer                  | United States | Not listed                                              |
| Software Engineer - Mercury Command | Mercury                | Canada        | USD 122,400–176,000 a year ≈ Rp171.0–245.9 juta a month |
| Full Stack Developer                | One HR Hub             | United States | Not listed                                              |

Remote pay in USD is far above an Indonesian floor, so the floor filter removes almost nothing here. The country filter is the one that matters for this user.

## What the baseline shows

1. For a user outside the United States and Europe, the country filter removes more rows than any other filter.
2. The floor filter removes few rows, because most remote postings either pay well above the floor or list no pay.
3. A filter on one skill cannot rank. Search 5 returns a full page in date order, and the best fit can be on page 3.
4. For a user in Indonesia, the limit is the supply of jobs. Searches 1 and 4 return only seeded postings, so no live source has these jobs today ([ADR 0015](adr/0015-take-jobs-only-from-sources-whose-terms-allow-it.md), note of 2026-10-29).

Seeded postings (source `manual`) have generated text. They stay out of every evaluation set, such as the embedding benchmark and the retrieval golden set, so matching is never tuned against generated text.

## Country states

Searches 1 to 3 run again on 2026-11-03 for a user in Indonesia, after the country filter got three states ([ADR 0029](adr/0029-read-the-countries-a-posting-accepts-from-its-text.md)). The floor is the same, Rp20 juta per month. The live set is now 190 postings, not 335: the Jobicy postings of the first run expired between 2026-10-27 and 2026-11-01. Skills and country readings were extracted again under prompt v3: 191 requests, USD 0.041.

| Search                               | Without the country filter | Open | Unclear | Not open   |
| ------------------------------------ | -------------------------- | ---- | ------- | ---------- |
| 1. Remote, Laravel, meets my minimum | 4                          | 1    | 0       | 3          |
| 2. Remote, Laravel (no floor)        | 4                          | 1    | 0       | 3          |
| 3. Remote, Python, meets my minimum  | 20 or more (a full page)   | 2    | 0       | 18 or more |

1. Searches 1 and 2 return the same rows as before. The one open row is the seeded Harris Group posting, and the three removed rows are Canada, the United States and Poland.
2. Search 3 returned 8 rows before and 2 now. All six missing rows (Canonical, Supabase, Nebius) have an `expires_at` in the past. The country states did not remove them.
3. No row is unclear in these searches. The live set has four postings with no source country and no location text, all from Himalayas:
    - One is open from its reading: "Applicants from all geographies and backgrounds are welcome." (Mixrank, in search 3).
    - Two have no reading, so they are unclear. They do not ask for Laravel or Python.
    - One is read as Latin America only, from the sentence "Join our close-knit LATAM remote team". That sentence describes the team and does not restrict who can apply, so a user in Indonesia would wrongly lose this posting. It asks for neither Laravel nor Python, so it is not in these three searches. ADR 0029, drawback 1.
4. None of the four states a country restriction, so the "not open" path from a text reading is covered by tests only.

## Semantic ranking

The same five searches ranked by meaning ([ADR 0030](adr/0030-match-openings-by-exact-distance-to-the-profile-text.md)), on 2026-11-03 against 190 live postings. The query is the profile of the local test user, not a search phrase:

```
Backend Engineer, Full Stack Developer
Skills: Docker, Laravel, PHP, PostgreSQL, React, Redis
```

The floor is that profile's Rp28 juta per month, not the Rp20 juta of the filter baseline above. The arrangement and the country come from each search. The skill filter does not exist in matching, so the skill of each search is only counted in the results. Every list is the top 10. Match is the cosine similarity of the profile text and the posting text, times 100.

| Search                                  | Postings that pass the filters | Top 10 with the searched skill | Match range of the top 10 |
| --------------------------------------- | ------------------------------ | ------------------------------ | ------------------------- |
| 1. Remote, Indonesia (Laravel)          | 14                             | 1 of 10                        | 56–82%                    |
| 2. Remote, any country (Laravel)        | 158                            | 1 of 10                        | 73–82%                    |
| 3. Remote, Indonesia (Python)           | 14                             | 1 of 10                        | 56–82%                    |
| 4. On-site and hybrid, Indonesia        | not counted                    | not applicable                 | 59–82%                    |
| 5. Any arrangement, any country (React) | not counted                    | 5 of 10                        | 74–82%                    |

Across the five lists, the match runs from 56% to 82% with a median of 73%. Searches 1 and 3 have the same filters, so they give the same list.

### Where semantic ranking won

1. **Search 4 puts the fitting jobs first.** The top five on-site and hybrid roles in Indonesia are Laravel, PHP and full-stack jobs (82, 81, 74, 68 and 67%). The keyword baseline shows a date-ordered page that starts with a platform role and puts the Laravel role third. All ten rows here are seeded postings, so this shows that the ranking works, not that it works on real text.
2. **Search 5 orders the React jobs.** Five of the top 10 ask for React, and the real ones lead with full-stack titles (79%, 79%, 77%, 75%). The keyword baseline gave a full page in date order, where "the best fit can be on page 3" (observation 3 above).
3. **Search 1 finds the one Laravel job first.** The Harris Group posting is rank 1 at 82%, as in the keyword list.

### Where it lost

1. **A posting that names the skill can rank low.** In search 2, "LAMP Stack Senior Developer + Laravel" (Nylon) is rank 35 of 158 at 67%, "Software Engineer(s) - Poland" (BeMyGuest) is rank 42, and Knak is rank 12. "Principal Full Stack Engineer" is at 79%, above all three. The vector holds the title and the skill names, and a "Full Stack" title sits near the target role "Full Stack Developer". The keyword filter returned all three. This is the case for hybrid search.
2. **It cannot answer "Python jobs" from a Laravel profile.** In search 3, the two Python postings rank 4 and 11 of 14, behind the Laravel, Go, DevOps and platform roles. The query is the profile, not the words of a search. This is by design, and the architect question of ADR 0030 is the same effect.
3. **It always fills the list.** Only 14 remote postings pass the Indonesia filters, so the top 10 of searches 1 and 3 ends with DevOps and front-end roles at 56–58%. The keyword search returned 1 row. There is no minimum match yet.
4. **Indonesia is mostly seeded.** Of the ten rows of search 1, seven are seeded postings, so the Indonesian lists say little about real text. The golden set excludes them ([ADR 0026](adr/0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md)).

## Hybrid ranking

The same searches in three modes ([ADR 0031](adr/0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md)), on 2026-11-04 against 190 live postings. The profile and the floor are those of the semantic run above. The keyword terms are the profile's roles and skills: Backend Engineer, Full Stack Developer, Docker, Laravel, PHP, PostgreSQL, React and Redis. Keyword mode ranks by the number of distinct terms a posting contains, semantic mode by distance, and hybrid mode fuses both ranks.

Two marks count each top 10. They are mechanical, not a judgement of relevance:

- **Role:** the title names a backend, full-stack, PHP, Laravel or developer role.
- **Skill:** the posting's verified skills include one of the six profile skills.

| Search                           | Keyword (role / skill) | Semantic (role / skill) | Hybrid (role / skill) |
| -------------------------------- | ---------------------- | ----------------------- | --------------------- |
| 1. Remote, Indonesia (Laravel)   | 4 / 9                  | 4 / 8                   | 4 / 9                 |
| 2. Remote, any country (Laravel) | 10 / 10                | 10 / 10                 | 10 / 10               |
| 4. On-site and hybrid, Indonesia | 7 / 10                 | 7 / 9                   | 7 / 10                |
| 5. Any arrangement (React)       | 10 / 10                | 10 / 10                 | 10 / 10               |

Search 3 has the same filters as search 1, so it gives the same lists. Hybrid is equal to or better than the better single mode on both marks in all four searches. In searches 2 and 5 every list scores 10 of 10, so the marks cannot tell the modes apart there, and the comparison is by order alone.

### Where hybrid won

1. **A posting that names the skill rises.** In search 2, "LAMP Stack Senior Developer + Laravel" (Nylon) is rank 35 of 158 in semantic mode and rank 6 in keyword mode. In hybrid mode it is rank 13. Knak moves from rank 12 to rank 11.
2. **Search 4 keeps the semantic top and fills the end with skill matches.** The first five rows are the semantic ones (82, 81, 74, 68 and 67%). Places 8 to 10 go to postings that name profile skills, not to a platform role that names none. All ten rows are seeded postings, so this shows how the fusion works, not how it works on real text.
3. **Search 1 moves the backend roles up.** The Mixrank posting goes from rank 4 to rank 3 and the Ajaib backend posting from rank 9 to rank 6.

### Where it did not help

1. **A posting that names the skill can still miss the list.** "Software Engineer(s) - Poland" (BeMyGuest) asks for Laravel and PHP. It is rank 42 in semantic mode and rank 51 in keyword mode, one past the 50-posting leg, so hybrid mode gives it no keyword credit and keeps it outside the first 50 rows. Of the 50 postings ahead of it in the keyword leg, 27 name more of the eight terms and 23 name the same two and rank higher on `ts_rank`. All terms weigh the same.
2. **The marks are too coarse for searches 2 and 5.** The retrieval golden set, with graded labels, has to settle those two.
3. **A Laravel profile cannot ask for Python.** Search 3 is search 1 in every mode.
4. **Keyword mode returns a short list.** In search 1 only 9 of the 14 postings contain a term. Hybrid mode fills the rest from the semantic leg, and the list is full, with 56–58% matches at the end as before.
5. **Indonesia is mostly seeded postings.** The lists of searches 1 and 4 say little about real text.
