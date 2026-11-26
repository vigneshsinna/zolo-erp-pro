# PR 06 — Autonomy release evidence and CI test guide

## Implemented
- Release-gate evaluator with required 100 invoice, 100 Copilot and 50 difficult workflow proofs.
- Distinguishes corpus/dataset validation from actual model-to-ERP executed workflows.
- Requires security evidence for tenant isolation, signed approvals, duplicate-write prevention, tax determinism, event deduplication, reversal safety, credit limits and company/branch/FY isolation.
- Requires measured concurrent runs of 5/10/20 users, live staging and independent accounting/security sign-offs.
- Adds a dedicated GitHub Actions MySQL 8.4 workflow for targeted autonomy regression tests, design rules, clean migrations and an evidence artifact.

## Safety
Automatic ERP posting stays disabled. This PR never treats stored policy preferences or a manually supplied JSON report as authorization to post financial records. The machine-generated status must remain BLOCKED until later independently reviewed work.

## Local / CI validation
Run against a disposable MySQL testing database:

~~~bash
php artisan migrate --env=testing --force
php vendor/bin/phpunit -c phpunit.ai.xml --filter 'AiOperationsPageTest|AiAutonomyPolicyTest|AiEventInboxTest'
php vendor/bin/phpunit tests/Unit/AiExceptionClassifierTest.php
php vendor/bin/phpunit tests/Unit/AutonomyExecutionPreflightTest.php
php vendor/bin/phpunit tests/Unit/Ai/AutonomyReleaseGateTest.php
php scripts/verify_ai_autonomy_release.php --output=artifacts/ai-evaluation/autonomy-release.json
php scripts/verify_ai_autonomy_release.php --require-production-ready
~~~

**Expected:** The default report shows BLOCKED with production_approved=false. The last command must exit with status 1; that is the desired result while autonomous posting is unimplemented.

## Future production-evaluation matrix
1. Execute 100 Indian purchase bills end to end with ground-truth GSTIN, HSN, CGST/SGST/IGST, discounts, round-off, handwriting and multi-page evidence.
2. Execute 100 Copilot create/edit requests and 50 difficult missing-master, ambiguity, tax and warehouse cases with actual ERP state assertions.
3. Exercise 5, 10 and 20 concurrent users, measuring p95 response time, LLM RPM, credits, retried requests, throttling and duplicate postings.
4. Verify cross-company, cross-branch, cross-FY and revocation cases; signed proposal hash changes and stale approvals must fail.
5. Confirm all arithmetic, reorder, tax and duplicate-invoice checks run deterministically with zero AI charges.
6. Reconcile uncertain outcomes before retry, prove compensating ERP workflows and obtain human security/accounting sign-offs.

## Known limit
Existing 250-item corpus tests chiefly validate fixture datasets. They are not evidence that 250 complete business workflows have been executed. This PR introduces gates; it does not invent missing results.
