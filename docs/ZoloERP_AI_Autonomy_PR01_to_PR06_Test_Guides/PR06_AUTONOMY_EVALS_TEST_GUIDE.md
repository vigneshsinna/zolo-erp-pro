# PR 06 — Autonomy evaluation and release safety gate

**GitHub:** https://github.com/vigneshsinna/zolo-erp-pro/pull/36  
**Base:** PR 05 feature branch

## Changes to verify
- Required scenario evidence: 100 Indian purchase bills, 100 Copilot tasks, 50 difficult cases.
- Dataset/manifest validations are **not** counted as executed end-to-end ERP workflows.
- Required proof: signed approval integrity, tenant/branch/FY isolation, no unapproved writes, no duplicate postings, deterministic tax, deduplicated events, safe reversal requests and credit limits.
- Measured 5/10/20 concurrent-user load, live staging evidence and independent security/accounting sign-off are required for any future autonomous posting release.
- Current release report deliberately returns `production_approved=false` and status `BLOCKED`.

## Automated tests (disposable MySQL)
```bash
php artisan migrate --env=testing --force
php vendor/bin/phpunit -c phpunit.ai.xml --filter 'AiOperationsPageTest|AiAutonomyPolicyTest|AiEventInboxTest'
php vendor/bin/phpunit tests/Unit/AiExceptionClassifierTest.php
php vendor/bin/phpunit tests/Unit/AutonomyExecutionPreflightTest.php
php vendor/bin/phpunit tests/Unit/Ai/AutonomyReleaseGateTest.php
php artisan test tests/Feature/Ai/Infrastructure/AiActionReversalRequestTest.php
php scripts/verify_ai_autonomy_release.php --output=artifacts/ai-evaluation/autonomy-release.json
php scripts/verify_ai_autonomy_release.php --require-production-ready
```
The final command must **exit 1** at this stage: production autonomy is not authorized. The normal evidence command should exit 0 only after confirming the blocked state.

## QA / production readiness checklist
1. Validate actual invoice extraction against GSTIN, HSN, tax splits, totals, handwritten and multipage ground truth, not only static fixture existence.
2. Execute Copilot create/edit/clarification flows against disposable ERP company data, checking actual before/after records.
3. Measure p50/p95 response, NVIDIA requests/minute, retry/throttle behavior, credit deductions and duplicates at 5/10/20 users.
4. Verify wrong tenant/branch/FY and permission-revocation attempts always fail.
5. Confirm deterministic arithmetic/tax/duplicate/stock checks consume zero LLM tokens.
6. Review real staging proof and accounting/security approval, then prepare a **separate** PR for any future posting enablement.

**Detailed in-repository guide:** https://github.com/vigneshsinna/zolo-erp-pro/blob/codex/ai-autonomy-release-gates/docs/ai/autonomy/PR06-autonomy-evals-test-guide.md
