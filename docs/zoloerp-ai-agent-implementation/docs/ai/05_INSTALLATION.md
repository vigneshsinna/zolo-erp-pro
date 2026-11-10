# 05 — Package installation and configuration runbook

> **Commands are instructions for a development branch only.** They have not been executed against zoloERP in this document. Do not install all optional packages at once. First confirm working tree/Composer lock and the installed package's real API surface.

## Preflight

```bash
git checkout main
git pull --ff-only origin main
git switch -c feature/ai-agent-foundation
php -v                          # >=8.5.12, <8.6
php artisan --version           # Laravel 13
composer validate --strict
composer audit --locked
composer check-platform-reqs --lock
php artisan test --help
```

Do **not** run migrations against production during development. Use a disposable dedicated `zolo_test_ai_*` database; preserve existing ERP MySQL, stock, general ledger and other sites. Record actual baseline versions and test outputs in the PR.

## Core integration A — Laravel AI SDK (PH0)

```bash
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
# Inspect the newly published config and migration files before execution.
php artisan make:agent ErpCopilot
php artisan make:tool CustomerOutstanding
composer validate --strict
composer audit --locked
```

The SDK docs specify vendor publishing and create `agent_conversations` / `agent_conversation_messages` for its own storage. **Do not recreate vendor tables**. Decide whether to use SDK conversation persistence before running migrations. Create our own separate `ai_thread_scopes` ownership mapping (see `06_DATABASE_SCHEMA.md`). In a sandbox only: `php artisan migrate --force`, then `php artisan migrate:status --pending=1`.

Official SDK documentation: https://laravel.com/docs/13.x/ai-sdk. Generated class names and interfaces take precedence over examples in this pack.

### Provider configuration

Add to `.env.example` **without actual credentials**:

```dotenv
AI_ENABLED=false
AI_READ_ONLY=true
AI_PROVIDER=openai
AI_MODEL=               # provision an approved model; name is provider-specific
AI_MAX_TOOLS_PER_RUN=3
AI_MAX_STEPS=5
AI_MAX_INPUT_CHARS=4000
AI_MAX_OUTPUT_ROWS=100
AI_DAILY_USER_REQUESTS=50
AI_DAILY_COMPANY_TOKENS=100000
AI_TRACE_ENABLED=false
OPENAI_API_KEY=
```

These `AI_*` names are **proposed custom zoloERP settings**, not guaranteed built-in SDK environment variables. Wire `config/erp_ai.php` through `env()` **in configuration only**. Resolve provider credentials in SDK's published `config/ai.php`; inspect its exact supported keys and the vendor's settings first. Keep API keys server-side. Consider encryption/secret management and provider data-retention rules.

## Core integration B — Laravel Horizon and Redis (PH1/PH2)

Only after Redis is provisioned and secure:

```bash
composer require laravel/horizon
php artisan horizon:install
# configure Redis transport and dedicated queues: ai-interactive, ai-background
# test locally against disposable Redis and database
php artisan horizon
```

Horizon requires Redis (`QUEUE_CONNECTION=redis`) and its own configuration; it is not a drop-in replacement for the existing `QUEUE_CONNECTION=sync`. Enable it first on staging, protect `/horizon` through authorisation, provide systemd/Supervisor, and ensure existing ERP jobs remain on their chosen queues until moved deliberately.

Official: https://laravel.com/docs/13.x/horizon.

## Core integration C — Laravel MCP (PH2)

```bash
composer require laravel/mcp
php artisan vendor:publish --tag=ai-routes
php artisan make:mcp-server ErpReadServer
# Add a limited tool and protect the web MCP route with auth:sanctum + tool permission middleware.
```

The MCP package will publish `routes/ai.php`; this file is **MCP-specific**, not the internal ERP chat API. Use `routes/erp-ai.php` or normal `routes/api.php` for the chat endpoints to avoid naming conflict. Do not expose a web MCP endpoint before per-client authentication, scope and rate-limit tests.

Official: https://laravel.com/docs/13.x/mcp.

## Optional integration D — Langfuse (PH2)

- Prefer a dedicated, supported Langfuse Cloud account or isolated host, not a full stack on the ERP VPS.
- Prototype PHP → OpenTelemetry/HTTP tracing adapter in a small test app; check Langfuse ingestion API, authentication, region and SDK compatibility. Avoid inventing a `composer require langfuse/...` package without verifying current support.
- Enable only after PII redaction policy and opt-in review; use correlation ID, not raw customer names, as trace identifiers.

## Optional integration E — Qdrant (PH3)

- Provision a private Qdrant service with authentication, encrypted transit, backup and monitoring.
- Implement HTTP API wrapper through Laravel `Http` (or a vetted PHP client). Create tenant and document ACL metadata indexes; enforce filters on every query.
- Use only for approved documentation, help and SOPs. Do not embed financial transaction tables as the primary query mechanism.

## Optional integration F — Neuron AI / Ollama (PH4)

Run an isolated proof of concept on a separate branch **after** the MVP, with `composer require neuron-core/neuron-ai` in a prototype project. Do not install both the Laravel AI tool runtime and Neuron orchestration in the same critical-path agent until routing/ownership is defined. Run Ollama on a separate machine with vetted model weights and usage rights.

## Post-install smoke gate

```bash
composer validate --strict
composer check-platform-reqs
composer audit
php artisan route:list | grep -E 'ai|mcp|horizon' || true
php artisan migrate:status --pending=1
php artisan test --filter=Ai
```

Any `grep`/command that finds nothing is not evidence of safety. Record package lock versions, generated route names, migration names, expected middleware, and tests. No `--ignore-platform-reqs`, no default public MCP exposure and no provider keys in `.env.example`.

## Rollback of a package-only change

Revert the feature branch commit, lockfile and any new config files; only reverse **AI-owned migrations** on a disposable environment. In production, prefer leaving unused AI-owned tables in place and disabling AI until a reviewed data-retention and rollback plan is approved. Do not rollback shared accounting schema to remove an AI package.
