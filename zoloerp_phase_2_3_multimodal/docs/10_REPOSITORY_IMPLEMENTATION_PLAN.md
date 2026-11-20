# 10 — Repository Implementation Plan and File Change Matrix

## Scope

Implement Phase 2.3 in a new feature branch based on the **latest integrated and tested** action/clarification branch (not automatically `main` when those features are not merged). No changes to live financial posting during perception implementation. Each code slice should have migrations, tests, backward compatibility, documentation and a feature flag; do not implement everything in one unreviewed PR.

## Existing code surfaces verified (reference `feature/ai-unified-input-clarification`, `7b5d0e5`)

- `app/Ai/Gateway/ApprovedModelGateway.php`: approved provider/model list, counters, shared NVIDIA dispatch rate limiter and telemetry.
- `app/Http/Controllers/Api/V1/AiChatController.php`: text chat plus optional `image` string, provider-backed `ErpCopilot` call and evidence summaries.
- `app/Ai/Agents/ErpCopilot.php`: main ERP reasoning/authorized tool architecture.
- `app/Ai/Agents/DeepSeekAgent.php`: helper for data-URI/base64/remote image attachments, not a complete purpose-specific Omni pipeline.
- `app/Providers/AiServiceProvider.php`: Laravel bindings, approved read tools and action registry.
- `resources/views/backend/ai/workspace.blade.php`: chat and rendering UI; currently text input.
- `config/erp_ai.php`: main AI model and quotas.
- `config/ai_actions.php`: existing action flags.
- `routes/api.php`: existing `ai/chat`, proposals and isolated installation-admin bootstrap routes.
- `app/Ai/Services/ActionProposalService.php`, `app/Ai/Services/RecordResolutionService.php`, `app/Ai/Tools/PreparePurchaseOrder.php`: reuse/extend for handoff, not replaced.

**Important:** The actual feature branch may have evolved since this snapshot. Re-inspect the tree and Laravel route registry before editing. Paths below are **proposals**, not statements that new files already exist.

## Proposed code files (each explicitly tagged)

| Operation | File | Responsibility |
|---|---|---|
| [Create] | `config/ai_multimodal.php` | Independent model/feature/size/retention/rate limits |
| [Modify] | `config/erp_ai.php` | Document routing relationship, no global model swap |
| [Modify] | `app/Ai/Gateway/ApprovedModelGateway.php` | Approved purpose-scoped model, accounting and shared NVIDIA RPM |
| [Create] | `app/Ai/Multimodal/Contracts/PerceptionClientInterface.php` | Image extraction and audio transcription contract |
| [Create] | `app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php` | Fixed endpoint, approved model payloads, bounded network calls |
| [Create] | `app/Ai/Multimodal/Services/MediaIngestionService.php` | Create scoped private media asset |
| [Create] | `app/Ai/Multimodal/Services/MediaValidationService.php` | MIME, size, duration, content and quota policies |
| [Create] | `app/Ai/Multimodal/Services/MediaPreprocessingService.php` | PDF rasterization / image normalization / audio transcoding |
| [Create] | `app/Ai/Multimodal/Services/MediaExtractionService.php` | Orchestrate perception, schema validation and state |
| [Create] | `app/Ai/Multimodal/Services/NormalizedInputBuilder.php` | Trusted typed task handoff and provenance |
| [Create] | `app/Ai/Multimodal/Services/ExtractionRevisionService.php` | User correction, compare-and-swap and confirmation |
| [Create] | `app/Ai/Multimodal/Schemas/InvoiceExtractionV1.php` | Invoice OCR strict schema; array bounds and decimals |
| [Create] | `app/Ai/Multimodal/Schemas/TranscriptExtractionV1.php` | Transcript strict schema, language/corrections |
| [Create] | `app/Ai/Multimodal/DTOs/VerifiedMediaAsset.php` | Server-verified media path and scope |
| [Create] | `app/Ai/Multimodal/DTOs/PerceptionResult.php` | Raw provider result metadata, not business facts |
| [Create] | `app/Ai/Multimodal/DTOs/NormalizedInputV1.php` | Typed normalized input contract |
| [Create] | `app/Models/AiMediaAsset.php` | Tenant-scoped metadata model (no public accessor) |
| [Create] | `app/Models/AiMediaExtraction.php` | Scoped extraction revisions |
| [Create] | `database/migrations/2026_11_11_000002_create_ai_media_assets.php` | Proposed additive private asset schema; choose unused filename after inspecting migrations |
| [Create] | `database/migrations/2026_11_11_000003_create_ai_media_extractions.php` | Proposed extraction revision schema; ensure schema compatibility |
| [Create] | `app/Jobs/Ai/ExtractMediaJob.php` | Safe, bounded queued extraction, no ERP write tools |
| [Create] | `app/Jobs/Ai/CleanupExpiredMediaJob.php` | Remove private uploads/derivatives under policy |
| [Create] | `app/Http/Controllers/Api/V1/AiMediaController.php` | Upload/status/extract/confirm/correct/delete APIs |
| [Create] | `app/Http/Requests/Ai/UploadAiMediaRequest.php` | Session/Sanctum input validation |
| [Create] | `app/Policies/AiMediaAssetPolicy.php` | Tenant+actor media authorization |
| [Modify] | `routes/api.php` | Register company-scoped AI media routes, rate limits, auth |
| [Modify] | `app/Providers/AiServiceProvider.php` | Bind perception client and test-fake interface; keep no write tools |
| [Modify] | `app/Http/Controllers/Api/V1/AiChatController.php` | Accept normalized media refs/task handoff; preserve old text chat |
| [Modify] | `app/Ai/Agents/ErpCopilot.php` | Receive safe normalized task details; avoid raw media in tool parameters |
| [Modify] | `resources/views/backend/ai/workspace.blade.php` | Add attach, record, preview, correction and upload-status components |
| [Modify] | `public/css/pages/ai-workspace.css` | Scoped styles and responsive layout; verify actual CSS path |
| [Create] | `resources/js/ai/media-input.js` | MediaRecorder and upload controller if existing build supports JS entry; otherwise use current Blade/JS convention |
| [Create] | `tests/Feature/Ai/Multimodal/MediaUploadSecurityTest.php` | ACL, content validation, retention |
| [Create] | `tests/Feature/Ai/Multimodal/InvoiceExtractionTest.php` | Mock Omni; bills, totals, ambiguous fields |
| [Create] | `tests/Feature/Ai/Multimodal/AudioTranscriptionTest.php` | Audio uploads, editing, language fallback |
| [Create] | `tests/Feature/Ai/Multimodal/NormalizedInputWorkflowTest.php` | Mixed sources, corrections, task handoff, no writes |
| [Create] | `tests/Unit/Ai/Multimodal/OmniRequestPayloadTest.php` | Correct image_url/audio_url, no arbitrary provider URLs |
| [Create] | `tests/Unit/Ai/Multimodal/ExtractionSchemaTest.php` | Strict parse, nulls, decimals and bounds |
| [Create] | `tests/Browser/Ai/MultimodalWorkspaceTest.php` | Frontend capture, keyboard and review flow (adapt to existing E2E framework) |

## 2.3A — Foundation and safe gateway

1. Freeze branch, inspect actual migration names, route groups, policies and resolved model list.
2. Add `config/ai_multimodal.php`; default all media calls off.
3. Implement `VerifiedMediaAsset` contract and media validation/disk service (tests first).
4. Add additive tenant-scoped media migrations using indexes on `(company_id, actor_id, state)` and `(company_id, id)`.
5. Implement Omni request adapter without tool calling; use fake provider in tests.
6. Add shared NVIDIA dispatch accounting and per-model telemetry without breaking existing Lightning token budgets.
7. Verify disabled gate: uploading/extracting returns policy-controlled errors and makes no provider dispatch.

## 2.3B — Invoice image and PDF

1. Build JPG/PNG ingestion, preprocessing and bounded PDF page renderer.
2. Define strict `InvoiceExtractionV1` parser and source provenance.
3. Add async worker/status endpoints, private media preview and field review card.
4. Integrate ERP-independent arithmetic consistency check and warnings; do not create bills.
5. Pilot against synthetic invoices / consented fixtures, benchmark exact field match and line detection.

## 2.3C — Voice

1. Add recording controls with browser permission and fallback.
2. Implement WebM→WAV transcoding in isolated worker and native WAV/MP3/FLAC handling.
3. Add Omni transcription schema and editable transcript card.
4. Enforce English-support caveat and disabled-by-default multilingual fallback.
5. Test number/negation accuracy and transcripts with real ERP-like terms.

## 2.3D — Workflow bridge

1. Only after Phase 2.1 is actually implemented, add `NormalizedInputBuilder → WorkflowTaskStateService` interface.
2. Preserve media/page/segment provenance through task revisions; use action-specific requirement providers.
3. Connect multiple missing fields to one clarification card and dependent masters to separate approvals.
4. Add document-type-to-capability mapping with strict registry check. If purchase-bill capability does not exist, stop at extraction and explain limitation.
5. Add integration tests for both PO and setup dependent master paths, with live DB writes disabled by default.

## 2.3E — Release and operations

1. Measure extraction quality per document type, count cost and latency.
2. Verify data-protection approval for the chosen NVIDIA hosted endpoint.
3. Run entire AI/commercial/operations suites on a disposable MySQL database and browser tests in the local Laravel 13/PHP 8.5 app.
4. Enable in one test company only; keep primary ERP actions independently gated.
5. Review logs for PII leaks, incident response and automatic deletion.
6. Conduct security sign-off, field-accuracy sign-off and gradual rollout.

## Rejected designs

- Do **not** replace `AI_MODEL` globally with Omni. It risks breaking current conversational tools, quotas and tool-call behavior.
- Do **not** send raw file bytes inside every chat request. Separate upload/status/extraction is easier to secure and retry.
- Do **not** run business commands inside the perception worker.
- Do **not** treat a model's financial math as authoritative.
- Do **not** build a new HMAC approval engine; extend existing Action Foundation.
- Do **not** add self-hosted GPU deployment as a hidden prerequisite; use the approved hosted API initially, with self-hosting only after capacity/privacy assessment.

## Delivery artifacts required per PR

For each slice, include source, migrations, unit/feature/browser tests, examples, updated environment documentation, security diff, backward compatibility notes, rollout flag and measured test results. Any unverified external API assumption must be marked and resolved by a documented smoke test before production enablement.
