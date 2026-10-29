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
