# PR 05 — Governed execution preflight and reversal review

**GitHub:** https://github.com/vigneshsinna/zolo-erp-pro/pull/35  
**Base:** PR 04 feature branch

## Changes to verify
- Preflight always requires signed human approval for ERP commands, even if autonomy policy preferences say `auto_under_limits`.
- Company approvers may submit a **manual reversal review request** for an actual completed AI execution.
- One request per execution, protected by a company-scoped lock and original execution receipt fingerprint.
- A request **does not reverse** a bill, journal, stock movement, GRN or payment. Actual reversal remains a separate domain-specific workflow.

## Automated tests
```bash
php artisan migrate --env=testing --force
php vendor/bin/phpunit tests/Unit/AutonomyExecutionPreflightTest.php
php artisan test tests/Feature/Ai/Commercial/PurchaseBillActionTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiAutonomyPolicyTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiActionReversalRequestTest.php
```

## Manual staging checklist
1. Open **AI Operations → Reversal review requests** as an approver.
2. Try an unknown UUID, incomplete/uncertain execution and other-company execution: all must be denied.
3. Submit a completed test execution and a reason of at least 12 characters; inspect audit row and receipt hash.
4. Resubmit the same execution: expect one record and no changes to the original evidence.
5. Revoke approval permission and verify the route returns 403.
6. Change branch/FY to prove a completed execution in another scope is inaccessible.
7. Compare purchase bills, stock movements, GL journals, GRN and payment allocations before/after: zero writes caused by the request.
8. Check a policy requesting automatic posting; preflight must still require signed approval.

**Important:** A financial document may have multiple downstream accounting and inventory effects. Do not implement generic delete/undo from this request.

**Detailed in-repository guide:** https://github.com/vigneshsinna/zolo-erp-pro/blob/codex/ai-reversal-governance/docs/ai/autonomy/PR05-reversal-governance-test-guide.md
