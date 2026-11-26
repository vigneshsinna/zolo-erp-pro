# PR 04 — Internal event inbox and deterministic stock alerts

**GitHub:** https://github.com/vigneshsinna/zolo-erp-pro/pull/34  
**Base:** PR 03 feature branch  
**Gate:** `AI_INTERNAL_EVENT_INGESTION_ENABLED=false` by default.

## Changes to verify
- Alert-producing runs from the existing, opted-in low-stock scheduler add a durable internal event to `ai_event_inbox`.
- Event idempotency: one event for each completed scheduled run; replays with the same payload do not duplicate it.
- Company, branch, FY and actor are verified against the **scheduled rule**, not untrusted event input.
- Event inbox is read only and can link to a pending human-reviewed proposal.
- No external email, WhatsApp, bank or signed webhook connector is provided by this PR.

## Automated tests (disposable MySQL)
```bash
php artisan migrate --env=testing --force
php artisan test tests/Feature/Ai/Infrastructure/AiEventInboxTest.php
php artisan test tests/Feature/Ai/Infrastructure/ScheduledRulesTest.php
php vendor/bin/phpunit tests/Unit/DesignTokensTest.php
```

## Manual staging checklist
1. Enable the ingestion flag after migrations, with existing scheduling enabled for a staging company.
2. Configure a low-stock schedule with explicit opt-in and valid warehouse, product and threshold.
3. Trigger its worker. An alert-producing run should produce one inbox event.
4. Replay the same run id and payload; verify only one event exists.
5. Replay with the same key but different product IDs; verify a collision error, not silent replacement.
6. Switch company, branch, FY and actor to test event isolation.
7. Disable ingestion while keeping scheduling active; stock checks continue but produce no new inbox events.
8. Verify no purchases, stock movements, journals, payment records or model credits were created by the event-ingestion action itself.

**Detailed in-repository guide:** https://github.com/vigneshsinna/zolo-erp-pro/blob/codex/ai-events-inbox/docs/ai/autonomy/PR04-event-inbox-test-guide.md
