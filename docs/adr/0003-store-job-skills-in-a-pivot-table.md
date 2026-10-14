# 3. Store job skills in a pivot table

- **Status:** Accepted
- **Date:** 2026-10-14

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

## Decision

Use the `job_posting_skill` pivot table as the source of truth, with a primary key on `(job_posting_id, skill_id)`, an index on `(skill_id, job_posting_id)` and an `is_required` column.

For the AND filter, `jsonb` is about ten times faster. The pivot still wins on everything else:

1. **Integrity.** Foreign keys keep every link pointing at a real skill. Merging a duplicate skill, or turning a skill into an alias, is two statements on each pivot: delete the links where the posting or user already has the target skill, then update the rest. Both pivots have an index that starts with `skill_id`, so neither statement scans the table. In `jsonb` it means rewriting every affected posting with no constraint to catch mistakes.
2. **Attributes per link.** `is_required` is a column, and later weights or years of experience are more columns. In `jsonb` each element must become an object, and every query must match its shape.
3. **Aggregations.** Skill demand, the skill-gap join against `user_skills`, and trends per skill are plain `GROUP BY` and joins, and run six times faster than unnesting arrays.
4. **The AND filter is fast enough.** 150–180 ms is the worst case: two of the most common skills, no other filters. Real searches also filter on recency, location and salary, and the slow path is one query that a cache can absorb.

## Consequences

**Benefits**

1. One normalized model that Eloquent maps directly with `belongsToMany`, for postings and for users.
2. Renames, merges and alias changes stay small, safe writes.
3. Analytics over skills (demand, gaps, trends) are simple and fast.

**Drawbacks**

1. AND filters over common skills are about ten times slower than with GIN, and the cost grows with how common the skills are.
2. The pivot uses about six times more disk than `jsonb`: about 6 rows per posting, each with two B-tree entries.

**Revisit when** skill filters miss the search latency target in production. Before you change the model, add a read-only `required_skill_ids` column with a GIN index, written from the pivot in the same transaction. Searches then use GIN, and the pivot stays the source of truth.
