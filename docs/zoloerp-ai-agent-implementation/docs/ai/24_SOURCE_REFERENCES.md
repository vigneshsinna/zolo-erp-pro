# 24 — Source References, Provenance and Verification Rules

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

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


---

## V3 reviewed source: actual company onboarding capabilities (2026-11-11)
- Branch `refactor/erp-copilot-stabilization` SHA `14195d8b5b195f65ca1283c96794c613de88eded`; full CI success [42870009218](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/42870009218).
- `app/Services/Platform/CompanyDirectoryService.php`: `createGroup`, `createCompany`, `installStarterMasters`, group membership and COA/MAIN branch/FY/warehouse/industry setup.
- `app/Services/Platform/CompanySetupService.php`: `update`, `location`, `branch` for admin-scoped follow-up setup.
- `app/Services/Industry/IndustryCatalog.php`, `IndustryProfileService.php`: business industry/subtype, relevant units, process/capability presets.
- `app/Services/ERP/ProductMasterService.php`, `ReviewedProductImport.php`: product creation and reviewed CSV import; not AI tools.
- `app/Services/Tax/GstSetupService.php`, `TaxSetupService.php`, `CompanyProfileService.php`: GST, tax and company registration settings.
- `app/Http/Controllers/CompanySelectorController.php`, `CompanyFinancialYearController.php`, `UnitController.php`, `WarehouseController.php`, `CustomerController.php`, `SupplierController.php`: existing UI/controller workflows. Several legacy controllers need scoping/validation audits before AI adaptation.
- `app/Ai/Services/AiToolManifest.php`: still read-only, **no AI Setup Agent write tools**. See `37`–`39` for proposed extension.
