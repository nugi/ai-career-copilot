# Data model

The schema starts from the queries the product runs. Each table below lists its hottest queries and the index that serves each one. An index without a query is not added.

```mermaid
erDiagram
    users ||--o| profiles : has
    users ||--o{ resumes : uploads
    resumes ||--o{ resume_chunks : "split into"
    users ||--o{ user_skills : has
    skills ||--o{ user_skills : "held by"
    skills ||--o{ skill_aliases : "also called"
    job_postings ||--o{ job_posting_skill : asks
    skills ||--o{ job_posting_skill : "asked by"
    users ||--o{ job_matches : receives
    job_postings ||--o{ job_matches : "scored in"
    resumes |o--o{ job_matches : "scored against"
    users |o--o{ ai_usages : incurs

    users {
        bigint id PK
        string email UK
    }
    profiles {
        bigint id PK
        bigint user_id FK,UK
        string headline
        jsonb target_roles
        jsonb preferred_locations
        jsonb work_arrangements
        bigint min_salary
        char3 salary_currency
        string salary_period
    }
    resumes {
        bigint id PK
        bigint user_id FK
        string label
        string file_path
        text content
        boolean is_primary
        timestamp parsed_at
    }
    resume_chunks {
        bigint id PK
        bigint resume_id FK
        smallint position
        string section
        text content
        int token_count
    }
    skills {
        bigint id PK
        string name
        string normalized_name UK
    }
    skill_aliases {
        bigint id PK
        bigint skill_id FK
        string alias UK
    }
    user_skills {
        bigint user_id PK,FK
        bigint skill_id PK,FK
        string proficiency
    }
    job_postings {
        bigint id PK
        string source
        string external_id
        string dedup_key
        string title
        string company_name
        string work_arrangement
        string employment_type
        string seniority
        bigint salary_min
        bigint salary_max
        char3 salary_currency
        string salary_period
        timestamp posted_at
        timestamp expires_at
    }
    job_posting_skill {
        bigint job_posting_id PK,FK
        bigint skill_id PK,FK
        boolean is_required
    }
    job_matches {
        bigint id PK
        bigint user_id FK
        bigint job_posting_id FK
        bigint resume_id FK
        smallint score
        jsonb reasons
        timestamp scored_at
    }
    ai_usages {
        bigint id PK
        bigint user_id FK
        string feature
        string provider
        string model
        int input_tokens
        int output_tokens
        decimal cost_usd
        timestamp created_at
    }
```

## Conventions

- **Money** is stored as a whole amount in `bigint`, plus a `char(3)` currency (`IDR` or `USD`) and a period (`monthly` or `yearly`). It is never stored as a string. IDR has no minor unit in practice and salaries are quoted in whole dollars, so amounts are whole units, not cents. `bigint` holds a yearly IDR salary, which overflows `integer`.
- **Enums** are `string` columns cast to PHP backed enums in `app/Enums`, and each column has a CHECK constraint listing the allowed values. Adding a case therefore needs a migration that replaces the CHECK. `tests/Feature/Database/SchemaConstraintsTest.php` writes every case of every enum, so an enum and its CHECK cannot drift apart unnoticed.
- **CHECK constraints** guard the rules that every writer must follow, including imports, queue jobs and raw SQL: enum values, salary range and unit, score range, and non-negative counts, sizes and costs. Validation repeats them for user-facing messages.
- **No unsigned types:** PostgreSQL has none, and Laravel's `unsigned()` modifier is silently ignored. Columns that must not go negative have a `>= 0` CHECK instead.
- **Foreign keys** get an index only when a query needs one. PostgreSQL does not index them automatically, and each table lists the queries that justify its indexes.
- **Deletes:** deleting a user removes their profile, resumes, chunks, skills and matches. `ai_usages.user_id` becomes `null` so cost history survives. Deleting a resume keeps its matches and sets `resume_id` to `null`.
- **Not yet in the schema:** embedding columns and HNSW indexes on `resume_chunks` and `job_postings` (added with semantic search, see [ADR 0001](adr/0001-use-postgresql-with-pgvector.md)), a full-text index for keyword search, and a trigram index for skill autocomplete.

## Tables and their hot queries

### profiles

One row per user with job preferences.

| Query                                                        | Index              |
| ------------------------------------------------------------ | ------------------ |
| Load the signed-in user's profile (on each onboarding check) | `unique (user_id)` |

`target_roles`, `preferred_locations` and `work_arrangements` are `jsonb` arrays. They are read only with their owner's profile and never filtered across users, so they do not get a table or an index. A CHECK keeps `work_arrangements` a list of known values (`jsonb_typeof(...) = 'array'` as well as `<@`, because `<@` alone also accepts a single string).

### resumes

| Query                                           | Index                                                                                         |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------- |
| The user's primary resume, used for fit scoring | partial `unique (user_id) where is_primary` (also allows at most one primary resume per user) |
| The user's resumes, newest first                | `(user_id, created_at)`                                                                       |

### resume_chunks

| Query                                                                  | Index                            |
| ---------------------------------------------------------------------- | -------------------------------- |
| A resume's chunks in document order (rebuilding context, re-embedding) | `unique (resume_id, position)`   |
| Chunks nearest to a job posting's embedding                            | HNSW, added with semantic search |

### skills and skill_aliases

| Query                                                              | Index                      |
| ------------------------------------------------------------------ | -------------------------- |
| Resolve a typed name to a skill: `where normalized_name = ?`       | `unique (normalized_name)` |
| Resolve an alias ("k8s", "golang") to its skill: `where alias = ?` | `unique (alias)`           |

The catalogue holds a few thousand rows at most, so listing a skill's aliases needs no index on `skill_aliases.skill_id`. Names and aliases are normalized (trimmed, single-spaced, lowercase) by the models, so "PostgreSQL", " postgresql " and "POSTGRESQL" are the same skill.

### user_skills

| Query                                                                        | Index                             |
| ---------------------------------------------------------------------------- | --------------------------------- |
| The user's skills with proficiency (profile, fit scoring, skill gap)         | `primary key (user_id, skill_id)` |
| Move the links of a skill merged into another, or delete them with the skill | `(skill_id, user_id)`             |

The product never asks "which users have skill X", but merging and deleting skills does. Without this index both scan the whole table, which grows with every user.

### job_postings

| Query                                                                                          | Index                           |
| ---------------------------------------------------------------------------------------------- | ------------------------------- |
| The newest open postings                                                                       | `(posted_at)`                   |
| The newest postings for one work arrangement (remote, hybrid, on-site)                         | `(work_arrangement, posted_at)` |
| Postings that pay at least the user's minimum: `where salary_currency = ? and salary_max >= ?` | `(salary_currency, salary_max)` |
| Upsert on import without duplicates: `on conflict (source, dedup_key)`                         | `unique (source, dedup_key)`    |

`external_id` is the source's own ID, or `null` when the source has none. Postgres treats every `null` as distinct, so a unique index on `(source, external_id)` would let ID-less duplicates through. Instead, `dedup_key` is a stored generated column, `coalesce(external_id, 'url:' || md5(apply_url))`. It is computed by the database, so `insert`, `upsert` and raw SQL are all covered, and a posting without an ID is identified by its apply URL.

CHECK constraints: `salary_min <= salary_max`, both non-negative, currency in (`IDR`, `USD`), currency and period present whenever an amount is, and allowed values for `work_arrangement`, `employment_type`, `seniority` and `salary_period`.

### job_posting_skill

| Query                                                                | Index                                    |
| -------------------------------------------------------------------- | ---------------------------------------- |
| A posting's skills and which are required (detail page, fit scoring) | `primary key (job_posting_id, skill_id)` |
| Postings that ask for skill X and skill Y; demand per skill          | `(skill_id, job_posting_id)`             |

Why a pivot table and not a `jsonb` array: [ADR 0003](adr/0003-store-job-skills-in-a-pivot-table.md).

### job_matches

One current score per user and posting.

| Query                                                                   | Index                              |
| ----------------------------------------------------------------------- | ---------------------------------- |
| The user's best matches: `where user_id = ? order by score desc`        | `(user_id, score)`                 |
| Upsert a user's score for a posting                                     | `unique (user_id, job_posting_id)` |
| Rescore every match of a posting that changed                           | `(job_posting_id)`                 |
| Rescore every match scored against a resume that changed or was deleted | `(resume_id)`                      |

CHECK constraint: `score between 0 and 100`.

### ai_usages

Append-only log of every AI call, with no `updated_at`.

| Query                                                    | Index                   |
| -------------------------------------------------------- | ----------------------- |
| The user's spend this month, for budgets and rate limits | `(user_id, created_at)` |
| Cost per feature and model per day                       | `(created_at)`          |
