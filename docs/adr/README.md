# Architecture Decision Records

This directory records significant architecture decisions: the context, the decision and its consequences.

| #                                                                             | Decision                                                      | Status                      |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------- | --------------------------- |
| [0001](0001-use-postgresql-with-pgvector.md)                                  | Use PostgreSQL with pgvector for vector storage               | Accepted                    |
| [0002](0002-use-inertia-with-react-for-the-frontend.md)                       | Use Inertia with React for the frontend                       | Accepted                    |
| [0003](0003-store-job-skills-in-a-pivot-table.md)                             | Store job skills in a pivot table                             | Accepted                    |
| [0004](0004-compare-salaries-as-annual-gross-in-the-users-currency.md)        | Compare salaries as annual gross in the user's currency       | Accepted                    |
| [0005](0005-fail-over-on-provider-outages-only.md)                            | Fail over between AI providers on outages only                | Accepted                    |
| [0006](0006-redact-contact-data-before-resume-text-is-stored.md)              | Redact contact data before resume text is stored              | Accepted                    |
| [0007](0007-treat-parsed-resume-data-as-unconfirmed.md)                       | Treat parsed resume data as unconfirmed                       | Accepted                    |
| [0008](0008-redact-the-users-name-and-personal-fields.md)                     | Redact the user's name and personal fields                    | Accepted                    |
| [0009](0009-redact-personal-data-by-label-and-format-in-any-locale.md)        | Redact personal data by label and format in any locale        | Accepted                    |
| [0010](0010-keep-name-parts-in-company-names.md)                              | Keep name parts in company names                              | Accepted                    |
| [0011](0011-run-ai-work-on-separate-queues-and-stream-chat.md)                | Run AI work on separate queues and stream chat answers        | Accepted                    |
| [0012](0012-route-ai-tasks-to-model-tiers-and-record-every-call.md)           | Route AI tasks to model tiers and record every call           | Accepted                    |
| [0013](0013-run-chat-on-the-cheap-tier-until-evaluations-show-a-gain.md)      | Run chat on the cheap tier until evaluations show a gain      | Accepted                    |
| [0014](0014-cap-the-daily-ai-spend-of-all-users.md)                           | Cap the daily AI spend of all users                           | Accepted                    |
| [0015](0015-take-jobs-only-from-sources-whose-terms-allow-it.md)              | Take jobs only from sources whose terms allow it              | Accepted                    |
| [0016](0016-fetch-job-sources-with-bounded-retries-and-dated-expiry.md)       | Fetch job sources with bounded retries and dated expiry       | Accepted                    |
| [0017](0017-store-job-pay-as-posted.md)                                       | Store job pay as posted                                       | Accepted                    |
| [0018](0018-link-duplicate-jobs-across-sources.md)                            | Link duplicate jobs across sources                            | Accepted                    |
| [0019](0019-give-each-job-a-stable-identity.md)                               | Give each job a stable identity                               | Accepted, partly superseded |
| [0020](0020-ingest-job-sources-on-a-schedule.md)                              | Ingest job sources on a schedule                              | Accepted, partly superseded |
| [0021](0021-key-ai-work-on-the-posting-text.md)                               | Key AI work on the posting text                               | Accepted                    |
| [0022](0022-close-ingestion-runs-that-a-worker-abandons.md)                   | Close ingestion runs that a worker abandons                   | Accepted                    |
| [0023](0023-name-the-job-identity-openings.md)                                | Name the job identity "openings"                              | Accepted                    |
| [0024](0024-extract-job-skills-once-per-posting-text.md)                      | Extract job skills once per posting text                      | Accepted                    |
| [0025](0025-list-openings-through-a-representative-posting.md)                | List openings through a representative posting                | Accepted                    |
| [0026](0026-embed-jobs-by-title-and-skills-with-text-embedding-3-small.md)    | Embed jobs by title and skills with text-embedding-3-small    | Accepted                    |
| [0027](0027-embed-postings-in-batches-keyed-on-the-embedded-text.md)          | Embed postings in batches keyed on the embedded text          | Accepted                    |
| [0028](0028-chunk-resumes-by-section-and-embed-them-after-the-parse.md)       | Chunk resumes by section and embed them after the parse       | Accepted                    |
| [0029](0029-read-the-countries-a-posting-accepts-from-its-text.md)            | Read the countries a posting accepts from its text            | Accepted, partly superseded |
| [0030](0030-match-openings-by-exact-distance-to-the-profile-text.md)          | Match openings by exact distance to the profile text          | Accepted, partly superseded |
| [0031](0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md)   | Fuse keyword and semantic ranks with Reciprocal Rank Fusion   | Accepted, partly superseded |
| [0032](0032-rank-the-whole-filtered-set-and-label-text-based-restrictions.md) | Rank the whole filtered set and label text-based restrictions | Accepted                    |
| [0033](0033-rerank-the-hybrid-top-30-with-cohere-behind-a-flag.md)            | Rerank the hybrid top 30 with Cohere behind a flag            | Accepted                    |

## Adding a record

1. Copy the structure of an existing record into `NNNN-short-title.md`, using the next number.
2. Fill in **Status**, **Date**, **Context**, **Decision** and **Consequences**.
3. Add the record to the table above.

The decision in an accepted record does not change. Clarifications are fine; to change a decision, write a new record and mark the old one as **Superseded by NNNN**.
