# 23. Name the job identity "openings" and assign it during import

- **Status:** Accepted
- **Date:** 2026-10-26

## Context

[ADR 0019](0019-give-each-job-a-stable-identity.md) gives each logical job its own row and names the table `jobs`. Two names already take that word:

- Laravel's queue migration `0001_01_01_000002_create_jobs_table.php` creates a `jobs` table for queued work.
- `App\Jobs\*` holds the queue classes, so an `App\Models\Job` would sit next to `IngestJobSource` and mean something else.

ADR 0019 also leaves the assignment details open: when a new posting gets its identity, which identity wins when a rule change joins or splits groups, and what happens when two imports link at the same time.

## Decision

**The job identity table is `openings` (model `Opening`, foreign key `opening_id`). The link step of every import assigns openings, inside the same transaction as the import's writes.**

1. **Name:** `openings` replaces `jobs` everywhere in ADR 0019. An opening is one position a company wants to fill, whichever boards post it.
2. **Table:** `openings` holds an ID, `merged_into_id` and timestamps, and no copy of posting data. `job_postings.opening_id` points at it, and `duplicate_of_id` is dropped. `duplicate_reason` and `duplicate_similarity` stay on the posting.
3. **One transaction:** `ImportJobPostings` writes its chunks and runs the link step in one transaction, behind an advisory lock, so two sources never link at the same time. The upsert inserts a posting before the link step chooses its opening, so `opening_id` is nullable in the schema. No committed posting has a null `opening_id`; a test checks this.
4. **Assignment:** the grouping rules of ADR 0018 do not change. They now choose openings:
    - Each opening that has a live posting is claimed by the group that holds its oldest live posting (the lowest posting ID).
    - A group keeps the lowest opening it claims. A group that claims none gets a new opening.
    - A posting stored after it expired is in no group, and gets an opening of its own.
    - Expired postings keep their opening, so an opening keeps its ID while any of its postings is stored.
5. **Merge:** the other openings a group claims are merged into the one it keeps. Their expired postings move with them, `merged_into_id` points at the kept opening, and openings merged into them earlier point at it too, so an old link resolves in one step. A merged opening has no postings and is never open.
6. **Split:** the group with the oldest posting keeps the opening. The postings that leave get the lowest other opening they claim, or a new one.
7. **Open openings:** `Opening::open()` reads openings with at least one live posting. It replaces `JobPosting::canonical()`.
8. **Not in this change:**
    - `job_matches` still points at a posting. It moves to `opening_id`, with its line in the merge step, when matching writes its first rows (Day 21).
    - Choosing the representative posting of an opening (ADR 0019, point 5) lands with the jobs list (Day 16).
9. **Existing data:** the migration gives each canonical posting an opening with the same ID, and its duplicates join it.

This supersedes the table name in ADR 0019 and its point 1, where `job_id` is a required column.

## Consequences

**Benefits**

1. No name clash with the queue table or the queue classes.
2. Expiry, merges and splits keep a stable opening for every user reference that comes later.
3. Repeated imports change no opening: `links_changed` stays 0 on a run with the same data.

**Drawbacks**

1. The database does not enforce a non-null `opening_id`. A write path that inserts postings outside `ImportJobPostings` must run the link step in the same transaction.
2. The advisory lock makes imports of different sources wait for each other during the write phase. At a few hundred rows per run the wait is short.
3. Each merge leaves an opening row with no postings, kept so old links still resolve.

**Revisit when** imports must write in parallel, or a second write path stores postings.
