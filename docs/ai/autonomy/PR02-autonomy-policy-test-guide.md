# PR 02 — Tenant / Action Autonomy Policy Foundation

**Draft PR:** A stacked change on PR #30. Do not merge or enable automatic business execution.

## What this PR adds
- `ai_autonomy_policies` with per-company, per-registered-action requested autonomy settings, whole-rupee amount cap, daily action limit and optimistic version.
- Append-only `ai_autonomy_policy_events` with actor, version, prior/new snapshots and event digest.
- A server-side `AutonomyPolicyService` that checks company admin authority, action registry membership and risk-tier restrictions.
- Administrator policy preference screen reachable from AI Operations when `AI_AUTONOMY_POLICY_MANAGEMENT_ENABLED=true`.
- `evaluate()` intentionally returns `automatic_execution_allowed=false` regardless of preferences. Current signed human approval remains mandatory.

## Permission / guardrails
- Only active company Admin/Owner with `ai.use` and `ai.action.approve` can manage settings.
- r3 financial and r4 restricted actions cannot select automatic policy levels.
- r2 operational actions can request `auto_under_limits` only as an *inactive, future-ready preference*; the current executor never honors it.
- No cross-company policy read or write; conflicting versions fail closed.
- No new action adapter, posting path, model call or credit debit.
- Global management gate defaults **off**. Keep it off in production until tested.

## Test in an isolated MySQL environment
```bash
php artisan migrate --env=testing --force
php artisan test tests/Feature/Ai/Infrastructure/AiAutonomyPolicyTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiOperationsPageTest.php
php artisan test tests/Feature/Ai/Commercial/PurchaseBillActionTest.php
```

## Manual QA
1. Disable the management flag and verify that the page is inaccessible.
2. Enable it only for staging and sign in as a company owner. Open AI Operations → Autonomy policies.
3. Save a request for an operational PO capability with `auto_under_limits`, ₹10,000 and 2 daily actions.
4. Inspect the DB row and immutable event. Refresh; the version should increase after each update.
5. Try a stale form version; verify no overwrite and no extra event.
6. Try an automatic level for `purchase_bill.create.v1`; verify rejection.
7. Confirm no proposal is approved, no order/bill/journal is created, and `ApprovedActionExecutor` still requires signed human approval.
8. Switch companies and verify no policy is visible or writable across company boundaries.
9. Remove owner rights or disable company AI. Confirm management is denied.
10. Run full AI regression tests before merging.

## Known limits
This is a policy preference foundation. The levels do not yet govern existing user interactions or authorize autonomous execution. A later PR must implement policy evaluation, budgets, exact transaction authorization, and reversible domain workflows before any automatic posting.
