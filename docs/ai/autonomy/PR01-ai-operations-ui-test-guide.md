# PR 01 — AI Operations UI Foundation: implementation and test guide

## Scope
- Add a single **AI Operations** navigation entry and a global **Ask Zolo** shortcut.
- Add the read-only `/ai/operations` landing page for scoped action counts, recent activity and owned scheduled rules.
- Reuse the existing Copilot (`/ai`) and Action Review Center (`/ai/action-review`).
- Never approve, execute, post, modify policy or create schedules from this page.

## Security
- Route uses `auth`, `company.context`, `common` plus an explicit server-side `ai.use` check.
- Action counts are filtered by company, branch and financial year; nonapprovers see only their own proposals.
- Scheduled rules always show only those owned by the logged-in user.
- Disabled AI, foundation or scheduling states do not trigger operational queries.
- No new schema, write endpoint, model call or AI credit debit.

## Automated test
```bash
php artisan test tests/Feature/Ai/Infrastructure/AiOperationsPageTest.php
php artisan test tests/Feature/Ai/Infrastructure/ScheduledRulesTest.php
php artisan test tests/Feature/NavigationWebTest.php
```
Use the repository's configured MySQL test environment, not an existing production database. Verify the test suite is configured for MySQL before running.

## Manual QA checklist
1. Enable AI for a disposable company, sign in as an AI-authorized admin, and open **AI Operations** from the left navigation.
2. Confirm **Ask Zolo** opens the existing chat with conversation history and attachments unchanged.
3. Confirm **Needs Review** and **Completed Actions** link to the existing review desk.
4. Create an unapproved test proposal; confirm it shows in pending and does not post a purchase or journal.
5. Create a scheduled low-stock rule; confirm it appears as a schedule but does not auto-post.
6. Sign in with an authorized nonapprover; confirm another user's proposals are not visible.
7. Change company/branch/FY; verify counts and schedules cannot leak from the previous context.
8. Disable AI, action foundation, then scheduling in turn. Verify safe empty/disabled states and no exceptions.
9. Verify sidebar in collapsed and expanded modes, top bar in desktop/mobile and dark theme.
10. Compare purchases, stock movements, journals and model-credit records before/after viewing: **no writes**.

## Release gate
Do not merge without passing focused PHPUnit tests, existing AI regression tests, browser smoke tests and a manual tenancy/security check. Existing approvals remain mandatory.

## Known limits
This is the UI foundation only. Per-tenant autonomy policies, event ingestion, automatic financial execution and one-click reversals are NOT implemented here.
