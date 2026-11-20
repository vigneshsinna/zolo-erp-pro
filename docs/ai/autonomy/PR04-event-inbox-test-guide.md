# PR 04 — Durable internal stock-event inbox

## What is implemented
- A source-scoped, company/branch/FY/actor event inbox with idempotency keys.
- Events generated from successful, opted-in scheduled low-stock checks; one event per run with alerts.
- Read-only AI Operations → Event inbox page; reviewers can open the existing human approval queue.
- No external WhatsApp, bank, email or unsigned webhooks are enabled by this PR.
- No automatic financial posting, LLM calls or action approval.

## Feature gate
`AI_INTERNAL_EVENT_INGESTION_ENABLED=false` (default). Turn it on in staging **after migrations**.

## Isolated test commands
```bash
php artisan migrate --env=testing --force
php artisan test tests/Feature/Ai/Infrastructure/AiEventInboxTest.php
php artisan test tests/Feature/Ai/Infrastructure/ScheduledRulesTest.php
php artisan test tests/Unit/DesignTokensTest.php
```

## Manual QA
1. Enable the gate for a staging company with scheduling enabled and low-stock fixtures.
2. Create an opt-in low-stock schedule, run it through the existing worker and open AI Operations → Event inbox.
3. Verify an event appears only when the stock check returns alerts.
4. Re-run the event-ingestion service with the same run and payload; verify exactly one event.
5. Attempt the same event key with altered data; verify that it fails closed.
6. Change company, branch, fiscal year or user. Verify inaccessible event rows stay hidden.
7. Disable the ingestion gate; scheduled stock checks still run but no new event rows are recorded.
8. Check purchases, stock movements, journals, approvals and model-credit records: **zero new writes caused by event display/ingestion**.

## Release gate and limitations
Run MySQL 8.0/8.4, existing scheduled-rule tests and cross-company isolation tests. External connectors and event-to-execution authorization are NOT in scope; treat this as the internal foundation.
