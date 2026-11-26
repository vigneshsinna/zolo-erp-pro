# 05 — Installation, Version Gates and Environment Setup

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Do not reinstall what already exists
The AI refactor branch already contains Laravel AI integration. First inspect `composer.json`, `composer.lock`, `config/ai.php`, `config/erp_ai.php`, routes and migration status. Use the installed SDK interfaces and preserve existing history tables. Never run `composer require` or publish vendor migrations merely because this guide lists the package.

## Safe local preflight
```bash
git fetch origin
git switch refactor/erp-copilot-stabilization
git pull --ff-only
php -v
php artisan --version
composer validate --strict
composer check-platform-reqs
composer audit --locked
php artisan route:list --path=ai
php artisan migrate:status
```
Run commands against an authorised checkout. **Do not run destructive migrations or tests on a shared/prod DB.** Use independent `zolo_test_*` databases and synthetic company data. Record current SHA and installed provider SDK version before any change.

## Environment configuration
Set defaults through Laravel `config/erp_ai.php`, with `env()` only in config. Proposed flags (verify current names, don't duplicate): `AI_ENABLED`, `AI_READ_ONLY`, `AI_ACTIONS_ENABLED` (false by default), `AI_PROVIDER`, `AI_MODEL`, `AI_MAX_STEPS`, `AI_MAX_TOOLS_PER_RUN`, `AI_DAILY_USER_REQUESTS`, `AI_DAILY_COMPANY_TOKENS`, `AI_PROVIDER_RPM_LIMIT`, `AI_TRACE_ENABLED`, `AI_RAG_ENABLED`, `AI_MCP_ENABLED`.

Separate model API keys from UI, GitHub and database. The configured NVIDIA/open-compatible model must pass actual tool schema tests; a provider/model may answer normal text but fail function calling. Rate-limit each actual HTTP dispatch, including retries. Do not confuse one user message, one model step, one provider HTTP request and one ERP tool invocation.

## Optional package staging
| Package | When | Prerequisites |
|---|---|---|
| Horizon / Redis | Durable workflows or report queues | Private Redis ACL, capacity, workers, timeouts/retry_after, gated dashboard |
| Laravel MCP | Authenticated external AI clients | OAuth/Sanctum identity, per-tool tenancy, SSRF guard, MCP audit |
| Langfuse | Quality and cost monitoring | Redaction, region/privacy consent, separate infrastructure |
| Qdrant | Document knowledge retrieval | ACL ingestion, encrypted private service, revocation deletion, retention |
| Neuron AI | Proven need for complex orchestration | Independent PoC against custom Laravel workflow, no split authorization |
| Ollama | Approved private model evaluation | Dedicated hardware, license, benchmark, private network |

## Installation gate for any new package
1. Review maintainer, license, latest tagged release, Composer support, transitives and advisories.
2. Install on a new implementation branch, one package at a time; inspect lockfile change.
3. Publish only reviewed migration/config files; avoid duplicating SDK tables.
4. Run AI suites, full ERP suite and source/Blade route tests.
5. Test disable/rollback, memory, throughput and credential failure in staging.

## Deployment and secret hygiene
Use explicit PHP 8.5 CLI/FPM, separate AI worker process budget, secure environment storage and company opt-in. Never expose `.env`, raw prompt trace, customer dataset or token to the browser. Do not alter other applications on a shared VPS or assume the runtime is updated just because GitHub CI is green.
