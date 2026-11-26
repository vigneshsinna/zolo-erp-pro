# 36 — Replacing the Old Documents and Safely Migrating the Product

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## What this pack replaces
The legacy folder held README plus documents `01`–`24`, first drafted against `main` before AI implementation. Those docs are unchanged in `feature/ai-agent-foundation` and `refactor/erp-copilot-stabilization`; some startup instructions (`git switch -c feature/ai-agent-foundation`, `make:tool CustomerOutstanding`) are now stale and can recreate removed wrappers. This pack preserves **all 25 old filenames**, rewrites them to reflect current reality and adds `25`–`36` for complete AI-operated ERP design.

## Safe repository replacement sequence
Do this only in an authorised local Git checkout, not on production:
```bash
git fetch origin
git switch refactor/erp-copilot-stabilization
git pull --ff-only origin refactor/erp-copilot-stabilization
# Confirm current branch and clean tree. If branch was merged, choose approved updated base.
git switch -c docs/ai-operating-layer-v2
# Replace docs/zoloerp-ai-agent-implementation/docs/ai with the contents of this pack.
# Inspect git diff --stat and all document changes; do not edit app/ or migrations in docs-only PR.
git status --short
git diff --check
git diff -- docs/zoloerp-ai-agent-implementation/docs/ai
# Commit and open a documentation-only PR for review after owner approval.
```
A copy operation may overwrite the **25 existing documentation files**; do not delete unrelated docs or accidentally replace the coding-agent plan stored outside `docs/ai`. New `25`–`36` files are additions. The ZIP root contains the exact repository-relative directory structure.

## Docs are not code
Installing documentation makes no changes to app behavior. Preserve current strict read-only `AiToolManifest` and proposal quarantine. `20_DELIVERY_BACKLOG.md` explicitly separates code phases and their gates; the action engine described in later documents is not implemented by copying these Markdown files.

## Current CI blocker register (reviewed SHA `6ded371`)
1. `ProductAliasTest` checks four specific grid lookup occurrences but finds five. Determine whether an extra actual grid path is correct or introduces duplicate handling; compare source branch/test and verify UI.
2. `DesignTokensTest::test_blade_styles_use_brand_tokens_not_old_accents` fails in Copilot workspace; replace legacy color with approved tokens.
3. `DesignTokensTest::test_blades_style_with_classes_not_inline_styles` fails because AI workspace uses inline CSS and a `<style>` block; move to approved page CSS. Sidebar inline style also appears in the failure output.
4. Re-run full remote acceptance: AI suite passed 59 tests/408 assertions but **aggregate acceptance failed**. No merge-ready claim until green.
5. Staging NVIDIA provider tool schema/telemetry and domain totals require independent release evidence beyond HTTP fakes.

## Branch strategy after documentation is approved
- **Copilot stabilization:** finish fixes on its existing branch (or follow repository PR review policy); merge only after full green.
- **Action foundation:** create `feature/ai-action-foundation` **from latest approved base** (not stale `main` or stale original feature), implement registry/workflow/preview as a separate PR with actions disabled.
- **Purchase Order vertical slice:** separate PR with exactly one controlled business write, reviewers from purchasing/security.
- **Purchase receipt and bill; sales; finance/stock; documents:** independent approved PRs and feature flags.

## Required architecture review before first action PR
Confirm source command schema and business behavior for actual purchase order, supplier bill, goods receipt, sales order/invoice and stock/GL events. Which event posts stock or liability varies by ERP domain and company configuration and **must be traced**, not inferred from names. Record accounting/tax/warehouse owner sign-offs, currency/UOM rules, permission matrix, exact approvals and idempotency.

## Test and release checklist
- [ ] Documentation links relative to repo work and all 25 original filenames preserved.
- [ ] Current vs proposed components accurately marked.
- [ ] No live action enablement slips into read-only Copilot refactor.
- [ ] Required CI suites all green; no test suppression.
- [ ] No company A data from company B; cross-branch/FY and historical replay safe.
- [ ] Read-only source-linked finance/inventory parity and provider failure tests pass.
- [ ] Action schema + permission/kill switch review passed (when next phase begins).
- [ ] One PO approved action with exact idempotent receipt and no unintended stock/GL effect (when enabled).
- [ ] Staging business user UAT, support/audit, backups, manual production approval documented.

## Handoff packaging
This ZIP contains `docs/zoloerp-ai-agent-implementation/docs/ai/README.md` and `01`–`36`. It also contains top-level `REPLACEMENT_GUIDE.md`, `IMPLEMENTATION_MASTER_PLAN.md`, `DOCUMENT_MANIFEST.json` and `DOCUMENT_INDEX.csv`. Review and copy the folder—not the ZIP root—into the repository. No source code is modified by this deliverable.
