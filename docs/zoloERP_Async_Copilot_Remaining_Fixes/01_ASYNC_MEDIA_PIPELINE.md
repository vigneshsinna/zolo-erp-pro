# 01 — R13: Asynchronous media preparation with durable progress

## Objective

Replace synchronous Copilot upload-to-preview execution with a bounded upload request and a recoverable job. Do not merely call `dispatch()` from the old method while retaining `QUEUE_CONNECTION=sync`. Do not enqueue a job that cannot recreate the original task/thread/action/companion instruction. Continue to support image, PDF, first-turn audio, voice reply, supplier-photo review, existing extraction and the reviewed optical sales/purchase routes.

## 1. Source integration points

- `routes/web.php` and `routes/api.php`: existing `media` groups, authentication/CSRF and scope middleware. Add task routes under the same protected route groups, do not expose public IDs or a second auth system.
- `AiMediaController::prepare`, `store`, `extract`, `prepareExisting`, `handoff`: share an orchestration service. Inspect route methods, request validators and legacy API clients. Feature-on long-running methods schedule or return existing task; feature-off compatibility remains explicit and gated. Do not leave an alternative unbounded sync path for feature-on requests.
- `MediaIngestionService`, `MediaPreprocessingService`, `MediaValidationService`: separate bounded admission/private original storage from expensive decoding, PDF rasterization, audio transcoding and derivative generation. Preserve existing quota, MIME/size checks, original hash, ACL, encryption/retention and cleanup. Existing `ingest()` currently preprocesses in request. Reuse its components, avoid duplicated stores. No public original file path.
- `ExtractMediaJob`: reuse or refactor into a coordinator/job chain, not a second engine. Worker reconstructs existing `AiPrincipalFactory`, validates actor membership, company/branch/FY, feature/licensing, thread/task revision and asset policy before each stage. Never serialize bearer tokens or model secrets.
- `MediaExtractionService`, `InvoiceExtractionV1`, `NormalizedInputBuilder`, `TransactionIntentResolver`, `CompanyDocumentRoleClassifier`, `NormalizedInputWorkflowBridge`, existing `ActionPreparationService`: reuse exactly. Keep explicit extraction/voice/ERP proposal approval distinctions and all current conflict gates.

## 2. Durable record and state contract

First inspect existing async-job/task infrastructure, migrations, model invocation and media tables. Extend existing generic job records if suitable; otherwise use additive table e.g. `ai_media_processing_tasks` and optional append-only stage events. Do not overload a governed action proposal status with upload progress. Suggested fields: uuid; company_id, branch_id, financial_year_id, actor_id; thread_id, workflow_task_id and expected revision; media_id; extraction_id/revision; client_request_id; normalized request hash; requested action, companion instruction (private, retention-scoped), resolution mode; state, stage, monotonic state_version; per-stage state/timestamps/attempts; lease_owner, lease_token, lease_expires_at, heartbeat_at; attempt_count, next_retry_at, absolute deadline; safe error code, retryable, result ref; created_at/updated_at. Enforce scope-indexed unique client request key and version CAS. Store result reference to existing extraction/workflow/proposal rather than duplicating sensitive data. Keep private sensitive payloads out of queue logs and status summary. Data retention and deletion must coordinate with existing media policy and financial audit.

Task states: `queued`, `processing`, `retry_scheduled`, `needs_input`, `needs_confirmation`, `review_ready`, `failed`, `cancel_requested`, `cancelled`, `expired`. Stage states: `pending`, `running`, `completed`, `failed`, `retry_wait`, `skipped`. `review_ready` means verified review or proposal available, NOT approved/posted. `needs_input` is a normal successful pause. `needs_confirmation` means source confirmation or intent/voice review is required; no claim of a sealed proposal until one exists. Cancellation cannot reverse an ERP transaction, nor guarantee cancellation of a provider call already accepted. Block downstream preparation after cancellation; existing proposal cancellation uses existing governance.

| Stage key | Mark complete only when | Typical waiting/failure |
|---|---|---|
| `upload` | Private original stored, hash and metadata persisted, admission passed | upload failure, oversized, invalid MIME |
| `preprocess` | Sanitized derivative(s) durable, dimensions/pages verified | decoding/rasterization, limits |
| `extract` | Required pages/transcript extracted with schema validation, or explicitly reviewed partial state | provider 429/timeout, unreadable, incomplete pages |
| `resolve` | Intent and applicable party/product candidates evaluated; needed clarification identified | ambiguous intent, foreign document, missing lens |
| `validate` | Actual ERP validation for current chosen route completed | missing input, stock/tax/price mismatch |
| `prepare` | Existing governed preparation result persisted and linked; review ready | stale task, changed scope, missing fields |

For a blocked `resolve` or `validate`, show the stage as needing input and following stages pending, do not mark as passed. Use not-applicable/skipped with reason for stock validation on a non-stock service, or irrelevant stages for voice transcript review; do not fake completion. Stage labels chosen from verified intent/profile; before resolution label document extraction generically. Partial extraction never automatically advances to posting review. Authoritative timestamps and actual events only; no fake progress percentages. Monotonic version ensures stale poll responses cannot overwrite newer result. Store event timestamps in UTC; display local time.

## 3. Proposed API contract

Retain current route prefixes as configured in workspace; examples relative to `/media`. Add explicit async feature flag and capability advertisement. Use existing `/prepare` endpoint with async mode negotiated, or versioned endpoint `/processing-tasks`; choose one canonical async path and document mapping. The actual `submitStagedAttachment()` must use it. Upload multipart once: `file`, `companion_text`, `thread_id`, optional `workflow_task_id`, expected revision, optional explicit action/resolution mode, stable `client_request_id` or existing accepted idempotency header. The frontend creates UUID before first request and keeps it for the same submission. Validate exact authority server-side, not request-provided scope IDs.

On success return HTTP 202 after durable media/task state commit and durable dispatch intent, without waiting on model. Replays may return 200 with same task (or 202 for unfinished). Example proposed response:

```json
{
  "task_id":"task-uuid",
  "media_id":"media-uuid",
  "thread_id":"thread-uuid",
  "state":"queued",
  "state_version":1,
  "stage":"preprocess",
  "status_url":"/ai/media/processing-tasks/task-uuid",
  "stages":[{"key":"upload","state":"completed"},{"key":"preprocess","state":"pending"},{"key":"extract","state":"pending"},{"key":"resolve","state":"pending"},{"key":"validate","state":"pending"},{"key":"prepare","state":"pending"}],
  "retryable":false,
  "poll_after_ms":2000
}
```

`GET /processing-tasks/{id}` returns current authorized snapshot; optional ETag/state_version to reduce repeated response. `GET /processing-tasks?thread_id=...` restores pending/finished cards for authorized conversation (paginated, bounded). Provide lookup by stable client request ID to recover lost upload response; never list all company tasks for a normal user. `POST /processing-tasks/{id}/retry` requires expected version and stable operation key, same media; returns current/new attempt status. `POST /processing-tasks/{id}/cancel` versioned, idempotent. User manual entry/intent correction uses existing workflow task/review APIs, not unrestricted task patch. If preparation requires continuation, resume same scoped workflow with current revision; avoid new provider call when confirmed extraction is reusable. Force re-extract is explicit user operation, new extraction revision and billing policy disclosure; do not let ordinary retry pass `force=true`.

Terminal review payload references existing renderer contract: `status`, `action_key`, `decision`, `task_id` (distinguish business workflow task ID from `processing_task_id`), `proposal`, `task`, `clarification`, `normalized_input`, `media`, `extraction`, `message`. Prefer scoped result endpoint or existing extraction/task reference and reconstruct safe DTO. No raw provider data or large base64 in status polling. Maintain existing `voice_intent_review_ready`, `voice_reply_ready`, `supplier_photo_review_ready` routing; don't call invoice renderer for audio. Error envelope: safe code, friendly message, retryable, failed stage, correlation ID, current revision, next retry if applicable. No raw stack/SQL/keys. No 500 for expected provider timeout/429; typed task status or controlled HTTP error for admission failures.

## 4. Worker orchestration

1. Admission: authorize; validate declared and detected type, byte limit, upload quota, thread and expected revision. Persist private original and task atomically from user's perspective; compensate storage failure; dispatch after commit. Handle crash between commit and dispatch using existing durable outbox or reconciliation sweeper. Never enqueue before record commit.
2. Claim: dedicated worker obtains atomic state/version claim and lease. Duplicate delivery no-ops; expired lease reconciled, fenced old worker cannot write new results. Do not hold MySQL lock across external I/O. Check cancellation, current scope, license, feature and deadline.
3. Preprocess: use existing safe normalizer and derivative storage; bounded resources. Save manifest/hash. Only advance after durable result. Page jobs bounded and individually recoverable, see file 02.
4. Extract: reuse existing validated extraction if matching media hash, relevant prompt/schema/model/profile and instruction semantics. Use gateway with durable attempt reference, absolute deadline and retry schedule. Store output via existing extraction revision service. No raw OCR accepted as authority.
5. Resolve/validate: current transaction resolver and optical identity services; preserve signed SPH/CYL, RE/LE as attributes where optical, unit, source provenance, receipt/dispatch status. Generic bill ambiguous; incoming purchase document cannot silently become outgoing customer invoice. Resale-reference flow must ask customer, selling price, warehouse, preserve source vs ERP totals. No duplicate stock/ledger. Use deterministic checks without LLM calls where possible. Partial/missing fields return `needs_input` and persist safe existing task.
6. Prepare: invoke existing bridge with approved flags and scope, no posting. Use durable unique result association so retry after proposal saved but before status update finds existing proposal/task instead of creating another. Recheck current draft revision/approval conditions. Never bypass `canStageWriteAction` and explicit voice/source review gates. Mark review ready only after actual persisted result. UI renders existing correct purchase or sales review.
7. On failure: record safe code, stage, retry plan. On forced worker termination, sweeper detects stale lease and reconciles provider attempt/credits and existing durable results before allowing retry. Do not pretend an uncertain provider response was never sent. Bound abandoned task age and cleanup. Ensure cancellation/expiry before preparation. Scope changes prevent stale UI, not permission grant to new scope.

## 5. Acceptance

- With an intentionally blocked fake provider, upload returns 202 before fake released; worker independently progresses and status shows extraction running. Prove no sync driver fallback; fake queue alone is not integration evidence.
- Upload same request key twice/concurrently: one admitted media/task, one applicable upload quota consumption. Same key with changed bytes/instruction => conflict. Lost response recovered by request ID.
- Refresh/thread change/role revocation: only authorized task returned, no wrong-scope card, worker reauthorizes. Expired/removed media safe failure.
- Cancel before/after provider dispatch: no unapproved domain posting; no hidden draft continuation, provider outcome/credits reconciled.
- Existing extraction retry does not re-upload or reinvoke model. Worker crash after extraction or proposal persistence recovers exact result. No duplicate proposals or stock/journal changes.
- R01–R05 regression: sales bill route correct, flag disabled safe, source mismatch/resale clarification, optical and non-optical schema, source confirmation, audio reply preserved. HTTP 202 is not a claim that extraction completed.
