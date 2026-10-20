# 6. Redact contact data before resume text is stored

- **Status:** Accepted, amended by [0008](0008-redact-the-users-name-and-personal-fields.md) (the user's name and personal fields are now redacted)
- **Date:** 2026-10-16

## Context

Users upload their resume as a PDF. Later features read the resume text: the parser, chunking and embeddings, fit scoring and chat. Most of them send the text to an AI provider (OpenAI, with Anthropic as the fallback).

A resume holds more personal data than the product needs: email, phone numbers, street address, profile links and sometimes the Indonesian national ID number (NIK). Indonesia's personal data protection law (UU PDP) asks for data minimisation and for a control at every place personal data goes. A rule like "remember to redact before each provider call" fails the first time a new feature forgets it.

### Personal data the product holds

| Data                                                                                                          | Where                                   |
| ------------------------------------------------------------------------------------------------------------- | --------------------------------------- |
| Name, email, password hash, two-factor secret, passkeys                                                       | `users`, `passkeys`                     |
| IP address and user agent of each session                                                                     | `sessions`                              |
| Headline, target roles, locations, work arrangements, minimum salary                                          | `profiles`                              |
| Skills and proficiency                                                                                        | `user_skills`                           |
| The original resume PDF, with all contact details                                                             | the default file disk, under `resumes/` |
| Resume text without contact details, but with the name, date of birth, employers, schools and referees' names | `resumes.content`                       |

## Decision

**Redact once, at the boundary, and store only the redacted text.** When a resume is uploaded, the app extracts the text, replaces contact data with placeholders, and saves only the result in `resumes.content`. Everything that reads resume text later gets redacted text by default. The original PDF stays on the private disk, so the text can be extracted again when the redaction rules improve.

`PiiRedactor` replaces:

- email addresses with `[EMAIL]`;
- web and profile links (LinkedIn, GitHub, GitLab, personal sites) with `[URL]`;
- 16-digit NIK numbers with `[NIK]`, before phone numbers, because a NIK can start with `62`;
- Indonesian mobile and landline numbers and international numbers with `[PHONE]`;
- street addresses (`Jl.`, `Jalan`, `Gg.`, `Perumahan`, RT/RW) with `[ADDRESS]`. The match ends at the postal code, a separator or the end of the line, and is at most 120 characters, so the rest of the resume is kept.

Names are not redacted. The person's name has no value in a prompt, but detecting names reliably needs a model, not a pattern.

A PDF without a text layer (a scanned image) or one that cannot be read is rejected with a clear message. Nothing is stored in that case.

### Controls at each hop

| Hop                 | What travels                                  | Control                                                                                                                                                                                                                                                                           |
| ------------------- | --------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Browser to app      | The PDF                                       | Only signed-in, verified, onboarded users can upload. PDF only, 5 MB at most. TLS in production.                                                                                                                                                                                  |
| App to file storage | The original PDF                              | Private disk, random file name in a folder for each user. Moving to S3 is a configuration change, and the bucket stays private.                                                                                                                                                   |
| App to database     | Redacted text, file metadata                  | Only redacted text is stored. Raw text exists only in memory during the upload request.                                                                                                                                                                                           |
| App to AI provider  | Redacted resume text, a subset of the profile | Resume text is redacted before it is stored, so no caller can send the raw text. The text still holds the name, date of birth, employers, schools and referees' names. The advisor sends target roles, locations, work arrangements and skills, never the headline or the salary. |
| App to logs         | Metadata only                                 | Prompts and answers are never logged ([ADR 0005](0005-fail-over-on-provider-outages-only.md)). Extraction errors do not pass the text or the file path on.                                                                                                                        |

## Consequences

**Benefits**

1. A new feature that reads `resumes.content` cannot leak contact details to a provider by mistake.
2. The database holds less personal data, so a database leak exposes less.
3. Better redaction rules apply to old resumes by extracting the stored PDFs again.

**Drawbacks**

1. The app cannot show the user their original text, only the redacted version. The PDF is still available.
2. Pattern matching misses unusual formats, such as an address with no street keyword, and it can remove text that only looks like contact data.
3. The original PDF still holds every detail. Deleting a user removes the database rows but not the stored file yet.

**Open items**

- Delete the stored PDFs when a resume or an account is deleted.
- Questions typed into the advisor are free text and are not redacted.
- The date of birth and the names of third parties, such as referees, still reach the provider. Remove them, or decide that they are acceptable.
- Review each AI provider's data-processing terms and data region before launch.

**Revisit when** a feature needs the contact details themselves (for example, to fill in an application form), or when name detection becomes necessary.
