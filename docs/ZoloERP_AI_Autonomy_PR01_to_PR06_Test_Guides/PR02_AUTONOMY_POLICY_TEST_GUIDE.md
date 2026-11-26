# PR 02 — Tenant and action autonomy policy foundation

**GitHub:** https://github.com/vigneshsinna/zolo-erp-pro/pull/31  
**Base:** PR 01 feature branch  
**Default:** Management flag off; automatic ERP posting still forbidden.

## Changes to verify
- Per-company, per-registered-action preferences: suggest, draft, and risk-qualified future automatic levels.
- Amount ceiling and daily action limit are saved with optimistic versioning and policy audit history.
- Company administrators only; cross-company access must fail.
- Saving an automatic *preference* must **never** create an approval or permit ERP posting.

## Automated tests (isolated testing MySQL only)
```bash
php artisan migrate --env=testing --force
php artisan test tests/Feature/Ai/Infrastructure/AiAutonomyPolicyTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiOperationsPageTest.php
php artisan test tests/Feature/Ai/Commercial/PurchaseBillActionTest.php
php vendor/bin/phpunit tests/Unit/DesignTokensTest.php
```

## Manual staging checklist
1. Set `AI_AUTONOMY_POLICY_MANAGEMENT_ENABLED=false`; access to `/ai/operations/policies` should be forbidden.
2. Enable in staging, sign in as company owner, then navigate to **AI Operations → Autonomy policies**.
3. Set an eligible operational action to `auto_under_limits` with ₹10,000 and 2/day, then save.
4. Refresh: preference and incremented version must persist. Inspect `ai_autonomy_policy_events` for an append-only change record.
5. Replay the old form's `expected_version`: update must fail with no extra audit event.
6. Attempt automatic posting preference for `purchase_bill.create.v1`: reject at the risk-tier check.
7. Switch company or revoke approver/admin privileges: the old policy must not be readable or writable.
8. Confirm approval signatures, purchases, journals, inventory and credit ledgers are unaffected.

## Release gate
The existing `ApprovedActionExecutor` still demands signed human approval. This is a **preference foundation**, not permission to execute. Keep the PR in draft until full ERP CI and manual scope checks pass.

**Detailed in-repository guide:** https://github.com/vigneshsinna/zolo-erp-pro/blob/codex/ai-autonomy-policy-foundation/docs/ai/autonomy/PR02-autonomy-policy-test-guide.md
