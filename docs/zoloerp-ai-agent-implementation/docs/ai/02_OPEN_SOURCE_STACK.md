# 02 — GitHub stack selection and integration decision record

## Selection principles

Prioritise Laravel-native, supported projects with verifiable licensing and a small operational footprint. Reuse **upstream maintained packages** instead of copying repository code into zoloERP. Pin reviewed versions via `composer.lock` and record license and advisory checks before production.

| Project | URL | Role | Decision / deployment | Notes |
|---|---|---|---|---|
| **Laravel AI SDK** | https://github.com/laravel/ai | Agent, model providers, tools, structured output, streaming, conversation/testing support | **Required PH0** in existing Laravel app | Official docs: https://laravel.com/docs/13.x/ai-sdk; Composer `laravel/ai`. Confirm exact package version/API at installation |
| **Laravel MCP** | https://github.com/laravel/mcp | MCP tool/server/client interoperability | **PH2**, install only when endpoint policy is ready; can evaluate PH0 | Composer `laravel/mcp`; avoid unauthenticated web servers |
| **Laravel Horizon** | https://github.com/laravel/horizon | Supervised Laravel Redis queues/dashboard | **PH1** if Redis ready | Requires Redis; `composer require laravel/horizon`, `php artisan horizon:install`; dedicate AI supervisors |
| **Langfuse** | https://github.com/langfuse/langfuse | LLM traces, feedback, experiments, evaluation | **PH2 optional** | External hosted/dedicated deployment preferred; PHP trace ingestion/OTel adapter is custom and version-verified |
| **Qdrant** | https://github.com/qdrant/qdrant | Filterable vector database | **PH3 optional** | Do not add for live financial/stock queries. All retrieval must enforce tenant/ACL filters, then recheck document access |
| **Neuron AI** | https://github.com/neuron-core/neuron-ai | PHP workflows, checkpoints, multi-agent and HITL | **PH4 evaluation only** | Alternative advanced orchestrator, not a second required runtime in MVP. Prototype isolated from production |
| **Ollama** | https://github.com/ollama/ollama | Local open-weight model serving | **PH4 optional** | Use a separate properly sized machine; model license/security to be checked individually |
| **Prism PHP** | https://github.com/prism-php/prism | Laravel/PHP LLM abstraction | **Fallback candidate, not installed** | Avoid two overlapping LLM SDKs in the MVP |

### Selected integration boundaries

- Laravel AI owns provider invocation, tool calling, test fakes and optional conversation transport, **not authorisation**.
- zoloERP-owned services own `AgentPrincipal`, tool catalog, permission guards, business actions, approvals, audit, quotas and tenant-safe read facades.
- Laravel MCP is **not** the in-process interface for our own tools. Both SDK tools and future MCP tools should call the **same** `ErpToolExecutor` guard and domain adapters to prevent bypasses.
- Horizon supervises bounded background jobs; Redis is transport, not a customer-data archive.
- Langfuse receives **redacted** telemetry only after consent/compliance review; no credentials, unredacted invoices or customer identifiers in default traces.
- Qdrant holds approved documentation embeddings/chunks, not ledger balances. Its filter is a second isolation mechanism, not an authentication system.

## Installation policy

Install packages one at a time in `feature/ai-agent-foundation`; run `composer why-not`, `composer check-platform-reqs`, `composer audit`, SDK smoke tests, and lock the resolved versions. No `--ignore-platform-reqs`; no blind `composer update` across the ERP.

## License and maintenance gates

Before installation, record: GitHub release/tag SHA, Packagist lock version, license and third-party transitive licenses, current security advisories, version compatibility, support activity, data-processing policy and deployment footprint. Repository licenses and upstream APIs can change; this pack links official sources instead of freezing unverified release numbers. For models, verify each **model's** license separately from Ollama's license.

## What to build, not buy

1. `AiPrincipal` and `CompanyContext` enforcement at tool execution time.
2. Strongly typed ERP tool registry, result evidence, audit and safe business adapters.
3. Proposal/review/approval/revalidation/replay-protection workflow.
4. Company settings, provider selection, budget and quotas.
5. Chat UI integrated into zoloERP navigation and existing login.
6. Regression and adversarial tests bound to real domain services.

## Why not run n8n/Dify/Flowise inside the ERP MVP?

They introduce separate runtimes, permission models and integration surfaces; n8n and Dify also warrant separate license/commercial review. They may be used outside the ERP later for non-authoritative notifications. Avoid relying on an archived/unmaintained agent UI as a production dependency. The selected stack is Laravel-native by design.

## Verified upstream Composer constraints (2026-11-10)

These are the **current GitHub repository `composer.json` constraints** observed at review time; resolve a tagged release in `composer.lock` before implementation. They are not a promise that every future version is compatible.

| Package | PHP requirement | Laravel / Illuminate support observed | License |
|---|---|---|---|
| `laravel/ai` | `^8.3` | `illuminate/support ^12.0\|^13.0` | MIT |
| `laravel/mcp` | `^8.2` | `illuminate/support ^12.41.1\|^13.0` | MIT |
| `laravel/horizon` | `^8.0` | `illuminate/support ^9.21\|^10.0\|^11.0\|^12.0\|^13.0` | MIT |
| `neuron-core/neuron-ai` | `^8.1` | Laravel integration is to be verified independently | MIT |
| `prism-php/prism` | `^8.2` | `laravel/framework ^12.61.1\|^13.12.0` | MIT |

All five declare compatible PHP version ranges with PHP 8.5, and the three recommended core packages declare Illuminate 13 support. This does **not** replace lockfile resolution, runtime tests or transitive dependency audits.
