# 01 — Verified Code Baseline, Risks and Starting Point

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Source review snapshot
- Repository: `vigneshsinna/zolo-erp-pro`.
- Original base `main` SHA `1b429a5b51d6d242e97347781938973c3e55015c` (Laravel 13, PHP >=8.5.12 <8.6).
- Original AI feature branch: `feature/ai-agent-foundation` SHA `a1477a679dc0f9ea98ce8cc2d531a1c997e20864`.
- Current Copilot refactor branch reviewed: `refactor/erp-copilot-stabilization` SHA `14195d8b5b195f65ca1283c96794c613de88eded`.
- PR: https://github.com/vigneshsinna/zolo-erp-pro/pull/6 . No assumptions about future HEAD, merge, or deployed production status.

## Implemented / inspected at snapshot
| Layer | Real source | Status |
|---|---|---|
| Read-only agents | `app/Ai/Agents/ErpCopilot.php`, `DeepSeekAgent.php` | Present; no new action agents assumed |
| Model/provider | `app/Ai/Gateway/ApprovedModelGateway.php` | Present; limits, allowlist and model telemetry |
| Shared SDK adapter | `app/Ai/Tools/GenericSdkTool.php` | Present; replaced 14 read SDK wrappers |
| Read capability catalog | `app/Ai/Services/AiToolManifest.php`, `app/Ai/DTOs/AiToolRegistration.php` | Present; explicit read-only registration |
| Business tool boundary | `ErpToolExecutor`, `AiPrincipalFactory`, `AiPrincipalAuthorizer`, `AiFeatureGate` | Present; permission and tenant enforcement |
| Reporting facades | `ReadCustomerOutstanding`, `ReadSalesAnalytics`, `ReadInventoryStockSummary`, `ReadOpenOrders` and others | Present; per-report business semantics require ongoing regression |
| API + UI | `AiChatController`, `resources/views/backend/ai/workspace.blade.php` | Present; read-only proposal endpoints deny |
| Workflow write engine | Domain command adapters, approved action receipts, durable workflow state | **Not implemented/approved** |

## Reviewed quality evidence
**Latest:** GitHub Actions run [42870009218](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/42870009218) passed all **11 jobs**: AI, company, legacy MySQL, delivery, operations, compliance, commercial, concurrency, clean MySQL 8.0, clean MySQL 8.4 and aggregate ERP gate. Earlier run 42846498957 failed a product-alias expectation and design-token checks; those are **historical failures at prior commit `6ded371`**, not current blockers at `14195d8`. Passing CI does not prove live NVIDIA provider/tool-call behavior, company setup AI or financial action workflows.

## Existing ERP services identified for future action mapping
| Business area | Existing owner (confirmed path/signature) | Adaptation requirement |
|---|---|---|
| Common commercial create | `CommercialApplicationService::create(string $kind, array $data, string $key, ?int $actor, ?CompanyContext $context, ?int $replacesId = null)` | Inspect valid `kind`, required fields, stages, idempotency and expected receipt |
| Sales command | `SaleApplicationService::create(SaleCommand $command)` | Map typed approved request, no direct model calls |
| Purchase command | `PurchaseApplicationService::create(PurchaseCommand $command)` | Map typed approved request, existing validation |
| Drafts | `CommercialDraftService::save/load/assertPostable` | Reuse where semantic lifecycle matches; AI proposal remains distinct from ERP commercial draft |
| Sales/purchase posting | `SalePostingService::post`, `PurchasePostingService::post` | Never post from LLM; require approval and fresh domain validation |
| Order lifecycle | `OrderLifecycleService::createSale`, `receivePurchase`, `remaining`, `consume`, `amend` | Verify exact ordering, stock and idempotency behavior before composing workflows |
| GST | `app/Services/Tax/TaxDeterminationService::prepare` | Determine statutory tax in domain layer; never estimate with a hardcoded rate |
| Production | `ProductionService::plan/complete/reverse` | Manufacturing-specific permissions/capabilities |

**Not validated:** production database state; live 3rd-party model credentials/data handling; live NVIDIA staging traffic; all business transaction shapes; capabilities for other ERP modules. Service signatures are integration candidates, not permission to call them unreviewed.

## Current freeze rule
The existing Copilot must remain **read-only for ERP business state**. It can write its own scoped conversations, counters and audit telemetry. Existing proposal endpoints return `AI_ACTIONS_DISABLED`, the read manifest rejects non-`read_only` handlers, and no model-controlled ERP write capability should be registered on this branch.

## First tasks
1. Preserve the new passing aggregate CI status; investigate any future regressions without weakening test assertions.
2. Obtain exact ERP report parity for sales, receivables, open orders, GST and inventory.
3. Verify live provider contract with synthetic staging data under a strict budget.
4. Freeze read-tool schemas, provider aliases, permissions, fact cards, errors and source links as golden tests.
5. Only then branch for the separate action foundation; no giant all-at-once ERP rewrite.


---
