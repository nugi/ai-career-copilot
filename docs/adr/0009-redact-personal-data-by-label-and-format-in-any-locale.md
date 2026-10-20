# 9. Redact personal data by label and by format, in any locale

- **Status:** Accepted
- **Date:** 2026-10-20
- **Extends:** [0006](0006-redact-contact-data-before-resume-text-is-stored.md), [0008](0008-redact-the-users-name-and-personal-fields.md)

## Context

The app is for job seekers in any country. The redaction rules in [ADR 0006](0006-redact-contact-data-before-resume-text-is-stored.md) and [ADR 0008](0008-redact-the-users-name-and-personal-fields.md) were written for Indonesian and English resumes: NIK and NPWP numbers, `+62` phones, `Jl.` and `RT/RW` addresses, and "Data Pribadi" labels. A German Lebenslauf, a French CV or a Japanese 履歴書 would reach the provider with its date of birth, marital status, nationality, ID number and home address.

Many countries expect these fields on a resume. Several of them are special categories of personal data under GDPR and UU PDP: religion, ethnicity and health. Nationality, age and marital status are not needed to parse a resume or score a fit, and they invite bias.

A rule for each country's formats does not scale, and most of these values have no distinctive format. A date of birth looks like any other date, and an address looks like any other text. What the values have in common is the label in front of them.

## Decision

**Redact by label in many languages, and by format only where the format is distinctive.**

- **Labels are data.** `App\Ai\Redaction\FieldLabels` keeps one list of labels for each category: personal fields, identifiers, phones and addresses. Each list covers English, Indonesian and Malay, German, French, Spanish, Portuguese, Italian, Dutch, Japanese, Chinese and Korean. The patterns are built from the lists, so a new language needs only new labels.
- **A label needs a colon,** so prose such as "Age of Empires" or "Address the root cause" stays. Labels in Chinese, Japanese and Korean may be followed by a space instead, because forms in those languages often have no colon. A phone value needs a run of at least seven digits, and an identifier at least four, with only spaces, dots, dashes or parentheses between them. So "Phone: on request" and a skills line such as "Mobile: iOS 17, Android 14" stay. Labels that head ordinary resume lines, such as "Race" and "Health", are left out; "Ethnicity" and "Disability" cover those fields.
- **Distinctive formats are matched without a label:** US SSN, UK National Insurance, Singapore NRIC, Malaysian MyKad, Brazilian CPF, Indian Aadhaar in groups of four, Indonesian NIK and NPWP, and North American phone numbers. Formats that look like ordinary numbers (a bare 12-digit Aadhaar, most national phone numbers) are matched only after a label.
- **Nationality and citizenship are redacted** with the other personal fields. Advice and matching use the user's target locations and work arrangements, and a user who needs visa advice can say so in their question.
- **New placeholder:** `[ID]` for any identifier that is not a NIK or NPWP.

## Consequences

**Benefits**

1. A resume from any of these locales reaches the provider without its personal fields, ID numbers or labelled contact details.
2. Supporting a new locale is a change to a word list, reviewed like any other change.
3. Fit scoring never sees age, gender, marital status, religion, ethnicity or nationality, which removes inputs for biased scores.

**Drawbacks**

1. An unlabelled street address outside Indonesia is not detected. Street keywords differ in every language and collide with ordinary words.
2. A label that the lists do not contain, or a value in a table without a label, is not redacted.
3. A long list of short labels (`Tel`, `WA`, `Age`) needs the colon rule to avoid false matches. A sentence such as "Status: shipped" loses its value.
4. Salary comparison still supports only IDR and USD ([ADR 0004](0004-compare-salaries-as-annual-gross-in-the-users-currency.md)). That is a separate decision.

**Revisit when** users upload resumes in a language that the lists do not cover, or when unlabelled addresses need detection, which needs a model rather than patterns.
