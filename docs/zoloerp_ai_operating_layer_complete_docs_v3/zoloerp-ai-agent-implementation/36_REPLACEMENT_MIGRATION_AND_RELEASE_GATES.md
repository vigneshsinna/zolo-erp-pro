# 36 — Replacing the Old Documents and Safely Migrating the Product

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## What this pack replaces
The legacy folder held README plus documents `01`–`24`, first drafted against `main` before AI implementation. Those docs are unchanged in `feature/ai-agent-foundation` and `refactor/erp-copilot-stabilization`; some startup instructions (`git switch -c feature/ai-agent-foundation`, `make:tool CustomerOutstanding`) are now stale and can recreate removed wrappers. This pack preserves **all 25 old filenames**, updates `25`–`36`, and adds `37`–`39` for initial company setup and mandatory ERP transaction lifecycle enforcement. The original 25 filenames and existing numbering remain stable.

## Safe repository replacement sequence
Do this only in an authorised local Git checkout, not on production:
```bash
git fetch origin
git switch refactor/erp-copilot-stabilization
git pull --ff-only origin refactor/erp-copilot-stabilization
# Confirm current branch and clean tree. If branch was merged, choose approved updated base.
git switch -c docs/ai-operating-layer-v3
# Replace docs/zoloerp-ai-agent-implementation/docs/ai with the contents of this pack.
# Inspect git diff --stat and all document changes; do not edit app/ or migrations in docs-only PR.
git status --short
git diff --check
git diff -- docs/zoloerp-ai-agent-implementation/docs/ai
# Commit and open a documentation-only PR for review after owner approval.
```
A copy operation may overwrite **README + all existing `01`–`36` documents**; do not delete unrelated docs or accidentally replace the coding-agent plan stored outside `docs/ai`. New `37`–`39` files are additions. The ZIP root contains the exact repository-relative directory structure.

## Docs are not code
Installing documentation makes no changes to app behavior. Preserve current strict read-only `AiToolManifest` and proposal quarantine. `20_DELIVERY_BACKLOG.md` explicitly separates code phases and their gates; the action engine described in later documents is not implemented by copying these Markdown files.

## Current release evidence (reviewed SHA `14195d8`)
The latest workflow [42870009218](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/42870009218) **passes 11/11 jobs** including AI, commercial, operations, clean MySQL and the aggregate ERP gate. Earlier ProductAlias and DesignTokens failures were at the previous SHA `6ded371`; do not present them as current blockers. Continue to require live synthetic provider-contract validation, ERP monetary reconciliation and sign-off before enabling new write capabilities. Company creation and master provisioning are available through existing non-AI Laravel services, but the AI Setup Agent is **proposed, not implemented**.

## Branch strategy after documentation is approved
- **Copilot stabilization:** latest reviewed CI is green. Preserve results and complete remaining domain/prover staging evidence before the approved merge/rollout.
- **Action foundation:** create `feature/ai-action-foundation` **from latest approved base** (not stale `main` or stale original feature), implement registry/workflow/preview as a separate PR with actions disabled.
- **Guided Initial Setup Agent:** separate first administrative action PR with installation/group-admin gate, company/group and scoped follow-up master setup.
- **Purchase Order vertical slice:** subsequent separate PR with exactly one controlled commercial write, reviewers from purchasing/security.
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
- [ ] Initial Setup Agent admin-only company creation with exact receipt, no duplicate starters and safe transition to scoped masters (when enabled).
- [ ] One PO approved action with exact idempotent receipt and no unintended stock/GL effect (when enabled).
- [ ] Staging business user UAT, support/audit, backups, manual production approval documented.

## Handoff packaging
This ZIP contains `docs/zoloerp-ai-agent-implementation/docs/ai/README.md` and `01`–`39`. It also contains top-level `REPLACEMENT_GUIDE.md`, `IMPLEMENTATION_MASTER_PLAN.md`, `DOCUMENT_MANIFEST.json` and `DOCUMENT_INDEX.csv`. Review and copy the folder—not the ZIP root—into the repository. No source code is modified by this deliverable.

## V3 cross-cutting requirements
The initial company/master configuration workflow is specified in `37_GUIDED_INITIAL_SETUP_AGENT.md` with source-confirmed vs future capabilities in `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md`. **ERP Transaction Lifecycle Enforcement** in `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` is mandatory for every existing-record edit, cancellation, invoice correction, return, reversal and adjustment. A chat instruction never creates permission to modify a submitted, posted or paid record directly; proposed revisions return through fresh ERP validation and human approval. Current code remains a read-only Copilot pending separate action implementation.

## V3 replacement scope and suggested next branch
This pack is a **complete replacement** for README + existing `01`–`36` (all preserved by name), plus **new documents `37`, `38`, `39`**. Use `docs/ai-operating-layer-v3` for a docs-only PR based on the latest approved code branch. The latest reviewed branch SHA is `14195d8` with aggregate CI passed, not the old historical failed run. Copying docs must not enable any model write tool or change the application's default settings.

The recommended next *implementation* sequence is: Copilot provider/domain sign-off → separate Action Foundation → admin-limited Initial Setup Agent first (bootstrap group/company and scoped masters) → business readiness → PO vertical slice → goods receipt/bill, sales, finance and later autonomous workflows. Every action must implement the mandatory `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` constraints. Pre-company setup must not be routed through company-scoped chat middleware with an invented company or FY.
