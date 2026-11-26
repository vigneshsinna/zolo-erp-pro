# PR 03 — Exception-first triage UI / test guide

Depends on PR #31. Route: `/ai/operations/exceptions` from AI Operations.

## Implemented
- New read-only Needs Review page listing pending action proposals and uncertain/reconciliation outcomes.
- Deterministic priority: critical for uncertain execution, high for expired proposals or preview warnings, normal for other pending proposals.
- Existing Action Review Center remains the only approval entry. No posting, rejection or write endpoints here.
- All queries limited to current **company + branch + financial year**, with nonapprovers further limited to their own proposals.
- No LLM invocation, no new migrations, no credit debit.

## Automated checks (test MySQL only)
```bash
php artisan test tests/Unit/AiExceptionClassifierTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiOperationsPageTest.php
php artisan test tests/Feature/Ai/Commercial/PurchaseBillActionTest.php
```

## Manual QA
1. Sign in as company approver, open AI Operations → Needs Review; open Action Review Center from the page.
2. Create a pending **unapproved** proposal with preview warnings; ensure high-priority triage appears.
3. Expire a disposable proposal; ensure it is marked expired and not directly approved from triage.
4. Simulate an uncertain execution in an isolated fixture; ensure critical reconciliation advice and no retry control.
5. Sign in as a nonapprover; verify only the actor's proposals appear.
6. Change company, branch or FY; confirm no other context's items appear.
7. Disable AI action foundation; verify informational state and no exception.
8. Confirm the page triggers **zero** ERP transactions and zero model calls.

## Release gate
Run the design-token lint, unit tests, AI and commercial regression suites. This PR does not automate exception approval.
