# AI Career Copilot

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

## Architecture decisions

Significant decisions are recorded as ADRs in [`docs/adr`](docs/adr/README.md).
