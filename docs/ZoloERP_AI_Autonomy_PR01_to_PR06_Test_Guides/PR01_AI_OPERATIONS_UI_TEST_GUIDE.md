# PR 01: AI Operations UI Foundation

**Pull request:** https://github.com/vigneshsinna/zolo-erp-pro/pull/30  
**Branch:** `codex/ai-operations-ui-foundation`  
**Base:** `feature/ai-unified-input-clarification`  
**Commit:** `b97ab2b1033b7a99b9c0c61243035d282a7e8c0f`  
**Status:** Draft PR. Validation is still pending; do not merge yet.

## Implemented
- New, permission-scoped `/ai/operations` page with recent action history, pending and completed counts, and user-owned low-stock schedules.
- Single **AI Operations** link in the left navigation, global **Ask Zolo** shortcut in the top bar.
- Links back to existing Copilot and Action Review Center. No new AI model calls or financial execution.
- Context boundaries: company, branch, FY, actor visibility. Server-side `ai.use` authorization.
- New test: `tests/Feature/Ai/Infrastructure/AiOperationsPageTest.php`.

## Automated tests
Use an isolated, configured MySQL test database, not production.

```bash
php artisan test tests/Feature/Ai/Infrastructure/AiOperationsPageTest.php
php artisan test tests/Feature/Ai/Infrastructure/ScheduledRulesTest.php
php artisan test tests/Feature/NavigationWebTest.php
```

## Manual acceptance steps
1. Sign in to a disposable company with AI enabled and `ai.use` permission. Open **AI Operations** from the left menu.
2. Confirm the global **Ask Zolo** topbar shortcut opens the existing Copilot. Existing conversation history, media and review flow must remain functional.
3. Open **Needs Review** and **Completed Actions** to confirm the existing Action Review Center opens with the requested status.
4. Prepare a test purchase order without approving it; confirm it is pending and **no purchase, stock movement or ledger journal** is posted.
5. Create an opt-in low-stock rule and confirm it appears in Scheduled Work but does not automatically post a PO.
6. Switch company/branch/FY and confirm the displayed counts and activity are scoped to the selected context. A user without approval permission must only see their own proposals.
7. Disable AI for the company, then disable the action foundation and scheduling separately. Verify disabled/empty states and no 500 errors.
8. Check responsive behavior, dark theme, collapsed sidebar and keyboard access.
9. Confirm merely viewing this page does not debit AI credits, contact a model provider, change policies or create ERP records.

## Release requirements
- Verify GitHub Actions are green for the exact commit.
- Perform browser and cross-tenant smoke tests in staging.
- Leave execution gate and mandatory human approval unchanged.

## Out of scope
Autonomy policies, auto-execution, general event listeners, reversal coordination, and full eval gates are separate PRs. No part of this PR allows auto-posting.
