# PR 03 — Deterministic exception triage

**GitHub:** https://github.com/vigneshsinna/zolo-erp-pro/pull/33  
**Base:** PR 02 feature branch

## Changes to verify
- `/ai/operations/exceptions` displays pending actions and executions that require reconciliation.
- Deterministic priorities: critical for uncertain execution, high for expired proposals and preview warnings, normal for other pending items.
- The screen never approves, rejects, posts or retries a transaction. Actions remain in the existing signed Action Review Center.
- Managers view company-scoped proposals; nonapprovers only view their own, always within the active company, branch and FY.

## Automated tests
```bash
php vendor/bin/phpunit tests/Unit/AiExceptionClassifierTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiOperationsPageTest.php
php artisan test tests/Feature/Ai/Commercial/PurchaseBillActionTest.php
php vendor/bin/phpunit tests/Unit/DesignTokensTest.php
```

## Manual staging checklist
1. Create a pending unapproved test proposal and verify it is listed.
2. Add a preview warning: the row should display high priority.
3. Expire a test proposal: it should explain that a fresh preview is needed.
4. Use a disposable uncertain-execution fixture: show critical priority and no retry button.
5. Confirm review-center link works and preserves its permission checks.
6. Sign in as another actor without approver rights; verify the proposal cannot be viewed.
7. Switch company, branch or FY and confirm all previous records disappear.
8. Disable the AI action foundation; the page should show a safe informational state.
9. Confirm zero AI credits and zero business writes caused by viewing the page.

**Detailed in-repository guide:** https://github.com/vigneshsinna/zolo-erp-pro/blob/codex/ai-exception-triage/docs/ai/autonomy/PR03-exception-triage-test-guide.md
