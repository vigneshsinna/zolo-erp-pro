# 13 — Sources, Assumptions, and Open Architecture Decisions

**Research date:** 11 November 2026. Treat external hosted model availability and commercial terms as changeable. The documents below record verified general API formats, not proof that a particular NVIDIA account's API key can call the model.

## Official source references

1. NVIDIA Nemotron 3 Nano Omni model card — modalities, input/output types, English-only language statement and example payloads:  
   https://build.nvidia.com/nvidia/nemotron-3-nano-omni-30b-a3b-reasoning/modelcard
2. NVIDIA NIM: Query Nemotron 3 Nano Omni API — OpenAI-compatible chat-completion request; `image_url` and `audio_url`; accepted formats; data URLs; thinking mode flags:  
   https://docs.nvidia.com/nim/vision-language-models/1.7.0/examples/nemotron-3-nano-omni-30b-a3b-reasoning/api.html
3. NVIDIA NeMo AutoModel coverage for Nemotron 3 Nano Omni — architecture and receipt-parsing recipes:  
   https://docs.nvidia.com/nemo/automodel/nightly/model-coverage/omni/nvidia/nemotron-omni.html
4. NVIDIA developer article — multimodal image/audio reasoning overview:  
   https://developer.nvidia.com/blog/nvidia-nemotron-3-nano-omni-powers-multimodal-agent-reasoning-in-a-single-efficient-open-model/
5. zoloERP source repository (access via connected GitHub; reference branch `feature/ai-unified-input-clarification` at `7b5d0e5`):  
   https://github.com/vigneshsinna/zolo-erp-pro/tree/feature/ai-unified-input-clarification
6. Existing zoloERP Phase 2.1 specification:  
   `docs/zoloerp_ai_operating_layer_complete_docs_v3/ZOLOERP_PHASE_2_1_UNIFIED_INPUT_COLLECTION_AND_CLARIFICATION_ENGINE.md` on the reference branch.

## Facts confirmed during source inspection

- Current `ApprovedModelGateway` has a single primary model resolution path plus an allowlist and provider RPM accounting.
- The current main `AiChatController` accepts typed chat and optional image input and forwards the image attachment to `ErpCopilot` using a helper; this is not the dedicated Omni perception flow.
- `DeepSeekAgent::createImageAttachment()` converts selected image strings to Laravel AI image objects; it is not speech-to-text or OCR workflow persistence.
- The existing workspace has a text composer and no verified fully integrated voice-recording workflow at the inspected commit.
- Omni's documented NIM API accepts `image_url` and `audio_url` messages and can disable thinking with `chat_template_kwargs`.
- The Omni model card lists English-only language support.
- The proposal, approval and guarded ERP command structures must remain authoritative for any action.

## Design assumptions requiring confirmation in implementation

1. **Hosted model entitlement:** The NVIDIA-hosted `integrate.api.nvidia.com` deployment accepts the exact model ID and both MIME content parts for the user's account. **Not proven until a live authenticated smoke test.**
2. **Structured JSON enforcement:** If the particular deployed API supports a strict JSON schema/response format, use it; still validate locally. Otherwise accept text and use a strict parser with safe fallback.
3. **Image size limits:** No universal hosted-provider file limit is assumed; app limits are preliminary proposals and may need tightening after actual payload/error tests.
4. **PDF rasterization:** Confirm approved server tool, resource guardrails and deployment environment (Windows/Herd development vs production Linux) before selecting renderer.
5. **Audio transcoding:** Confirm approved installed binary/library and sandbox before enabling `audio/webm` uploads in the UI.
6. **Data-processing agreement:** Validate with business/legal stakeholders whether company invoices and customer data may be sent to the selected NVIDIA endpoint and where data is processed/retained.
7. **Tamil-language fallback:** Choose provider only after actual consented-language quality evaluation. Avoid claiming English-only Omni supports Tamil.
8. **Media retention:** Proposal default 24 hours is not a legal retention opinion; company/document policies may require different periods.
9. **Phase 2.1 actual implementation:** It was a specification at the inspected branch; integration path must adapt if the user has since completed it in a newer branch.
10. **Phase 2.2 actual implementation:** Dependent master task resumption is a design target, not proof of a present runtime feature.

## Final scope decisions proposed for sign-off

| Decision | Recommendation | Owner to confirm |
|---|---|---|
| Model split | Lightning (reasoning) + Omni (perception) | Product + AI engineering |
| First modality | Image invoices / receipts, text remains unchanged | Product |
| Second modality | Short English voice notes, editable transcript | Product + UX |
| Document formats | JPG/PNG, PDF page conversion; no video in v1 | Engineering + Security |
| Language scope | English pilot; Tamil fallback separately reviewed | Product + Localization |
| Hosted vs self-hosted | Hosted NVIDIA API if privacy approval and entitlement pass | Infrastructure + Security |
| Data retention | Configurable short-lived private media with separate official ERP attachments | Privacy + Compliance |
| Missing masters | Dependent setup proposal and separate approval | ERP domain owners |
| Business posting | Never by Omni; existing Action Foundation only | Product + Finance |
| 2.3 release gate | Test data, CI, security, financial integrity and provider smoke test | QA + Security + Finance |

## Explicitly deferred

Video understanding, real-time streaming speech interaction, voice output/TTS, automatic handwritten invoice reconciliation, multilingual transcription without proven fallback, background autonomous purchasing, direct account/journal modifications, remote URL ingestion, unattended supplier creation and any new regulatory assumptions beyond existing ERP domain validation.

## Implementation handoff instruction

Do not treat illustrative PHP/JSON as already implemented. Start with an exact current-branch code audit, write isolated mocks and tests first, verify provider compatibility, and implement slices 2.3A–2.3E in reviewed PRs. Preserve the existing read-only Copilot, existing ERP domain permissions and separate Mode A bootstrap boundary. Every new AI-driven write must use the approved proposal/approval/HMAC/live validation path.
