# 10. Keep name parts in company names

- **Status:** Accepted
- **Date:** 2026-10-20
- **Amends:** [0008](0008-redact-the-users-name-and-personal-fields.md) (each name part was redacted everywhere)

## Context

[ADR 0008](0008-redact-the-users-name-and-personal-fields.md) redacts the user's full name, then each part of the name with three or more letters. It listed a drawback: a part that is also a word elsewhere in the resume is redacted too.

In practice this hits company names. Indonesian given names often share words with company names, such as Jaya, Putra, Sari and Mulia, so `PT Nugraha Digital` became `PT [NAME] Digital`. The parser then stores a broken company name, and the user has to fix every one when confirming the parse ([ADR 0007](0007-treat-parsed-resume-data-as-unconfirmed.md)).

## Decision

**A single name part stays when it is in a company name that starts with a known prefix. The full name is still redacted everywhere.**

- A company name is a prefix from `FieldLabels::COMPANY_PREFIXES` (`PT`, `CV`, `UD`, `PD`), in that exact case and with an optional dot, followed by one to four words on the same line, each starting with an uppercase letter.
- A name part that starts inside such a company name is kept. Outside one, it is replaced with `[NAME]` as before.
- The prefixes are data, like the field labels ([ADR 0009](0009-redact-personal-data-by-label-and-format-in-any-locale.md)), so another country's prefixes need only a new entry.

## Consequences

**Benefits**

1. Company names that share a word with the user's name reach the parser intact.
2. The full name never reaches the provider, even inside a company name.

**Drawbacks**

1. A company named after the user's family, such as `PT Nugraha Digital` for a user named Nugraha, sends the surname to the provider.
2. Only prefixes are recognised. Legal forms that come after the name, such as `Sdn Bhd`, `GmbH` or `Ltd`, are not, so name parts in those company names are still redacted.
3. A company name that spans a line break, or has more than four words before the name part, is not recognised.

**Revisit when** users outside Indonesia report broken company names, which would call for suffix forms, or when the app needs to redact third-party names (see ADR 0008).
