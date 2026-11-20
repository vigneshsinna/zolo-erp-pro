# PR 07 - Copilot stream failure fix and test guide

## Cause and current evidence
The screenshot shows AI_STREAM_FAILED from AiChatController::stream, which previously swallowed all exceptions. The exact production exception cannot be confirmed without local Laravel logs. An identifiable bug is that the standard chat submit omitted client_turn_id, required by active workflow continuations. In addition, model-provider streaming is not proven reliable for all complex agent/tool responses.

## Implemented
- Stable JSON chat route used by default; opt-in AI_CHAT_STREAMING_ENABLED=true for validated deployments.
- Existing SSE endpoint preserved, now logs a safe incident UUID and exception type/location, with distinct structured 422/409/403/429/503 and default 500 errors.
- Unique client_turn_id on normal chat submit to support existing draft workflows.
- No automatic retry, no duplicate purchase proposals, no new execution route or approval bypass.
- Server errors never expose invoice details, raw prompts or provider keys.

## Local configuration
Set AI_CHAT_STREAMING_ENABLED=false in .env, then execute:

    php artisan optimize:clear
    php artisan config:cache

Reload the browser (Ctrl+F5).

## QA scenarios
1. Open /ai, click New chat and type 'create a purchase bill'. Expect a validated draft/clarification or a specific provider/domain error, not generic AI_STREAM_FAILED.
2. Browser Network should contain one POST to /ai/chat, not /ai/chat/stream. No automatic retry.
3. Open an existing PO draft, ask about a purchase bill, and verify any workflow-selection/validation feedback is specific.
4. With streaming explicitly enabled in staging, send a provider-backed read-only question; verify SSE start/delta/complete or an error event with incident_id.
5. Search storage/logs/laravel.log for the incident UUID to locate exception type and code (never share sensitive request data).
6. Confirm finance approvals, credit metering, purchases, stock movements and ledgers are unaffected by transport selection.
7. Run tenant/company/branch/FY isolation tests. Do not post the same purchase bill twice after an uncertain failure.

## Test commands (isolated MySQL only)

    php vendor/bin/phpunit -c phpunit.ai.xml --filter 'AiChatApiAndWorkspaceTest|ActiveTaskRoutingTest'
    node tests/Browser/Ai/drift-workspace.spec.mjs
    php vendor/bin/phpunit tests/Unit/DesignTokensTest.php

The browser fixture test requires the repository browser setup and explicitly enables SSE to test it.

## AI Operations explanation
Needs Review counts pending and reconciliation-required proposals in the authorized company/branch/FY. Completed Actions counts previously completed governed proposals, not autonomous posting. Scheduled Work only shows the user's opt-in schedules when AI_SCHEDULING_ENABLED=true. Low-stock scheduled checks perform deterministic PHP inventory calculations and can prepare unapproved PO proposals. Event ingestion has its own feature flag AI_INTERNAL_EVENT_INGESTION_ENABLED. Reversal requests only create a manual review record. The autonomy policies are preferences; signed human approval is still mandatory.

## Limits
This PR does not turn on autonomous financial posting or external inbox, bank or WhatsApp integrations. If JSON chat fails too, inspect the specific error response, ai_runs and ai_model_invocations; verify vendor/model connectivity separately rather than retrying blindly.
