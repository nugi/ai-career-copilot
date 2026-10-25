# Architecture Decision Records

This directory records significant architecture decisions: the context, the decision and its consequences.

| #                                                                        | Decision                                                 | Status   |
| ------------------------------------------------------------------------ | -------------------------------------------------------- | -------- |
| [0001](0001-use-postgresql-with-pgvector.md)                             | Use PostgreSQL with pgvector for vector storage          | Accepted |
| [0002](0002-use-inertia-with-react-for-the-frontend.md)                  | Use Inertia with React for the frontend                  | Accepted |
| [0003](0003-store-job-skills-in-a-pivot-table.md)                        | Store job skills in a pivot table                        | Accepted |
| [0004](0004-compare-salaries-as-annual-gross-in-the-users-currency.md)   | Compare salaries as annual gross in the user's currency  | Accepted |
| [0005](0005-fail-over-on-provider-outages-only.md)                       | Fail over between AI providers on outages only           | Accepted |
| [0006](0006-redact-contact-data-before-resume-text-is-stored.md)         | Redact contact data before resume text is stored         | Accepted |
| [0007](0007-treat-parsed-resume-data-as-unconfirmed.md)                  | Treat parsed resume data as unconfirmed                  | Accepted |
| [0008](0008-redact-the-users-name-and-personal-fields.md)                | Redact the user's name and personal fields               | Accepted |
| [0009](0009-redact-personal-data-by-label-and-format-in-any-locale.md)   | Redact personal data by label and format in any locale   | Accepted |
| [0010](0010-keep-name-parts-in-company-names.md)                         | Keep name parts in company names                         | Accepted |
| [0011](0011-run-ai-work-on-separate-queues-and-stream-chat.md)           | Run AI work on separate queues and stream chat answers   | Accepted |
| [0012](0012-route-ai-tasks-to-model-tiers-and-record-every-call.md)      | Route AI tasks to model tiers and record every call      | Accepted |
| [0013](0013-run-chat-on-the-cheap-tier-until-evaluations-show-a-gain.md) | Run chat on the cheap tier until evaluations show a gain | Accepted |
| [0014](0014-cap-the-daily-ai-spend-of-all-users.md)                      | Cap the daily AI spend of all users                      | Accepted |
| [0015](0015-take-jobs-only-from-sources-whose-terms-allow-it.md)         | Take jobs only from sources whose terms allow it         | Accepted |
| [0016](0016-fetch-job-sources-with-bounded-retries-and-dated-expiry.md)  | Fetch job sources with bounded retries and dated expiry  | Accepted |
| [0017](0017-store-job-pay-as-posted.md)                                  | Store job pay as posted                                  | Accepted |
| [0018](0018-link-duplicate-jobs-across-sources.md)                       | Link duplicate jobs across sources                       | Accepted |
| [0019](0019-give-each-job-a-stable-identity.md)                          | Give each job a stable identity                          | Accepted |
| [0020](0020-ingest-job-sources-on-a-schedule.md)                         | Ingest job sources on a schedule                         | Accepted |

## Adding a record

1. Copy the structure of an existing record into `NNNN-short-title.md`, using the next number.
2. Fill in **Status**, **Date**, **Context**, **Decision** and **Consequences**.
3. Add the record to the table above.

The decision in an accepted record does not change. Clarifications are fine; to change a decision, write a new record and mark the old one as **Superseded by NNNN**.
