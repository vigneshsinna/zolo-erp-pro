# ZoloERP — PR #37 Copilot Stream Failure Fix & AI Operations Test Guide

**Pull request:** https://github.com/vigneshsinna/zolo-erp-pro/pull/37  
**Target branch:** `feature/ai-unified-input-clarification`  
**Status:** Draft pending CI and local staging verification.

## 1. Observed failure

The Copilot screenshot displays `[AI_STREAM_FAILED] The response stream ended` after `create a purchase bill`. This comes from `AiChatController::stream()`, which previously caught all exceptions without recording a useful diagnostic. The screenshot does not provide the underlying PHP exception, so a precise cause cannot be proven from the screenshot alone.

A separate, confirmed issue in the code: standard chat submissions omitted `client_turn_id`, which active workflow continuation requires. The UI also enabled streamed model responses for every message, including complex ERP action requests.

## 2. What PR #37 changes

- Adds `AI_CHAT_STREAMING_ENABLED` (default `false`) and chooses the existing authenticated `POST /ai/chat` JSON transport unless a deployment has explicitly enabled streaming.
- Preserves the SSE endpoint for validated providers and regression tests.
- Adds a unique client-turn UUID for normal chat submissions.
- Returns specific validation, conflict, authorization, rate-limit and disabled errors from unexpected streaming failures.
- Logs an incident UUID, exception type and PHP file/line on the server without logging supplier details, invoice text or provider secrets.
- Preserves mandatory human approval, tenant isolation, metering and no automatic retry.

## 3. Local setup

After merging the reviewed PR into the **same branch that powers the local `.test` domain**, add or update `.env`:

```dotenv
AI_CHAT_STREAMING_ENABLED=false
```

Then, in the application directory:

```bash
php artisan optimize:clear
php artisan config:cache
```

Hard-refresh the browser (`Ctrl+F5`).

## 4. UI test cases

1. Open `/ai`, select **New chat**, and enter `create a purchase bill`.
2. Expected: a structured clarification or a specific domain/provider error, not the unhelpful `AI_STREAM_FAILED` message.
3. In browser Developer Tools → Network, verify **one** request to `POST /ai/chat` and no request to `POST /ai/chat/stream`.
4. Start or open a purchase-order draft, then ask about creating a purchase bill. Expected: safe workflow feedback or a clear validation message, no silent failure.
5. Verify the browser never automatically resubmits the transaction after an error.
6. Confirm no bill, stock posting, journal entry, or AI action approval is created merely by asking the question. Draft proposals may appear in Needs Review if the workflow succeeds.
7. In isolated staging, enable `AI_CHAT_STREAMING_ENABLED=true` and check SSE response events. Error events should include a safe `incident_id`.
8. If JSON transport also fails, inspect the resulting error code and server log. Do not retry an uncertain posted transaction.

## 5. Automated regression commands

Use disposable MySQL test data, never production:

```bash
php vendor/bin/phpunit -c phpunit.ai.xml --filter 'AiChatApiAndWorkspaceTest|ActiveTaskRoutingTest'
node tests/Browser/Ai/drift-workspace.spec.mjs
php vendor/bin/phpunit tests/Unit/DesignTokensTest.php
```

The browser test depends on the repository's documented fixture export and browser setup.

## 6. Diagnosing remaining failures

When a request fails with an `incident_id`, locate the matching entry in `storage/logs/laravel.log` (or your configured log destination).

Windows PowerShell example:

```powershell
Get-Content .\storage\logs\laravel.log -Tail 150
```

Use the logged exception class and file/line to decide whether the next fix concerns provider streaming, workflow validation, database, permissions or credit metering. Do not share API keys or raw invoice contents.

## 7. What AI Operations currently does

| UI section | Current source and behavior |
|---|---|
| **Needs Review** | Reads pending plus reconciliation-required `ai_action_proposals`, scoped to company/branch/financial year and permissions. |
| **Completed Actions** | Counts completed governed proposals. This does **not** prove autonomous execution. |
| **Recent governed activity** | Shows the latest eight scoped action records and statuses. |
| **Scheduled Work** | Reads the signed-in user's active `ai_scheduled_rules`. The screenshot says scheduling is disabled, so the card shows zero. |
| **Event inbox** | When explicitly enabled, stores deduplicated low-stock events created by opted-in scheduled checks. Does not post bills automatically. |
| **Reversal review requests** | Records a manual review request for a completed execution; no one-click GL/stock reversal. |
| **Autonomy policy preferences** | Future-ready, admin-only preferences; automatic ERP posting remains blocked. |

### Processing examples

**Copilot:** authenticated user request → deterministic PHP/SQL read or approved LLM → validated draft proposal → human approval → authoritative ERP domain service → posting receipt and audit.

**Scheduled stock rule (opt-in):** scheduling engine → stock/reorder checks without a model call → low-stock alert → optional unapproved PO draft → Needs Review. No automatic purchase/bill posting.

To experiment with internal scheduling in a disposable environment, review `AI_SCHEDULING_ENABLED=true` and `AI_INTERNAL_EVENT_INGESTION_ENABLED=true`, configure queue workers, and explicitly create a scheduled rule using the authenticated schedules API. The current Operations page does **not** provide a complete schedule-creation wizard.

## 8. Release decision

Do not merge PR #37 solely based on the screenshots. Require GitHub CI success and a live purchase-bill smoke test on the local installation. If the JSON endpoint still errors, follow its structured error code and local Laravel logs before deciding on a further code change.
