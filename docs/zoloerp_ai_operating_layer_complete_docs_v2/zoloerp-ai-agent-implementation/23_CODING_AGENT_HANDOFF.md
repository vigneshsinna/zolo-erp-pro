# 23 — Coding-Agent Handoff: Stabilize, Then Implement Action Foundation

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Mission
Work against `vigneshsinna/zolo-erp-pro`. Read this entire documentation pack, inspect current HEAD and actual domain service contracts, and deliver changes in small independently testable PRs. Do not implement live posting in a Copilot refactor PR. Distinguish inspected sources, implemented changes, passing tests and deferred design.

## Stage 0: Current read-only stabilization
1. Start from `refactor/erp-copilot-stabilization`, verify whether it remains HEAD `6ded371`; check git status and PR #6.
2. Fix the actual `DesignTokensTest` regressions in `resources/views/backend/ai/workspace.blade.php` and sidebar; follow repo CSS convention.
3. Investigate `ProductAliasTest` 5-versus-4 issue comparing original branch; verify grid behavior and correct test expectation only with business evidence.
4. Verify company/branch/FY, source links, inventory and financial reporting golden tests; confirm NVIDIA-compatible staged provider dispatch counts.
5. Run full remote required CI including AI, company, commercial, operations and clean MySQL; do not merge until aggregate gate green. Record SHA.

## Stage 1: Separate Action Foundation branch
Create a new branch from the **approved, updated base** after Stage 0 (suggest `feature/ai-action-foundation`). Do not branch blindly from stale main. Add code-defined `ActionCapabilityRegistry`, versioned command interface, slot/schema resolution, workflow state store, immutable proposals and server approval endpoints behind `AI_ACTIONS_ENABLED=false`. Keep the existing read manifest strictly `read_only`. Do not implement raw SQL, generic route or arbitrary method tools.

## Stage 2: First end-to-end Purchase Order
Trace `CommercialApplicationService`, `PurchaseApplicationService`, `PurchaseCommand`, `CommercialDraftService`, `OrderLifecycleService`, `TaxDeterminationService` and purchasing policies. Produce signed mapping of input → domain command → expected ERP receipt. Implement supplier/product/UOM matching, missing-field clarification, price/tax preview, approval and a single idempotent real PO command. Verify order does not invent receipt, ledger or stock side effects. No auto-posting of bills yet.

## Stage 3: Expand only after real pilot
Purchase receipt and bill; sales quote/order/invoice; payments/accounting; inventory/production; documents and bulk. Each module requires its domain owner sign-off, permission matrix, golden fixtures and release flags. Prefer parallel read-only specialist agents where helpful; they share capabilities, not Copilot class internals.

## Explicit prohibitions
No production migrations without operator approval; no forcing Composer platform requirements; no unattended live financial postings; no re-enabling quarantined legacy `ActionProposalService`; no hardcoded GST; no direct `Model::forceCreate` for commercial documents; no changes to core ERP posting semantics solely for AI; no weakening test gates; no pretending a mocked model test validates the actual provider.

## Required output from every coding PR
Change summary and design-to-code mapping, exact SHA, source service contracts, files changed, security/threat review, test matrix and counts/skips, sample task and actual result, idempotency proof where relevant, DB migration and rollback plan, outstanding questions, sign-off owner and link to CI. If a domain workflow isn't known, stop that particular unsafe capability and document the needed decision rather than guessing.
