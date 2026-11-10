# 01 — Verified repository baseline and integration points

## Source and inspection scope

Inspected connected GitHub repository `vigneshsinna/zolo-erp-pro`, `main`, SHA `1b429a5b51d6d242e97347781938973c3e55015c` (2026-11-09). The following are **source-derived** findings. Production database size, credentials, Redis availability and live VPS health are **not independently verified** in this pack.

## Verified facts

- `composer.json`: PHP `>=8.5.12 <8.6`, `laravel/framework ^13.2`, Sanctum `^4.0`, Laravel Modules `^13.0`, Spatie Permission `^6.0`, PHPUnit `^12.0`. No `laravel/ai`, `laravel/mcp`, `laravel/horizon` package declared at this snapshot.
- `routes/api.php`: `/api/v1` versioning, `auth:sanctum`, explicit `company.context` middleware on key ERP endpoints. Exposes sales, purchases, products, inventory valuation/transfer and finance reports. An AI endpoint should **not** reuse transaction POST endpoints as unsupervised tools.
- `app/Services/Platform/CompanyContextResolver.php`: `resolve(userId, companyId?, branchId?, financialYearId?, businessDate?)` checks membership and branch access; `forActor()` uses authenticated context and rejects unverified request headers. Async jobs require explicit actor re-resolution rather than assumed HTTP auth.
- `app/Services/Platform/CompanyContext.php`: `readonly` object with company, branch and financial-year IDs.
- `app/Services/Accounting/FinancialReportService.php`: authoritative ageing, trial balance, P&L, balance sheet and ledger methods. Some methods internally invoke `CompanyWriteGuard::context($context, auth()->id())`; **do not call those directly from unauthenticated jobs**. Add a supported actor-explicit read facade.
- `app/Services/Inventory/InventoryAvailabilityService.php`: `forProduct(productId, warehouseId?, variantId?, batchId?)` currently performs some raw queries without accepting `CompanyContext`; wrap/refactor for validated ownership before any AI exposure.
- `app/Services/Platform/CompanyInsightService.php`: `companyInsight(companyId)` computes cross-company-level dashboards; it is **not safe** as a direct unrestricted tool for branch-limited users.
- `.env.example`: `QUEUE_CONNECTION=sync`, `CACHE_DRIVER=file`, Redis host settings present. Do not assume Redis/Horizon is installed or configured in production.
- Current frontend uses Blade, Bootstrap 4, jQuery and legacy Vue tooling. A small progressive-enhancement AI chat page avoids forcing a frontend rewrite.
- `app/Jobs/DispatchDocument.php` exists. AI jobs need their own queue, retry, timeout and observability policy.
- A review of the repository tree found **no dedicated `app/Ai`, `app/Mcp`, `config/ai.php` or AI route implementation** at the above SHA.

## Existing domain ownership to preserve

| Business subject | Existing source owner | AI integration rule |
|---|---|---|
| Identity/tenancy | `CompanyContextResolver`, policies, membership tables | Never trust context from model or unauthorised HTTP fields |
| AR/AP ageing | `FinancialReportService::ageing()` | Reconcile with open-item/control ledger; branch and FY enforced |
| Trial balance/P&L/BS | `FinancialReportService` | Present exact values with date basis and reconciliation status |
| Inventory | `InventoryAvailabilityService`, stock movement services | Refactor for company/branch/warehouse validation; no direct updates |
| Sales/purchases | Existing commercial application services | Read in Phase 1; drafts only after approvals phase |
| Industry ops | Existing job work, manufacturing and capability services | Only when industry's capability enabled |

## Mandatory pre-implementation audit

1. Run `composer validate --strict` and `composer audit --locked` on a current clean branch; record results.
2. Inspect Spatie Permission usage and decide the exact **effective** role/capability checks for AI tools; do not equate API authentication with business authorisation.
3. Trace read permissions through `CompanyWriteGuard`, `FinancialReportService`, sales and stock reporting so AI sees only what ordinary users are allowed to see.
4. Validate Redis host and PHP extensions on **development/staging first**; identify dedicated AI worker process management on the shared VPS.
5. Check feature flags and accounting reconciliation in a disposable, realistic MySQL dataset.
6. Identify domain objects with personal/confidential data and define field-level redaction and provider-usage policies.
7. Confirm SDK migration naming and model identifiers after Composer resolves versions; **do not manually duplicate vendor conversation tables**.

## Change-management note

Do not edit historical accounting, stock movement, opening balance or migration files simply to support AI. New AI-owned migrations must be additive; production deployment remains a separate release. Do not merge code into `main` before CI, privacy, pilot and rollback gates pass.
