# 19 — CI, Staging, Release Controls and VPS Deployment

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Branch/deployment policy
Documentation replacement is separate from application code. Do not merge the current read-only Copilot until its aggregate ERP acceptance gate is green. Future actions must be built in a separate feature branch with `AI_ACTIONS_ENABLED=false` by default; no surprise production activation after merging. Production code, migrations and user data require explicit operator sign-off.

## CI pipeline changes/gates
- Retain existing `company-foundation.yml` and protected aggregate acceptance check; AI jobs are additive and mandatory.
- Run PHP 8.5 Composer/platform/security checks, migrations on isolated MySQL 8.0 and 8.4, all ERP domain suites, AI fake model contract, source-linked golden fixtures and browser tests.
- A separate action workflow job should exercise approval/decline, idempotency and concurrent job retry against disposable DB. No billable provider calls during routine CI.
- Optional staging provider test uses synthetic data and configured budget, with controlled tool-call model contract and telemetry validation.
- No CI bypass, skipped failing suites, broad snapshots or assertions weakened without documented business reasons.

## Latest CI status and remaining release validation
At SHA `14195d8`, run [42870009218](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/42870009218) passed all 11 acceptance jobs, including AI, commercial, operations and aggregate gate. Run 42846498957 at the previous SHA had ProductAlias and DesignTokens failures; these are historical. Preserve green CI with ongoing regressions. **Not yet proven by this evidence:** production AI adoption, a live NVIDIA staging tool-call contract, setup/write capabilities, financial posting correctness under AI, or safe rollout of an action agent.

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


---

## V3 additional onboarding release gate
Setup Agent requires a distinct bootstrap permission contract and tests before it is enabled for installation/group admins. Test company provisioning on a disposable database with existing and new groups, ensure `MAIN` branch/FY/warehouse, default units/industry settings, chart of accounts, payment accounts, document series, GST rules and memberships are created **exactly once**. Do not expose company setup to general AI users. A successful AI test job or complete CI run is not sufficient without audited provisioning, rollback/recovery and activation/readiness checks. Lifecycle corrections require integration tests against real purchasing/sales/finance/stock services, not model-created test records.
