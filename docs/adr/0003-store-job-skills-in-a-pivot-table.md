# 3. Store job skills in a pivot table

- **Status:** Accepted
- **Date:** 2026-10-14
- **Amended:** 2026-10-19 (second measurement with other pivot queries, drift check)

## Context

Fit scoring, skill-gap plans and job search all need the skills a posting asks for. Search must answer "postings that require Laravel AND AWS" at about 1M postings. Skills are canonical, with aliases ("k8s" is Kubernetes), and each skill on a posting is either required or nice to have.

Options: a `job_posting_skill` pivot table, or a `jsonb` array of skill IDs on `job_postings` with a GIN index.

Both were measured on PostgreSQL 18, warm cache, with 1M postings, 6 skills each and 500 skills with a skewed distribution. "Common" skills appear in 106k and 43k postings; the "rare" one appears in 8k.

| Query                                             | Pivot                          | `jsonb` + GIN              |
| ------------------------------------------------- | ------------------------------ | -------------------------- |
| Newest 20 postings with two common skills         | 155–180 ms                     | 12–14 ms                   |
| Newest 20 postings with a rare and a common skill | 23–27 ms                       | 2 ms                       |
| Count postings with two common skills             | 120–135 ms                     | 18–22 ms                   |
| Demand: top 10 skills across all postings         | 750–825 ms                     | 4.5–4.8 s                  |
| Storage for skills                                | 612 MB (rows and both indexes) | 76 MB column + 15 MB index |

The pivot answers AND by reading every row for both skills (149k) and grouping them by posting. GIN intersects two compressed posting lists, so it does far less work.

The first measurement compared GIN's best query with only one pivot query, the `GROUP BY` above. A second measurement rebuilt the data set with the same shape and compared three pivot queries for the AND filter (min–max of 5 warm runs, in ms):

| Query                                    | Pivot `GROUP BY` | Pivot `EXISTS`, primary-key probes | Pivot `EXISTS`, planner's join | `jsonb` + GIN |
| ---------------------------------------- | ---------------- | ---------------------------------- | ------------------------------ | ------------- |
| Newest 20 with two common skills         | 190–212          | 38–61                              | 347–457                        | 17–25         |
| Newest 20 with a rare and a common skill | 177–231          | 726–872                            | 39–153                         | 1.6–4.5       |
| Count with two common skills             | 212–309          | 3,803–5,107                        | 62–158                         | 15–43         |

The probe query walks postings newest first and checks both skills with the pivot's primary key. With two common skills, about 1 posting in 230 matches, so it reads 5,209 postings to find 20 and stops. With a rare skill it reads 22k postings, and for a count it reads all of them. Then the planner's own plan, a merge join of the two skills' index entries, is faster. With two common skills the planner chooses a parallel scan that reads 17k–21k postings, so the probe form must be forced (`OFFSET 0` in each `EXISTS`). Absolute times differ between the two runs, because each run generated its own data on the same laptop. Compare the columns, not the runs.

## Decision

Use the `job_posting_skill` pivot table as the source of truth, with a primary key on `(job_posting_id, skill_id)`, an index on `(skill_id, job_posting_id)` and an `is_required` column.

For the AND filter, `jsonb` is faster in every case than the best pivot query: about 2.5 times with two common skills, about 4 times for a count, and 25–35 times with a rare skill. The `GROUP BY` form is never the best pivot query. The pivot still wins on everything else:

1. **Integrity.** Foreign keys keep every link pointing at a real skill. Merging a duplicate skill, or turning a skill into an alias, is two statements on each pivot: delete the links where the posting or user already has the target skill, then update the rest. Both pivots have an index that starts with `skill_id`, so neither statement scans the table. In `jsonb` it means rewriting every affected posting with no constraint to catch mistakes.
2. **Attributes per link.** `is_required` is a column, and later weights or years of experience are more columns. In `jsonb` each element must become an object, and every query must match its shape.
3. **Aggregations.** Skill demand, the skill-gap join against `user_skills`, and trends per skill are plain `GROUP BY` and joins, and run six times faster than unnesting arrays.
4. **The AND filter is fast enough.** With the right query for each case, the worst case is about 160 ms (a rare skill, or a count), and the newest postings with two common skills take under 65 ms. Real searches also filter on recency, location and salary, and the slow path is one query that a cache can absorb. The search query uses the forced probe form when every skill in the filter is common, and plain `EXISTS` otherwise. Skill frequencies come from the pivot and change slowly.

## Consequences

**Benefits**

1. One normalized model that Eloquent maps directly with `belongsToMany`, for postings and for users.
2. Renames, merges and alias changes stay small, safe writes.
3. Analytics over skills (demand, gaps, trends) are simple and fast.

**Drawbacks**

1. AND filters are about 2.5–35 times slower than with GIN, depending on how common the skills are, and no single pivot query is best in every case. The query form must follow skill frequency.
2. The pivot uses about six times more disk than `jsonb`: about 6 rows per posting, each with two B-tree entries.

**Revisit when** skill filters miss the search latency target in production. Before you change the model, add a read-only `required_skill_ids` column with a GIN index, written from the pivot in the same transaction. Searches then use GIN, and the pivot stays the source of truth.

Two copies of the same data can drift, for example after a bulk update that skips the model. Store the IDs sorted, so that equal sets have equal values, and catch drift in three places:

1. A feature test writes skills through every code path (create, update, merge, alias) and asserts that the column equals the pivot.
2. A scheduled job compares the column with the pivot and logs every posting that differs at error level:

    ```sql
    select p.id
    from job_postings p
    left join (
        select job_posting_id, jsonb_agg(skill_id order by skill_id) as ids
        from job_posting_skill
        where is_required
        group by job_posting_id
    ) s on s.job_posting_id = p.id
    where p.required_skill_ids is distinct from coalesce(s.ids, '[]'::jsonb)
    ```

3. A command rebuilds the column from the pivot, so a drift found by the job is fixed in one step.
