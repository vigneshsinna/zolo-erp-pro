# 24 — Source References, Provenance and Verification Rules

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Repository and current code (source inspected 2026-11-10)
- https://github.com/vigneshsinna/zolo-erp-pro
- AI refactor branch: https://github.com/vigneshsinna/zolo-erp-pro/tree/refactor/erp-copilot-stabilization
- PR and CI: https://github.com/vigneshsinna/zolo-erp-pro/pull/6 ; https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/42846498957
- Current AI: `app/Ai/Agents/ErpCopilot.php`, `app/Ai/Tools/GenericSdkTool.php`, `app/Ai/Services/AiToolManifest.php`, `app/Ai/Services/ErpToolExecutor.php`, `app/Ai/Services/AiPrincipalFactory.php`, `app/Ai/Gateway/ApprovedModelGateway.php`, `app/Http/Controllers/Api/V1/AiChatController.php`, `resources/views/backend/ai/workspace.blade.php`.
- Business domains: `app/Services/Commercial/{CommercialApplicationService,CommercialDraftService,PurchaseApplicationService,SaleApplicationService,PurchasePostingService,SalePostingService,OrderLifecycleService}.php`; `app/Services/Tax/TaxDeterminationService.php`; `app/Services/Manufacturing/ProductionService.php`; `app/Services/Accounting/FinancialReportService.php`; `app/Services/Inventory/InventoryAvailabilityService.php`; `app/Services/Platform/CompanyContextResolver.php`.
- Tests: `tests/Feature/Ai/`, `tests/Unit/Ai/`, `tests/Unit/DesignTokensTest.php`, `tests/Feature/ProductAliasTest.php`.

## Upstream projects and official docs
- Laravel AI SDK: https://github.com/laravel/ai ; https://laravel.com/docs/13.x/ai-sdk
- Laravel MCP: https://github.com/laravel/mcp ; https://laravel.com/docs/13.x/mcp
- Laravel Horizon: https://github.com/laravel/horizon ; https://laravel.com/docs/13.x/horizon
- Laravel security/authorization/transactions: https://laravel.com/docs/13.x/authorization ; https://laravel.com/docs/13.x/database
- Qdrant: https://github.com/qdrant/qdrant
- Langfuse: https://github.com/langfuse/langfuse
- Neuron AI: https://github.com/neuron-core/neuron-ai
- Ollama: https://github.com/ollama/ollama
- OWASP GenAI risks: https://genai.owasp.org/

## Evidence language
**Source-confirmed:** file, class or signature observed at pinned SHA, not necessarily behavior under real production data. **CI-confirmed:** named job run shows passing tests, not proof of live provider or finance correctness. **Proposed:** not yet in repository, requires implementation and business sign-off. **Unknown/open:** domain rule or environment condition not inspected; cannot be silently invented.

## When docs drift
Update SHA, actual versioned SDK interface, changed migrations/routes, business semantics and CI status before implementation. Treat documentation as replaceable design guidance and current code/business rules as authority. See `36_REPLACEMENT_MIGRATION_AND_RELEASE_GATES.md` for exact replacement workflow.
