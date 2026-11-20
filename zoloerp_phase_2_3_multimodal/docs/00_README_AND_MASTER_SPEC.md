# zoloERP AI Operating Layer — Phase 2.3
## Multimodal AI Perception & Input Normalization

**Status:** Implementation specification, not implemented application code  
**Date:** 11 November 2026  
**Target:** Laravel 13 / PHP 8.5, existing `vigneshsinna/zolo-erp-pro` feature architecture  
**Reference branch inspected:** `feature/ai-unified-input-clarification` (`7b5d0e5f8baaae392d222e0f2fe263e914f0692b`)  
**Scope:** Text, photographed bills / business documents, speech-to-text, normalization, trusted ERP task handoff, human review. **No AI-autonomous writes.**

## Decision summary

Keep the existing ERP reasoning model (`nvidia/nemotron-3.5-lightning-30b-a3b`) for task orchestration and introduce a separate, explicitly allowlisted perception model (`nvidia/nemotron-3-nano-omni-30b-a3b-reasoning`) for image/document extraction and audio transcription. The user can enter text, attach an image/PDF, record audio, or combine modalities. Each path produces a common versioned **NormalizedInput**. Never forward the provider's unconstrained response directly to ERP actions.

```
Browser (text | image | PDF | audio | mixed)
  -> authenticated upload endpoint + media validation
  -> private media store / preprocess (page rasterization or audio transcode)
  -> explicitly routed NVIDIA Omni perception call (images or audio only)
  -> schema validation + source/provenance + confidence signals
  -> user correction/confirmation where needed
  -> trusted NormalizedInput -> Phase 2.1 task / requirements / record resolution
  -> Phase 2.2 dependent-master proposals (only when needed)
  -> domain-prepared business proposal -> human approval -> ERP domain executor
```

Text-only requests continue directly to the existing Copilot (no unnecessary Omni request). The Omni adapter **must not** have tools capable of writing ERP data. All new modules are feature-gated and disabled by default.

## Documents in this pack

| File | Contents |
|---|---|
| `01_SYSTEM_ARCHITECTURE.md` | End-to-end workflow, boundaries, invariants, ownership, dependency staging |
| `02_MODEL_ROUTING_NVIDIA_API.md` | Model selection, NVIDIA API payloads, compatibility, errors, RPM / token accounting |
| `03_MEDIA_INGESTION_PREPROCESSING.md` | Upload, private storage, validation, PDF pages, audio conversion, cleanup |
| `04_IMAGE_AND_DOCUMENT_EXTRACTION.md` | Invoices/receipts, extraction rules, evidence, GST, duplicate prevention |
| `05_AUDIO_TRANSCRIPTION_AND_LANGUAGE.md` | Voice recording, transcription, Tamil/mixed-language fallback, corrections |
| `06_NORMALIZED_INPUT_DATA_CONTRACTS.md` | DTOs, JSON schemas, field evidence, confidence, versioning, trust levels |
| `07_ERP_WORKFLOW_AND_MASTER_INTEGRATION.md` | Phase 2.1/2.2, missing slots, setup/PO/bill/sales, separate approvals |
| `08_BLADE_UI_AND_API_DESIGN.md` | Workspace UX, request/response contracts, revision and errors |
| `09_SECURITY_PRIVACY_GOVERNANCE.md` | Threat model, isolation, statutory accounting, audit and retention |
| `10_REPOSITORY_IMPLEMENTATION_PLAN.md` | Exact proposed `[Create]`/`[Modify]` paths, sequence, rollback |
| `11_TEST_MATRIX_AND_RELEASE_GATES.md` | Tests, benchmark fixtures, acceptance tests, rollout gates |
| `12_SCENARIOS_AND_EXAMPLES.md` | End-user scenarios and sample structured outputs |
| `13_SOURCES_AND_OPEN_DECISIONS.md` | Verified references, assumptions, unresolved decisions |

## Existing code: verified vs proposed

**Verified existing:** `ApprovedModelGateway` with an approved model allowlist, `AiChatController::chat()` with optional `image` string, `DeepSeekAgent::createImageAttachment()`, `ErpCopilot`, `ActionProposalService`, `ActionCapabilityRegistry`, `PreparePurchaseOrder`, existing purchase-order domain adapter, and a Blade workspace with a text box. The app still requires coherent media upload, audio capture, purpose-specific Omni client, structured validation and task integration.

**Not confirmed as implemented at the inspected commit:** Phase 2.3 files; dedicated audio endpoint; PDF ingestion; `ai_media_assets`; voice UI; the full Phase 2.1 `WorkflowTaskStateService`; Phase 2.2 automatic dependent-master workflow resumption. Do not assume these exist until checked on the implementation branch.

## Non-negotiable invariants

1. The perception model can only extract/describe/transcribe. It has **no write tools**, no domain-command interface and no privileged record-reading access.
2. The model's returned text is **untrusted**. Do not execute instructions embedded in invoices, labels, screenshots or audio.
3. Tenant context is resolved **server-side** from authenticated user, company, branch, financial year and specific access policy.
4. Model data never creates a supplier, warehouse, product, invoice, purchase order or journal without separate server validation and authenticated approval.
5. Approved action payloads remain immutable and signed; execution revalidates live ERP state under authoritative domain transaction / lock rules.
6. User-confirmed corrections override earlier uncertain extractions; user-supplied information is not silently replaced.
7. Files remain in **private** storage, and file processing has explicit limits, retention and data minimization.
8. Model names, gateway URLs, retry policies and prompts come from code/config allowlists, not from end-user messages or uploaded content.
9. Bootstrap company creation remains in **isolated installation-admin Mode A**; generic media/task routes cannot bypass Tenancy Law 1.
10. Release is staged, reversible and disabled-by-default; use a disposable test company for ERP writes.

## Suggested roll-out

**2.3A** transport + media store + isolated Omni gateway + mocked tests; **2.3B** invoice image extraction and user review; **2.3C** voice capture and transcription; **2.3D** task/clarification integration with dependent masters; **2.3E** confidence benchmark, quota/security audit, gradual enablement.

## Dependencies and sequencing

Phase 2.3 is not a prerequisite for text-only Phase 2.1. It should be implemented independently behind a perception API and integrated when Phase 2.1's durable task contract is ready. Phase 2.2 master creation is an **optional dependent task** with its own approval. Avoid prematurely wiring OCR output directly into action proposal creation.

## Source-of-truth principle

The ERP calculates GST, line totals, unit conversions, stock, tax profile, fiscal-period status and final payable totals. Even if an invoice photo visibly shows those values, treat them as *claims from a document* until the ERP has calculated, compared and validated them. Model-extracted arithmetic is never authoritative.
