# 24 — Verified sources and documentation bookmarks

**Reviewed for this pack:** 2026-11-10. Links represent upstream docs/projects, not a guarantee of license or API stability at a later install date. Use the **installed package version's** documentation and Composer metadata as the final integration source.

## First-party Laravel components

- Laravel AI SDK source: https://github.com/laravel/ai
- Laravel AI SDK 13.x docs (installation, agent contracts, tools, testing, conversation): https://laravel.com/docs/13.x/ai-sdk
- Laravel MCP source: https://github.com/laravel/mcp
- Laravel MCP 13.x docs (web/local server, auth, tools, clients): https://laravel.com/docs/13.x/mcp
- Laravel Horizon source: https://github.com/laravel/horizon
- Laravel Horizon 13.x docs (Redis, config, process monitoring): https://laravel.com/docs/13.x/horizon
- Laravel 13 framework docs: https://laravel.com/docs/13.x
- Composer package security audits: https://getcomposer.org/doc/03-cli.md#audit

## Optional open-source integrations

- Langfuse repository: https://github.com/langfuse/langfuse
- Langfuse docs: https://langfuse.com/docs
- Qdrant repository: https://github.com/qdrant/qdrant
- Qdrant multitenancy: https://qdrant.tech/documentation/manage-data/multitenancy/
- Qdrant filtered search/payload index: https://qdrant.tech/documentation/manage-data/indexing/
- Neuron AI repository: https://github.com/neuron-core/neuron-ai
- Neuron AI documentation: https://neuron-ai.dev
- Ollama repository: https://github.com/ollama/ollama
- Prism PHP alternative: https://github.com/prism-php/prism

## Security references

- OWASP Top 10 for LLM Applications: https://genai.owasp.org/llm-top-10/
- OWASP Agentic AI security resources: https://genai.owasp.org/
- Laravel authentication / authorization: https://laravel.com/docs/13.x/authorization
- Laravel rate limiting: https://laravel.com/docs/13.x/rate-limiting
- Laravel database transactions: https://laravel.com/docs/13.x/database

## zoloERP inspected repository paths

- https://github.com/vigneshsinna/zolo-erp-pro
- `composer.json` and `composer.lock`
- `routes/api.php`
- `app/Services/Platform/CompanyContextResolver.php`
- `app/Services/Platform/CompanyContext.php`
- `app/Services/Accounting/FinancialReportService.php`
- `app/Services/Inventory/InventoryAvailabilityService.php`
- `app/Services/Platform/CompanyInsightService.php`
- `config/queue.php`, `config/cache.php`, `.env.example`
- `.github/workflows/company-foundation.yml`, `.github/workflows/deploy.yml`, `deploy/deploy.sh`

These repository paths are verifiable code touchpoints, not evidence the proposed AI integrations have already been installed.

## Source vs proposal labelling

- **Observed**: Explicitly found in the checked `main` repository snapshot.
- **Documented upstream**: Described by current official Laravel or open-source project documentation.
- **Proposed**: New AI file paths, services, API routes, schemas, quotas, permissions, events and rollout policy in this pack.
- **Needs runtime check**: Redis/worker/service availability, provider keys, model support, data-processing agreements, cost, current production state and package compatibility after Composer solve.

If source or package behaviour changes, update this source file and the relevant implementation spec; do not fabricate compatibility or state that unrun tests have passed.
