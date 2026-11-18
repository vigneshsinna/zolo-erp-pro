# D08 release blocker follow-up

Baseline: `166e74a19f0d33073ad83369aceb022e87ac5044` (PR #15). The executable changes are at `fc06a3f2f7779c702fee72354fbe3d270612ede4` in [draft PR #16](https://github.com/vigneshsinna/zolo-erp-pro/pull/16/files). Tests used PHP 8.5.12 and MySQL 8.4.4 on a dedicated loopback instance at port 3308. Each suite that resets tables used its own named disposable schema.

## Payment capture and replay

`AiTopupService::recordPaymentSuccess()` read `$rawPayload` in a closure that did not capture it. At baseline, the duplicate webhook fixture raised `Undefined variable $rawPayload`. The validation below that failure also accepted authorized payments, omitted fields and the pre-tax amount. The early event replay looked up only the internal ID, which could return null for a webhook carrying the provider order ID.

- `app/Ai/Billing/Services/AiTopupService.php` captures the payload, locks the provider-bound order, validates captured status, full amount including tax, INR, provider order identity and payment identity, and claims the existing unique provider/event record in the same transaction as the ledger grant. Identical retries resolve the same order through either order identifier. A conflicting event cannot grant credits. Replaying the same captured payment after refund cannot grant it again.
- `app/Http/Controllers/Api/V1/AiCreditController.php` requires actual order, payment and event identities from the signed payload and stops synthesizing random payment IDs.
- `tests/Feature/Ai/Billing/{CreditSecurityTest,ConcurrentCreditLoadTest,CreditLedgerRulesTest}.php` now use full captured fixtures. The added test covers invalid capture, underpayment, wrong order/currency/payment, missing status, nested payload retry through both IDs and cross-account event reuse. Existing sequential replay tests continue to check one grant. The five-retry test name now states what it measures.

The baseline focused billing run reported 11 tests, 68 assertions, one error and one failure. The final capture/replay case passed with 30 assertions, including two HMAC-authenticated HTTP callbacks. The combined billing and revision suite passed 33 tests with 164 assertions before the final cross-account and HTTP checks were added. Rollback: revert the billing patch as a unit only if required. Preserve ledger rows and payment events, and reconcile pending callbacks before restoring any older payment handler.

## Laravel application setup and configuration defaults

`tests/Unit/Ai/Billing/SubscriptionAndPaymentHardeningTest.php` now uses the project's Laravel test case. A raw PHPUnit test did not bind the application environment needed by HMAC verification, causing `Target class [env] does not exist` in the D08 full run. Requiring the configuration files had also evaluated live process overrides. The tests now remove each relevant variable from the process, `$_ENV` and `$_SERVER` during the default check and restore it afterward. The defaults checked are subscription required, direct purchase bill disabled and empty webhook secret. The production configuration files are unchanged.

The D08 baseline had the HMAC bootstrap error and both default assertion failures. The final test class passed six tests with 22 assertions. Rollback: revert this test-only change.

## CI disposable database

`.github/workflows/company-foundation.yml` provisioned and migrated `zolo_test_foundation` for AI, but `phpunit.ai.xml` forces both the MySQL audit name and Laravel database to `zolo_test_ai_release`. The MySQL service, job guard name and migration target now agree on `zolo_test_ai_release` for AI. The other matrix names and `UsesAiMysql` are unchanged. Rollback: revert the workflow patch together with any paired CI schema configuration; retain the guarded test database contract.

## Normal PO and commercial fixtures

The repeated MySQL run finished in 132.4 seconds with 18 tests, 51 assertions and ten failures. PHPUnit had buffered its progress, and the disposable fixture was repeatedly building its schema. The first PO request also failed quickly on SQLite. Both engines showed HTTP 503 from missing `lot_number`. The failure was a fixture mismatch, and the run provided no deadlock evidence.

`tests/Support/CommercialTestCase.php` applies the existing lot number migration and optical product and sale header migration after their prerequisites. Linux company acceptance and the focused MySQL accounting replay reported missing `sph`, including in suites extending the company context fixture directly. The packing and optical sales-line migrations now run in `tests/Support/CompanyContextTestCase.php`, the common parent of those suites. The accounting replay passed with seven assertions after the first fixture move. The company writer, numbering and platform cases passed 40 tests with 275 assertions and three MySQL-only skips on SQLite after the final move. Existing `PurchaseOrderLifecycleTest` and `CommercialUiWiringTest` verify normal PO creation, editing, receiving and sales/purchase form wiring. Rollback: revert the fixture changes only.

`tests/Feature/PlatformMigrationTest.php` expected five profiles even though the current capability migration seeds six. The test now records all profile IDs before re-running the migrations and asserts the same ordered IDs after the repeat. The existing adoption now passes the idempotency test without weakening its foreign-key and company decision checks. Rollback: revert the test change.

## Operations acceptance findings

The first Linux operations run reported 81 tests and 670 assertions with one error and one failure.

- The FMCG case purchase received all 144 base units. The new batch row had a null company ID because `ProductBatch::firstOrCreate()` discarded that guarded attribute on insert. The same premature insert omitted manufacturing date and MRP. FEFO correctly excludes the unowned batch. `app/Models/ProductBatch.php` now exposes a company-scoped create-or-load method with a fixed allowlist of metadata. `app/Services/ERP/PurchaseService.php` and `SaleService.php` call it under their existing company write lock. The purchase passes the reviewed batch metadata. The regression in `tests/Feature/IndustryFlowIntegrationTest.php` now asserts company ownership, expiry, 144 received units and the existing MRP and FEFO sale result. It reproduced the error before the fix and passed 13 assertions afterward on both SQLite and MySQL. Existing batches are read as-is. Rollback: revert the code commit without changing batch rows. Orphaned historical rows, if present, need separate ownership review.
- `DesignTokensTest` found 16 inline style attributes in the AI and sale Blade views. The fix moves the same dimensions, spacing and font sizes into `public/css/pages/ai-workspace.css` and `public/css/commercial-entry.css`. Optical badge visibility uses the existing Bootstrap `d-none` class. `DesignTokensTest` passed all four checks after the change, and the Edge conversation smoke passed with fixture API responses. Rollback: revert both view and stylesheet changes together.

## Independent-session workflow revision and UOM locking

The D08 test exercised stale revisions sequentially. `tests/Feature/Ai/Conversation/IndependentWorkflowRevisionTest.php` and `tests/Support/ai_workflow_revision_worker.php` now start two Laravel processes that establish distinct MySQL connections. Both read revision 1 before a start barrier opens. Exactly one applies revision 2, one gets 409 and reloads revision 2, and one patch event persists. The test exercises the existing workflow state service. The existing HTTP tests cover the route's 409 response. Rollback: remove the two test files.

On the disposable instance, `EXPLAIN SELECT * FROM units WHERE company_id=1 ORDER BY id FOR UPDATE` used `units_company_id_index`. A separate two-connection, read-only locking probe on four fixture units showed MySQL error 3572 (`NOWAIT`) when a second session tried to lock an unrelated same-company unit. It acquired the lock after rollback. The existing company row already serializes same-company PO commands, while the UOM lock adds contention with unit maintenance. Lock scope grows with the number of units and index range locking can also affect inserts. Production contention and latency were not measured. Source hashing and lock scope stay intact pending real staging measurements.

## Commands and release status

The temporary configurations below copy the tracked PHPUnit configurations and point them at the isolated MySQL server. The AI guard remains required. Scenario evidence was directed to ignored scratch files with `AI_SCENARIO_EVENTS_PATH`.

```powershell
php vendor/bin/phpunit -c scratch/conversation.phpunit.xml tests/Unit/Ai/Billing/SubscriptionAndPaymentHardeningTest.php tests/Feature/Ai/Billing/ConcurrentCreditLoadTest.php --log-junit scratch/blockers-billing-red.xml
php vendor/bin/phpunit -c scratch/conversation.phpunit.xml tests/Feature/Ai/Billing/CreditSecurityTest.php --filter test_only_bound --log-junit scratch/blockers-payment-final.xml
php vendor/bin/phpunit -c scratch/conversation.phpunit.xml tests/Unit/Ai/Billing/SubscriptionAndPaymentHardeningTest.php --log-junit scratch/blockers-explicit-overrides.xml
php vendor/bin/phpunit -c scratch/conversation.phpunit.xml tests/Feature/Ai/Conversation/IndependentWorkflowRevisionTest.php tests/Feature/Ai/Billing/CreditSecurityTest.php tests/Feature/Ai/Billing/ConcurrentCreditLoadTest.php tests/Feature/Ai/Billing/CreditLedgerRulesTest.php tests/Unit/Ai/Billing/SubscriptionAndPaymentHardeningTest.php --log-junit scratch/blockers-focused.xml
php vendor/bin/phpunit -c scratch/d08-domain.phpunit.xml tests/Feature/PurchaseOrderLifecycleTest.php tests/Feature/CommercialUiWiringTest.php --stop-on-error --log-junit scratch/blockers-po-red.xml
php vendor/bin/phpunit -c scratch/d08-domain.phpunit.xml tests/Feature/PurchaseOrderLifecycleTest.php tests/Feature/CommercialUiWiringTest.php --debug --log-junit scratch/blockers-po-green.xml
php vendor/bin/phpunit -c scratch/conversation.phpunit.xml --debug --log-junit scratch/blockers-complete-ai.xml
php artisan migrate --force
php vendor/bin/phpunit -c phpunit.ai.xml --debug --log-junit scratch/blockers-ci-ai.xml
```

The last two commands used the CI-named schema `zolo_test_ai_release`. The local ERP matrix used `php vendor/bin/phpunit -c phpunit.<suite>.xml --log-junit scratch/blockers-<suite>.xml` for each suite other than AI. The official matrix including both MySQL clean-install versions ran in [GitHub Actions run 88](https://github.com/vigneshsinna/zolo-erp-pro/actions/runs/44244971235).

- The complete local AI run passed 405 tests and 4,120 assertions. The CI-named local run passed 405 tests and 4,123 assertions, including the cross-account event test, with zero failures or skips. It loaded the test class before the final six signed HTTP assertions were added. The final signed HTTP case passed separately with 30 assertions. Linux CI run 88 passed all 405 AI tests with 4,113 assertions at `fc06a3f`.
- The normal PO/UI run passed 18 tests and 126 assertions with zero failures or skips on MySQL. Its 521-second runtime overlapped separate matrix suites rebuilding schemas. The same focused run passed with the final shared fixture on SQLite. Rendered sale and purchase routes passed 2 tests and 24 assertions against the seeded disposable schema. Edge passed the fixture-based bill/payment browser smoke alongside the AI conversation check.
- The full local operations run completed with 81 tests, 665 assertions and three MySQL-only skips on SQLite. The FEFO regression passed on MySQL independently.
- The local legacy MySQL suite passed 46 tests and 354 assertions. The commercial concurrency suite passed 6 tests and 138 assertions. Both had zero failures and skips.
- Composer strict validation, locked platform checks and locked audit passed. The clean local MySQL 8.4 migration completed, application and Manufacturing pending checks returned zero, and route and view cache generation passed.

Staging still requires an authorized URL, disposable tenant, access method and approved live provider. The local and CI checks use fixtures. `AI_CONVERSATION_ENABLED` remains false by default. No merge, deployment or production financial execution occurred.
