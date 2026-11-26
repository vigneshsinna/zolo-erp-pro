# 19 — CI, Staging, Release Controls and VPS Deployment

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Branch/deployment policy
Documentation replacement is separate from application code. Do not merge the current read-only Copilot until its aggregate ERP acceptance gate is green. Future actions must be built in a separate feature branch with `AI_ACTIONS_ENABLED=false` by default; no surprise production activation after merging. Production code, migrations and user data require explicit operator sign-off.

## CI pipeline changes/gates
- Retain existing `company-foundation.yml` and protected aggregate acceptance check; AI jobs are additive and mandatory.
- Run PHP 8.5 Composer/platform/security checks, migrations on isolated MySQL 8.0 and 8.4, all ERP domain suites, AI fake model contract, source-linked golden fixtures and browser tests.
- A separate action workflow job should exercise approval/decline, idempotency and concurrent job retry against disposable DB. No billable provider calls during routine CI.
- Optional staging provider test uses synthetic data and configured budget, with controlled tool-call model contract and telemetry validation.
- No CI bypass, skipped failing suites, broad snapshots or assertions weakened without documented business reasons.

## Current branch blockers to close first
At SHA `6ded371`, run `42846498957` failed because one commercial `ProductAliasTest` assertion expected 4 occurrences but found 5, and operations `DesignTokensTest` reports old accent and inline style usage in `backend/ai/workspace.blade.php` (plus sidebar styling). Confirm whether alias issue is inherited on `feature/ai-agent-foundation` before change. Refactor UI styles into approved page CSS and correct runtime behavior; rerun full remote acceptance.

## Release preflight
1. Clean reviewed PR + green required tests; no unreviewed domain service edits.
2. DB backup and financial/stock fingerprints; snapshot restore tested.
3. AI-owned migrations additive and dry-run; legacy proposal rows preserved/quarantined.
4. Production-matched PHP 8.5 CLI/FPM and worker configs; shared hosted apps unaffected.
5. Vendor privacy and provider credentials reviewed; feature disabled by default, limits enforced.
6. Source links, permission revocation, disabled action routes and high-risk operation denial tested.
7. Support runbook, task status recovery, customer-facing notice and rollback plan signed.

## Deployment order
Deploy code and additive migrations with new flags **off**. Smoke existing ERP core modules and PHP-FPM. Enable read-only Copilot for internal company and approved role, validate live tool result against ERP. For action phase, enable **prepare/preview only** for pilot, then allow one reviewed transaction capability after explicit finance/security sign-off. Expand by capability/company, not by broad global action flag.

## Rollback / incident
First disable action flag then AI flag, stop AI workers if necessary, preserve transaction/audit receipts. Roll back application code only after reviewing migration compatibility; do not casually reverse posted ledger/stock via DB rollback. For a problematic committed transaction use existing ERP reversal service with permissions and audit.
