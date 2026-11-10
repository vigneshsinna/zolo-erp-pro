# 14 — Redis, Laravel Horizon, background jobs and limits

## Use cases

Read-only synchronous chat can run without Horizon at first; queued AI tasks become important for long reports, ingestion, summarisation, scheduled briefings and document extraction. Horizon is the selected open-source queue operator for Laravel Redis queues.

Official: https://laravel.com/docs/13.x/horizon. Horizon **requires Redis** and is not currently guaranteed on the existing ERP VPS.

## Queue topology

| Queue | Purpose | Profile |
|---|---|---|
| `ai-interactive` | Short bounded follow-up reads | low timeouts, limited concurrency |
| `ai-background` | Large report/briefing | few workers, controlled retry |
| `ai-ingestion` | PH3 document parsing/embedding | separate memory/CPU budget |
| Existing ERP queues | Payments, document dispatch, core operational jobs | Remain independent; **do not** starve them |

## Configuration outline

1. Provision private Redis with password/TLS as appropriate; choose app-specific keys/prefix/ACL for AI metrics and queues.
2. Set an isolated Redis queue connection and worker names. Validate Laravel 13 `config/queue.php` and `config/database.php` before edits.
3. Install Horizon; edit `config/horizon.php` for the actual `production`/`staging` environment and AI supervisors (`minProcesses=1`, `maxProcesses=2` initial example, not sizing guarantee).
4. Set job timeout **shorter** than `retry_after`, define max tries/backoff, retry jitter, unique work and idempotency. No endless LLM retries.
5. Register Horizon dashboard authorization via `Horizon::auth`/gate for **platform admin only**; deny customer access to cross-tenant queues.
6. Run Horizon under systemd/Supervisor using explicit `php8.5`, restart on deploy (`php artisan horizon:terminate` where appropriate), and monitor queue latency/failures.

## Async lifecycle

- Controller persists `ai_runs(status=queued)`, actor and selected scope reference (not a serialized authenticated Session object).
- Job reloads `User`, `AiPrincipal` and company/branch/FY permissions immediately before accessing data. Deny and mark failed if revoked.
- Lock run row/unique job key, budget-check, set status=running; perform read tools within limits; write final redacted facts to secured storage and status=completed.
- If worker crashes, status moves to failed after bounded retries; no infinite pending runs. A cancellation flag is checked between steps.
- Return `run_id`; UI polls a secure endpoint or subscribes to private channels with tenant/auth checks.

## Limits (proposed defaults, tune by load testing)

- Interactive LLM call: max 30–45 s wall time; workers constrained separately.
- At most 3 read tools / 5 reasoning steps per interactive request.
- Max 100 records returned internally and max 20 table rows shown to model/UI by default.
- Token budgets by actor/company/day, billing hard stop, model concurrency cap and per-provider rate limits.
- Agent jobs must not execute SQL migrations, optimize production caches or make DB-schema changes.

## Failure and operations

- Provider 429/timeout: bounded exponential backoff, no unlimited retries, do not expose sensitive prompt in exception logs.
- Redis unavailable: return read-only service-unavailable response or direct bounded fallback only if explicitly approved; do not silently route expensive jobs into synchronous core ERP workers.
- Monitor process memory, FPM occupancy, failed jobs, queue age, oldest run, per-tenant starvation and cost.
- Use dedicated scheduler entries, PHP 8.5 worker process and permissions, preserving other hosted apps and PHP versions.
