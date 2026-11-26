# Goldfish Annotated Review Report

**Status:** resolved
**Target:** `app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php`, `app/Ai/Multimodal/Services/MediaValidationService.php`, `app/Http/Controllers/Api/V1/AiChatController.php`, `app/Ai/Multimodal/Services/NormalizedInputWorkflowBridge.php`, `app/Ai/Gateway/ApprovedModelGateway.php`, `app/Ai/Multimodal/Services/MediaIngestionService.php` (`full file`)

## Prioritized Findings & AI Suggestions

### [Issue 1] [NVIDIA Omni Audio Payload Contract Mismatch] - Severity: Blocker
*   **Affected Scope:** [app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php#L85-L95](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php#L85-L95) (`transcribeWavAudio`), [tests/Unit/Ai/Multimodal/OmniRequestPayloadTest.php#L123-L132](file:///v:/pers/Freelance/zolo-erp-pro/tests/Unit/Ai/Multimodal/OmniRequestPayloadTest.php#L123-L132), [tests/Feature/Ai/Multimodal/AudioTranscriptionTest.php#L118-L120](file:///v:/pers/Freelance/zolo-erp-pro/tests/Feature/Ai/Multimodal/AudioTranscriptionTest.php#L118-L120)
*   **Discrepancy Description:** In `NvidiaOmniPerceptionClient.php`, the code sends audio using `{ "type": "input_audio", "input_audio": { "data": "...", "format": "wav" } }`. NVIDIA's documented request format for Nemotron 3 Nano Omni instead uses `audio_url` and a base64 data URL: `{ "type": "audio_url", "audio_url": { "url": "data:audio/wav;base64,..." } }`. Current unit and feature tests mirror the incorrect `input_audio` structure, allowing tests to pass against the wrong provider contract.
*   **Rationale:** Release blocker for voice input. The live NVIDIA endpoint rejects `input_audio` with 400 Bad Request.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:** The critique is accurate. NVIDIA's Nemotron Omni multimodal API strictly adheres to `{ "type": "audio_url", "audio_url": { "url": "data:audio/wav;base64," . $base64 } }`, matching the symmetric `image_url` pattern.
*   ***Proposed Edit:***
    *   Location: `app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php#L85-L95`
    *   Change:
        ```php
        $content = [
            ['type' => 'text', 'text' => $userPrompt],
            [
                'type' => 'audio_url',
                'audio_url' => [
                    'url' => 'data:audio/wav;base64,' . $base64Audio,
                ],
            ],
        ];
        ```
    *   Update assertions in `tests/Unit/Ai/Multimodal/OmniRequestPayloadTest.php` and `tests/Feature/Ai/Multimodal/AudioTranscriptionTest.php` to assert `audio_url` and `data:audio/wav;base64,...`.

---

### [Issue 2] [PDF Active Content Security Checks Incomplete] - Severity: Major
*   **Affected Scope:** [app/Ai/Multimodal/Services/MediaValidationService.php#L307-L310](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Multimodal/Services/MediaValidationService.php#L307-L310) (`inspectPdfSecurity`), [app/Ai/Multimodal/Services/MediaPreprocessingService.php#L153-L161](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Multimodal/Services/MediaPreprocessingService.php#L153-L161)
*   **Discrepancy Description:** The validator detects PDF JavaScript and launch action markers (`/\/(?:JavaScript|JS|Launch|OpenAction)\b/`), but takes no action (the block body is empty). The preprocessing service then retains the original uploaded PDF containing active scripts as the canonical stored file in private storage.
*   **Rationale:** Malicious or polyglot PDFs with embedded JavaScript or automatic execution triggers must not be admitted into private ERP storage or allowed into down-stream processing.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:** Valid finding. Detecting active executable content without throwing an exception violates fail-closed security. The validation service must immediately reject PDFs containing JavaScript or launch actions before preprocessing or disk storage.
*   ***Proposed Edit:***
    *   Location: `app/Ai/Multimodal/Services/MediaValidationService.php#L307-L310`
    *   Change:
        ```php
        if (preg_match('/\/(?:JavaScript|JS|Launch|OpenAction)\b/', $head . $contents)) {
            throw MultimodalException::invalidMagicBytes('Active scripts, launch actions, or embedded JavaScript are forbidden in uploaded PDFs.');
        }
        ```
    *   Add test in `tests/Feature/Ai/Multimodal/MediaUploadSecurityTest.php` verifying 422 `INVALID_MAGIC_BYTES` rejection for PDFs with JavaScript/OpenAction markers.

---

### [Issue 3] [Unconfirmed Extraction Entering Chat Context] - Severity: Major
*   **Affected Scope:** [app/Http/Controllers/Api/V1/AiChatController.php#L749-L767](file:///v:/pers/Freelance/zolo-erp-pro/app/Http/Controllers/Api/V1/AiChatController.php#L749-L767) (`chat` / `stream` media attachments)
*   **Discrepancy Description:** The direct media-to-workflow handoff endpoint requires extraction confirmation (`$extraction->isConfirmed()`), but `/api/v1/ai/chat` accepts unconfirmed media extractions and injects them directly into the Copilot's prompt context as normalized input.
*   **Rationale:** Weakens the human-in-the-loop governance requirement where unverified model perception outputs should be reviewed and confirmed before being treated as authoritative task context.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:** Valid finding. Unconfirmed OCR or voice transcripts should not influence chat reasoning or downstream action proposals until the user has reviewed and confirmed them via `/api/v1/ai/media/{id}/confirm`.
*   ***Proposed Edit:***
    *   Location: `app/Http/Controllers/Api/V1/AiChatController.php#L749-L751`
    *   Change:
        ```php
        $latest = $asset->latestExtraction;
        if ($latest === null || ! $latest->isConfirmed()) {
            return $this->error('EXTRACTION_CONFIRMATION_REQUIRED', 'Attached media extraction must be reviewed and confirmed before use in chat context.', 422);
        }
        ```
    *   Add feature test in `tests/Feature/Ai/Multimodal/NormalizedInputWorkflowTest.php` asserting that attaching unconfirmed media to `/api/v1/ai/chat` returns 422 with `EXTRACTION_CONFIRMATION_REQUIRED`.

---

### [Issue 4] [Cross-Modal Conflict Checking Missing from Handoff & Chat] - Severity: Major
*   **Affected Scope:** [app/Ai/Multimodal/Services/NormalizedInputWorkflowBridge.php#L43-L77](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Multimodal/Services/NormalizedInputWorkflowBridge.php#L43-L77), [app/Http/Controllers/Api/V1/AiMediaController.php#L320-L343](file:///v:/pers/Freelance/zolo-erp-pro/app/Http/Controllers/Api/V1/AiMediaController.php#L320-L343), [app/Http/Controllers/Api/V1/AiChatController.php#L744-L767](file:///v:/pers/Freelance/zolo-erp-pro/app/Http/Controllers/Api/V1/AiChatController.php#L744-L767)
*   **Discrepancy Description:** While `NormalizedInputBuilder::detectCrossModalConflicts()` exists to compare party names and values between two modalities, `NormalizedInputWorkflowBridge::handoff()` and `AiMediaController::handoff()` process only a single extraction and never pass `$companionExtracted`. Similarly, `AiChatController` processes multiple attached media in isolation without cross-modal comparison.
*   **Rationale:** When a user uploads both a scanned invoice and a companion voice note, discrepancies between them (e.g., party name mismatch) bypass conflict detection.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:** Valid finding. The plumbing between the controller, workflow bridge, and builder is incomplete.
*   ***Proposed Edit:***
    *   Location: `app/Http/Controllers/Api/V1/AiMediaController.php#L330-L343`
        - Accept optional `'companion_media_id' => ['nullable', 'uuid']`.
        - Validate that companion media exists, is confirmed, and pass it to `$this->workflowBridge->handoff()`.
    *   Location: `app/Ai/Multimodal/Services/NormalizedInputWorkflowBridge.php#L43-L77`
        - Accept optional `?AiMediaAsset $companionAsset = null, ?AiMediaExtraction $companionExtraction = null`.
        - Forward `(array) $companionExtraction->extracted_json` as `$companionExtracted` into `$this->normalizedBuilder->build()`.
    *   Location: `app/Http/Controllers/Api/V1/AiChatController.php#L744-L767`
        - When multiple media assets are attached, pass companion extractions to `build()` so cross-modal conflicts are detected and surfaced.
    *   Add feature tests in `tests/Feature/Ai/Multimodal/NormalizedInputWorkflowTest.php` asserting that conflicting party names between bill and voice note trigger `NORMALIZED_INPUT_CONFLICT`.

---

### [Issue 5] [Upload Quota Check-and-Store Race Condition] - Severity: Major
*   **Affected Scope:** [app/Ai/Gateway/ApprovedModelGateway.php#L133-L161](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Gateway/ApprovedModelGateway.php#L133-L161) (`admitMediaUpload`), [app/Ai/Multimodal/Services/MediaIngestionService.php#L64-L142](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Multimodal/Services/MediaIngestionService.php#L64-L142) (`ingest`)
*   **Discrepancy Description:** Daily company media upload quotas are checked in a transaction that only counts existing `ai_media_assets` and commits immediately. The file preprocessing and database insertion of `AiMediaAsset` occur afterwards outside any lock or reservation. Concurrent upload requests could check quota before records are stored, exceeding the configured limit.
*   **Rationale:** Enterprise rate/quota enforcement must be atomic and race-free.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:** Valid finding. A check-then-act race exists between `admitMediaUpload` and `AiMediaAsset::create`.
*   ***Proposed Edit:***
    *   Location: `app/Ai/Gateway/ApprovedModelGateway.php`
        - Add atomic reservation `reserveMediaUpload(AiPrincipal $principal, string $date): MediaUploadReservation` utilizing an atomic lock (`Cache::lock`) and temporary reservation counter with auto-expiring TTL.
    *   Location: `app/Ai/Multimodal/Services/MediaIngestionService.php`
        - Acquire reservation upfront before preprocessing/storing.
        - Call `$reservation->commit()` upon successful `AiMediaAsset::create()`.
        - In the `catch` block, call `$reservation->release()` to free the reserved slot.
    *   Add test in `tests/Feature/Ai/Multimodal/MediaUploadSecurityTest.php` verifying that reserved slots count toward quota and prevent race conditions.

