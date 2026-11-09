# AI Career Copilot

[![CI](https://github.com/nugi/ai-career-copilot/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/nugi/ai-career-copilot/actions/workflows/ci.yml)

Job hunting is scattered and mostly guesswork. Listings are spread across many job boards, it is hard to tell which roles actually fit your experience, and generic career advice does not say which skills to build next or how to present the ones you already have. AI Career Copilot brings jobs from Indonesia and remote-friendly companies worldwide into one place, scores how well each role fits your resume, explains the gaps, and helps you close them with a concrete skill plan and targeted resume and LinkedIn suggestions.

> **Status:** early development. The features below, and parts of the stack (AI SDK, Redis, AWS), are being added incrementally.

## Core features

- **Job search:** aggregated listings with keyword, semantic and hybrid search.
- **Fit scoring:** a match score for each job against your resume, with the reasons behind it.
- **Skill-gap plan:** the skills a target role asks for that your profile lacks, turned into a prioritised plan.
- **Resume and LinkedIn help:** rewrite suggestions tailored to a specific role, applied only after your approval.

## Tech stack

| Layer            | Technology                                                    |
| ---------------- | ------------------------------------------------------------- |
| Backend          | PHP 8.5, Laravel 13                                           |
| AI               | Laravel AI SDK (OpenAI, Anthropic, Gemini)                    |
| Database         | PostgreSQL 18 with pgvector 0.8.7                             |
| Cache and queues | Redis                                                         |
| Frontend         | Inertia.js 3, React 19, TypeScript, Tailwind CSS 4, Wayfinder |
| Quality          | Pest, Larastan (PHPStan), Pint, GitHub Actions                |
| Infrastructure   | AWS, provisioned with Terraform                               |

## Getting started

### Requirements

- PHP 8.5 with the `pdo_pgsql` extension, and Composer
- Node.js 22 and npm
- Docker, **or** your own PostgreSQL 18 server with pgvector 0.8.7

### Quick start with Docker

```bash
git clone https://github.com/nugi/ai-career-copilot.git
cd ai-career-copilot
composer setup:docker
composer dev
```

`composer setup:docker` starts PostgreSQL and Redis, installs dependencies, creates `.env`, creates and migrates the application and testing databases, and builds the frontend. Open <http://localhost:8000>.

### Using your own PostgreSQL

1. Copy `.env.example` to `.env`.
2. Set `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for your server. The user must be allowed to create databases and the `vector` extension.
3. Run `composer setup`, then `composer dev`.

## Composer scripts

| Command                 | What it does                                                                                                   |
| ----------------------- | -------------------------------------------------------------------------------------------------------------- |
| `composer setup:docker` | Start the Docker services, then run `composer setup`                                                           |
| `composer setup`        | Install dependencies, create `.env` and the app key, migrate the app and testing databases, build the frontend |
| `composer dev`          | Run the web server, the queue worker and Vite together                                                         |
| `composer test`         | Check formatting (Pint), run static analysis (Larastan) and run the test suite (Pest)                          |
| `composer lint`         | Fix PHP formatting with Pint                                                                                   |
| `composer ci:check`     | Run every check that CI runs, including frontend lint and type checks                                          |

## Testing

Tests run against a real PostgreSQL database, `career_copilot_testing`, so vector queries behave as they do in production. `phpunit.xml` pins the database name, and the connection settings come from `.env`. `composer setup` creates the testing database.

```bash
composer test
```

## Retrieval quality

`php artisan eval:retrieval` scores the matches ranking on 20 graded fixture profiles: recall@10, MRR and nDCG@10 for each mode, with bootstrap intervals ([ADR 0035](docs/adr/0035-measure-retrieval-on-a-graded-golden-set-of-profiles.md), [workflow](docs/retrieval-eval.md)). Saved runs are in [`docs/evals/retrieval`](docs/evals/retrieval).

Baseline, 19 of 20 cases (one case has no eligible label), 310 graded labels, 10,000 bootstrap resamples ([run](docs/evals/retrieval/2026-11-09-084349-7a92c4b.json)):

| Mode                        | Recall@10 | MRR   | nDCG@10 | vs hybrid (95% interval)  |
| --------------------------- | --------- | ----- | ------- | ------------------------- |
| Semantic                    | 0.652     | 0.804 | 0.831   | +0.032 (−0.030 to +0.104) |
| Keyword, count              | 0.458     | 0.616 | 0.653   | −0.146 (−0.231 to −0.073) |
| **Hybrid, count** (default) | 0.643     | 0.784 | 0.799   |                           |
| Hybrid, count + rerank      | 0.661     | 0.781 | 0.818   | +0.019 (−0.056 to +0.099) |
| Hybrid, IDF                 | 0.646     | 0.741 | 0.787   | −0.013 (−0.051 to +0.017) |
| Hybrid, IDF + proficiency   | 0.646     | 0.742 | 0.787   | −0.013 (−0.046 to +0.012) |

Keyword alone is clearly worse. No other mode beats hybrid with `count` by more than the noise, so the default stays.

## Contributing

`main` is protected. Create a branch, open a pull request, and merge after the `ci` check passes. Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/).

## Architecture decisions

Significant decisions are recorded as ADRs in [`docs/adr`](docs/adr/README.md). The schema, and the queries each index serves, are documented in [`docs/erd.md`](docs/erd.md).
