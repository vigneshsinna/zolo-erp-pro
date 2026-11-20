# PR 05 — Governing execution and manual reversal requests

**This PR does not reverse ERP transactions or enable automatic financial posting.**

## Delivered
- A deterministic preflight representation proving an autonomy preference is never signed ERP execution authorization.
- Audit-preserving table for reversal *review requests* tied to a completed, company-scoped AI execution.
- Scoped approver-only UI to request review, with a reason and exact receipt hash.
- Idempotent one-request-per-execution under a company lock; reject incomplete or uncertain execution receipts.
- No direct changes to `ApprovedActionExecutor`, accounting reversal APIs, stock movements, purchase bills or payments.

## Isolated test commands
```bash
php artisan migrate --env=testing --force
php artisan test tests/Unit/AutonomyExecutionPreflightTest.php
php artisan test tests/Feature/Ai/Commercial/PurchaseBillActionTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiAutonomyPolicyTest.php
```

## Manual QA
1. Sign in as company approver. Open AI Operations → Reversal review requests.
2. Attempt to request reversal of an unknown UUID, an incomplete execution or an execution in another company: deny.
3. From a **completed** staging execution, submit a detailed reason. Confirm the request is created, but no GL, stock, bill, payment or GRN writes occur.
4. Resubmit same execution ID; verify only one request exists, preserving the first reason and receipt hash.
5. Change branch/FY and confirm source execution lookup is denied even in the same company.
6. Sign in as user without `ai.action.approve`; confirm 403.
7. Attempt to promote an `auto_under_limits` preference to execution authority; preflight must still require signed approval.
8. Reconcile original execution receipt against the ERP domain records; validate the audit hash.
9. Confirm there is **no** action to bypass domain-owned reversal logic.

## Future work required before actual reversing
Build and validate separate compensating flows for each resource type, including bill credit notes, stock corrections, allocation/GRN handling and period-closure rules. A journal reversal alone is insufficient.
