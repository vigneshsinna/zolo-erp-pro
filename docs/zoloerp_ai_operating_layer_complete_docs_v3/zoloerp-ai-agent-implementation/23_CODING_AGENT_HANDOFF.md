# 23 — Coding-Agent Handoff: Stabilize, Then Implement Action Foundation

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Mission
Work against `vigneshsinna/zolo-erp-pro`. Read this entire documentation pack, inspect current HEAD and actual domain service contracts, and deliver changes in small independently testable PRs. Do not implement live posting in a Copilot refactor PR. Distinguish inspected sources, implemented changes, passing tests and deferred design.

## Stage 0: Current read-only stabilization
1. Start from current reviewed `refactor/erp-copilot-stabilization` at `14195d8`, re-fetch HEAD and inspect PR #6.
2. Preserve green acceptance run 42870009218: all 11 jobs passed, including commercial, operations and aggregate gate. Earlier DesignTokens/ProductAlias failures at `6ded371` are historical; do not re-fix them blindly.
3. Verify financial/stock report parity, company/branch/FY, model history and authorised source links.
4. Run bounded NVIDIA staging-provider tool-call and quota contract tests with synthetic data; mocks alone are insufficient.
5. Run complete CI for subsequent PRs and capture the exact result SHA. Stage 1A Setup Agent is after Action Foundation; do not enable writes in current read-only Copilot.

## Stage 1: Separate Action Foundation branch
Create a new branch from the **approved, updated base** after Stage 0 (suggest `feature/ai-action-foundation`). Do not branch blindly from stale main. Add code-defined `ActionCapabilityRegistry`, versioned command interface, slot/schema resolution, workflow state store, immutable proposals and server approval endpoints behind `AI_ACTIONS_ENABLED=false`. Keep the existing read manifest strictly `read_only`. Do not implement raw SQL, generic route or arbitrary method tools.

## Stage 2: First commercial end-to-end Purchase Order (after validated Initial Setup Agent)
Trace `CommercialApplicationService`, `PurchaseApplicationService`, `PurchaseCommand`, `CommercialDraftService`, `OrderLifecycleService`, `TaxDeterminationService` and purchasing policies. Produce signed mapping of input → domain command → expected ERP receipt. Implement supplier/product/UOM matching, missing-field clarification, price/tax preview, approval and a single idempotent real PO command. Verify order does not invent receipt, ledger or stock side effects. No auto-posting of bills yet.

## Stage 3: Expand only after real pilot
Purchase receipt and bill; sales quote/order/invoice; payments/accounting; inventory/production; documents and bulk. Each module requires its domain owner sign-off, permission matrix, golden fixtures and release flags. Prefer parallel read-only specialist agents where helpful; they share capabilities, not Copilot class internals.

## Explicit prohibitions
No production migrations without operator approval; no forcing Composer platform requirements; no unattended live financial postings; no re-enabling quarantined legacy `ActionProposalService`; no hardcoded GST; no direct `Model::forceCreate` for commercial documents; no changes to core ERP posting semantics solely for AI; no weakening test gates; no pretending a mocked model test validates the actual provider.

## Required output from every coding PR
Change summary and design-to-code mapping, exact SHA, source service contracts, files changed, security/threat review, test matrix and counts/skips, sample task and actual result, idempotency proof where relevant, DB migration and rollback plan, outstanding questions, sign-off owner and link to CI. If a domain workflow isn't known, stop that particular unsafe capability and document the needed decision rather than guessing.


---

## Stage 1A: First administrative action slice — Initial Setup Agent
After the read-only Copilot source is verified and the separate Action Foundation exists, create bootstrap authorization for installation/group admins **before company scope**. Use `CompanyDirectoryService::createGroup/createCompany` via one reviewed, idempotent adapter; show exactly which starter resources are created automatically. Switch to normal company scope using the committed company/MAIN/FY IDs; then implement scoped branch/warehouse/UOM/product/party configuration and readiness in separate capabilities. Do **not** call legacy `UnitController::store`/`WarehouseController::store` with a model-built `Request`. Do not add a broad `ai.execute_setup` command. Review `37`–`39` and obtain platform/finance/security sign-off.

**Before any future action modifies an existing record**, enforce `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md`. Generic `Model::update()` of a submitted/posted document, company legal identity or previously used UOM is prohibited. Editing a proposal invalidates prior approval and returns to validation. The Action Engine is still outside the current read-only refactor branch.
