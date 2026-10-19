# 2. Use Inertia with React for the frontend

- **Status:** Accepted
- **Date:** 2026-10-13
- **Amended:** 2026-10-19 (hiring claims are assumptions)

## Context

The UI is interactive: a streaming copilot chat, job search with live filters, an application tracker, and resume suggestions the user reviews before applying. Within a year the team may hire, either locally in Indonesia or remotely.

Options: Livewire (Blade, server-rendered components), Inertia with React, or a separate React SPA talking to a JSON API.

Hiring differs by market. In Indonesia, Laravel is widespread, so Livewire developers are easy to find among Laravel developers, and React developers are also common. For a remote team, React is the dominant frontend skill, while Livewire is mostly known inside the Laravel community.

These hiring claims are assumptions, not measurements. No job data backed them when this record was written. They are checked once job ingestion runs (see **Revisit when**).

## Decision

Use Inertia v3 with React and TypeScript, with Wayfinder for typed routes. The hiring question does not change the pick: React hires well in both markets, while Livewire hires well only in a Laravel-heavy local market.

## Consequences

**Benefits**

1. The largest hiring pool, locally and remotely. Frontend developers can contribute without learning Laravel internals.
2. Rich interactions (streaming, optimistic updates) with typed contracts between Laravel and the UI through TypeScript and Wayfinder.
3. No separate API for the web app: Laravel keeps routing, auth, validation and sessions.

**Drawbacks**

1. Two languages and a build step. Simple CRUD screens take longer than in Livewire, and backend developers need React to ship UI changes.
2. Pages are coupled to Laravel controllers. A mobile app or third-party client will need a separate API.

**Revisit when** the ingested postings contradict the hiring assumptions. Count the frontend stacks (React, Vue, Livewire) in about 30 Laravel postings from Indonesia and 30 remote ones. If Livewire leads in both, this decision is worth a second look. Also revisit when a mobile app or public API is planned (add an API layer and keep Inertia for the web), or the team turns out to be mostly Laravel developers building simple screens (consider Livewire for admin-only areas).
