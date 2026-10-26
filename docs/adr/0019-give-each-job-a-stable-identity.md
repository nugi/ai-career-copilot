# 19. Give each job a stable identity

- **Status:** Accepted; partly superseded by [0023](0023-name-the-job-identity-openings.md) (the table name, and point 1)
- **Date:** 2026-10-25

## Context

[ADR 0018](0018-link-duplicate-jobs-across-sources.md) stores one `job_postings` row per source and links the rows of one job to a canonical row through `duplicate_of_id`. The canonical row is whichever live row has the lowest ID, so it changes:

- when the canonical row expires, the next row of its group takes over (ADR 0018, point 4);
- when a rule change or a new posting joins or splits groups.

So far nothing outside `job_postings` points at a posting. That changes soon, and each new reference would point at a row that can stop being canonical:

| Table or column                                                   | Holds                               |
| ----------------------------------------------------------------- | ----------------------------------- |
| `job_posting_skill`                                               | the skills one posting's text asks  |
| job embeddings ([ADR 0001](0001-use-postgresql-with-pgvector.md)) | one vector for each job to match on |
| `job_matches`                                                     | a user's fit score for a job        |
| saved jobs and applications                                       | the job a user saved or applied to  |

If a user saves a job and its canonical row then expires, the saved job points at an expired row while the same job is still open on the other board. Moving each reference when the canonical row changes is possible, but every table that points at a job would need its own move step, and a missed one loses user data.

## Decision

**A `jobs` row is the identity of one job. Each `job_postings` row belongs to one job, and everything a user touches points at `jobs.id`. What a job shows is chosen from its postings at read time.**

1. **Identity:** `jobs` holds one row per logical job: an ID and timestamps. It holds no copy of posting data. `job_postings.job_id` is a required foreign key.
2. **Assignment:** the link statement that sets `duplicate_of_id` today sets `job_id` instead:
    - A posting that joins a group takes the group's job: the lowest `job_id` among its live members.
    - A posting in no group gets a new job.
    - A job keeps its ID while any of its postings is stored, live or expired. When a canonical posting expires, the job does not change.
3. **Merges and splits:** a rule change can join two jobs or split one.
    - A join keeps the lower job ID. References to the other job move to it in the same transaction, and the other job keeps `merged_into_id` so an old link still resolves.
    - A split keeps the job ID with the oldest posting. The postings that leave get a new job, and references stay on the job the user saw.
4. **What points where:**
    - Per-job work and user data point at `jobs.id`: embeddings, `job_matches`, saved jobs and applications.
    - Data read from one posting's own text stays on the posting: `job_posting_skill`, `duplicate_reason` and `duplicate_similarity` (why the posting joined its job).
5. **Presentation at read time:** a job's page combines its live postings:
    - The representative posting is the newest live posting from the most preferred source. Its title, description and apply link are shown, so each source's link stays its own ([ADR 0015](0015-take-jobs-only-from-sources-whose-terms-allow-it.md)).
    - Salary comes from the representative posting, or from the first live posting with a salary when it has none.
    - A job is open while any posting is live, and its expiry is the latest expiry of its live postings.
6. **Replaces the canonical row:** `duplicate_of_id` and `JobPosting::canonical()` go. Lists, matching and embeddings read jobs with at least one live posting.
7. **Timing:** build the table before skill extraction stores its first rows. No table points at a job until then, so the change is a migration and a rewrite of the link statement, with no data to move.

## Consequences

**Benefits**

1. A saved job, an application or a match survives the expiry of one source's posting and every change to the duplicate rules.
2. Per-job work (an embedding, a fit score) runs once per job and keeps its key when the postings change.
3. The best data of the whole group shows: a salary from one board and a later expiry from the other.

**Drawbacks**

1. Every read of a job joins `jobs` to `job_postings` and chooses a representative posting. List pages need an index on `(job_id)` and a query that picks one posting per job.
2. Merges must move references in the tables of point 4. Each new table that points at a job adds a line to the merge step, and a test.
3. A false merge now joins two jobs' user data, not only two rows. A split cannot tell which half a saved job meant, so it stays with the job the user saw.

**Alternatives considered**

- **Keep the oldest canonical row forever:** stable, but an expired posting then stands for an open job, and its expired apply link is the one shown.
- **Choose the best row as canonical:** shows the best data, but the identity changes whenever the data does, which is the problem this record solves.

**Revisit when** merges become frequent enough to need a review queue, or a source starts sending a stable cross-board job ID.
