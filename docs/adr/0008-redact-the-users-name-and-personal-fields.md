# 8. Redact the user's name and personal fields

- **Status:** Accepted
- **Date:** 2026-10-20
- **Amends:** [0006](0006-redact-contact-data-before-resume-text-is-stored.md) (names were not redacted)

## Context

[ADR 0006](0006-redact-contact-data-before-resume-text-is-stored.md) redacts contact data before resume text is stored. It left the person's name in, because detecting names reliably needs a model, and it listed the date of birth as an open item.

Since then, the resume parser sends the stored text to an AI provider on every upload ([ADR 0007](0007-treat-parsed-resume-data-as-unconfirmed.md)). A review of real Indonesian resumes found more personal data passing through:

- a "Data Pribadi" block: `Tempat, Tanggal Lahir`, `Agama`, `Status Perkawinan`, `Jenis Kelamin`, and sometimes height, weight and blood type. Religion and health data are specific (sensitive) personal data under UU PDP;
- addresses that start with `Kompleks`, which the address pattern did not know;
- old-format NPWP tax numbers (`12.345.678.9-012.345`);
- the user's own name, usually on the first line.

None of these fields helps parse a resume, score a fit or give career advice.

## Decision

**Redact these fields at the same boundary as contact data, and redact the user's own name using the name on their account.**

- A labelled personal field keeps its label and loses its value: `Agama: [PERSONAL]`. A label only counts when a colon follows it, so prose such as "gender equality" stays.
- `Kompleks` joins the address keywords. NPWP numbers, in the old format or after an `NPWP` label, become `[NPWP]`. NPWP runs before NIK, so a labelled 16-digit NPWP is not reported as a NIK.
- The account name (`users.name`) is known, so no model is needed: the full name, and then each part of three or more letters, is replaced with `[NAME]` as a whole word in any case. Names run last, after emails and links that may contain them.
- Resumes stored under the old rules are extracted again from their PDFs with `resume:redact`, which queues a new parse for each resume whose text changed, so `parsed_data` built from the old text is replaced too.

## Consequences

**Benefits**

1. Religion, marital status, gender, date of birth and health data no longer reach the provider or the database.
2. The user's name no longer reaches the provider in the resume text.
3. The same `resume:redact` command applies any later rule change to stored resumes.

**Drawbacks**

1. A name part that is also a word elsewhere in the resume, such as a company or school named after a person, is redacted too.
2. Only the account holder's name is redacted. Referees' and colleagues' names stay.
3. An unlabelled date of birth, such as "Bandung, 12 Januari 1995" on its own line, is not detected.
4. Re-parsing stored resumes costs one provider call each.

**Revisit when** the app needs to redact third-party names, which needs a named-entity model, or when users can upload resumes for someone else.
