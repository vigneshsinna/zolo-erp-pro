# zoloERP AI Unified Branch — Phase 2.3 Audit & Complete Remediation Plan

**Document type:** Read-only source audit + implementation specification  
**Audit date:** 14 November 2026  
**Repository:** [vigneshsinna/zolo-erp-pro](https://github.com/vigneshsinna/zolo-erp-pro)  
**Branch:** `feature/ai-unified-input-clarification`  
**Commit inspected:** [`cac0ca386e6038c1693ffece4e62454f3d314a15`](https://github.com/vigneshsinna/zolo-erp-pro/commit/cac0ca386e6038c1693ffece4e62454f3d314a15)  
**Observed UI error:** `[ALL_PAGES_FAILED] Document extraction failed across all pages.`  
**Scope:** Phase 2.3 multimodal AI perception, NVIDIA Omni request handling, media ingestion, ERP handoff, AI Workspace, security, recovery, telemetry, and testing.

> **Evidence boundary:** This document reflects a static inspection of the GitHub commit above and the user's local screenshot. It does **not** claim to have reproduced the error, called the user's NVIDIA account, executed local Laravel tests, or verified a production deployment. Findings are classified as source-confirmed, plausible contributor, or release/test gap. Recheck the branch head before implementing if new commits have been pushed.

---

## 1. Executive summary

Phase 2.3 has substantial working infrastructure: separate Nemotron 3.5 Lightning reasoning and Nemotron 3 Nano Omni perception models, an authenticated media pipeline, private storage, normalized invoice/voice extraction, editable review, confirmation, and a governed ERP handoff.

The latest commit also fixes several earlier AI Workspace defects, including unresolved merge-conflict markers, duplicate form handlers, a CSRF token appearing in the browser URL, malformed attachment rendering, cramped invoice tables, and misleading Purchase Bill handoff messaging.

**It is not yet ready for production sign-off.** The visible `ALL_PAGES_FAILED` message masks the true per-page failure, and the audit identified additional token-accounting, media trust-boundary, workflow, security, and recovery issues. There are **13 findings in the audit list**, including risks and gaps rather than 13 independently reproduced production incidents.

**Immediate objective:** identify the actual failed NVIDIA page call, fix failure transparency and JSON handling, then close the confirmed security/reliability defects and run real-provider and browser end-to-end acceptance tests.

### Status summary

| Area | Source-review status | Follow-up |
|---|---|---|
| Image and PDF upload and preprocessing | Implemented | Improve security and failure recovery |
| NVIDIA Omni perception requests | Implemented | Diagnose failed requests and validate real provider behavior |
| WAV audio request format (`audio_url`) | Corrected in preceding commit | Real endpoint smoke test still needed |
| Normalized extraction, confirmation and revision | Implemented | Verify failures and stale revision handling |
| Multi-input ERP clarification | Implemented | Reject conflicting attached data consistently |
| Human-approved ERP action foundation | Present | Preserve existing approval and execution policies |
| Latest AI Workspace merge-conflict/form defects | Corrected in source | Run live browser regression |
| GitHub Actions for exact audited commit | No run found during audit | Set up/verify CI before release |

---

## 2. Screenshot-specific failure: `ALL_PAGES_FAILED`

### Observation

The browser displays this result after the user attaches the same sample purchase bill previously extracted successfully:

```text
[ALL_PAGES_FAILED] Document extraction failed across all pages.
```

### Code path

- `app/Http/Controllers/Api/V1/AiMediaController.php::store()` receives the multipart upload and invokes `MediaIngestionService::ingest()`.
- Unless `extract_now=false` or `async=true`, the controller immediately calls `MediaExtractionService::extract()`.
- `app/Ai/Multimodal/Services/MediaExtractionService.php::extractVisualDocument()` loops over stored page/image derivatives and calls `NvidiaOmniPerceptionClient::extractStructuredFromImages()` for each page.
- Exceptions inside the per-page `try/catch` are reduced to a warning and failure status. If `succeededPages === 0`, it updates `ai_media_assets.state='failed'`, sets `last_error_code='ALL_PAGES_FAILED'`, and throws the generic error.
- `public/js/ai-media-input.js::submitStagedAttachment()` displays the returned error in chat.

### What this proves and what it does not

- **Confirmed:** All processed pages failed to return an accepted structured extraction, or none of the available derivatives could be processed.
- **Not confirmed:** Whether NVIDIA returned HTTP 400/401/403/429/503, a timeout occurred, the JSON parser rejected a response, token or rate limits fired, or the derivative was absent.
- **Likely investigation target:** `NvidiaOmniPerceptionClient::buildPayload()` does not explicitly disable the model's reasoning output. The model may consume its output allowance before producing final JSON, but this hypothesis requires a real response or reproducible test.

### Required remediation

1. Keep a **safe structured failure code for each page** (`PROVIDER_HTTP_400`, `PROVIDER_RATE_LIMITED`, `PROVIDER_TIMEOUT`, `INVALID_PERCEPTION_JSON`, `MISSING_DERIVATIVE`, etc.). Avoid logging the raw model text, API key, signed media URL or invoice contents.
2. Persist or emit a server-side correlation ID, media ID, model purpose/key, page number, attempt count, HTTP status, provider error category, duration, and token status.
3. Do not swallow page exceptions without preserving an actionable *category* in restricted server diagnostics.
4. If every page fails, return an error object with the failed-page count, safe page failure categories and retryability. Do not reveal private storage locations or provider secrets.
5. Differentiate `all pages failed` from `no valid stored derivatives` and from `schema validation rejected merged output`.
6. Expose a **Retry Extraction** action when the asset remains valid and reprocessing is permitted; reuse its media ID rather than creating a duplicate upload.
7. Test both single-page PNG and multi-page PDFs with one-page success/partial failures/all-page failures.

### Local diagnosis commands (read-only)

Run from the local repository directory:

```powershell
php artisan tinker
```

Inside Tinker:

```php
DB::table('ai_media_assets')
    ->latest('created_at')
    ->limit(5)
    ->get(['id', 'source_kind', 'page_count', 'state', 'last_error_code', 'created_at']);

DB::table('ai_model_invocations')
    ->where('model_purpose', 'perception_vision')
    ->latest('created_at')
    ->limit(10)
    ->get(['model', 'status', 'error_message', 'duration_ms', 'http_attempts', 'media_id', 'created_at']);
```

Exit Tinker and inspect application logs:

```powershell
Get-Content .\storage\logs\laravel.log -Tail 150
```

Also inspect the failed `POST /ai/media` in **F12 → Network → Response**, checking the HTTP code and safe error fields. Do not paste unredacted headers, API keys, raw base64 media, or live customer invoice contents into issue trackers or chat.

**How to interpret:**

| Evidence | Next investigation |
|---|---|
| No `perception_vision` invocation | Missing derivative, admission/rate-limit failure before telemetry, or telemetry recording failure |
| Failed invocation, HTTP 400/422 | Check current API request contract and model acceptance |
| Failed invocation, HTTP 401/403 | Check credentials and model access without printing secrets |
| Failed invocation, HTTP 429 | Check provider RPM bucket, retry policy, and reserved token release |
| Failed invocation, timeout/503 | Check timeouts, payload size, provider health and retry backoff |
| Failed invocation, `INVALID_PERCEPTION_JSON` | Inspect safe parsed metadata, finish reason, thinking mode and token limit |
| Completed invocation but extraction failed | Investigate schema normalization, serialization and persistence |

---

## 3. Findings register — all 13 audit items

| ID | Severity | Finding | Evidence classification |
|---|---|---|---|
| EX-01 | P0 | Per-page failures hidden behind `ALL_PAGES_FAILED` | Source-confirmed |
| EX-02 | P1 | Perception request does not explicitly disable reasoning | Source-confirmed request design; failure contribution unproven |
| EX-03 | P1 | Failed provider telemetry records insufficient diagnostic category | Source-confirmed |
| GW-01 | P1 | Rate-limit enforcement can leave token reservation uncleared | Source-confirmed code path |
| SEC-01 | P1 | Legacy raw `image` chat path bypasses governed media ingestion | Source-confirmed |
| SEC-02 | P1 | Media principal policy checks company/actor, not branch/FY | Source-confirmed; actual exploit not established |
| WF-01 | P1 | Chat passes normalized media conflicts without rejecting them | Source-confirmed control-flow gap |
| WF-02 | P1 | Company/buyer mismatch warns but does not block staged action | Source-confirmed policy gap |
| UI-01 | P2 | Failed upload loses frontend staged-file state | Source-confirmed |
| UI-02 | P2 | Failed upload/review lacks direct retry recovery | Source-confirmed UX gap |
| SEC-03 | P2 | Idempotency key replay doesn't compare newly uploaded file hash | Source-confirmed |
| SEC-04 | P2 | PDF active-content scanner reads only initial bounded bytes | Source-confirmed limitation, not proof of exploit |
| QA-01 | P2 | Mocked tests do not prove real NVIDIA integration and latest CI status | Test-evidence gap |

### EX-01 — Preserve original extraction error categories

**File:** `app/Ai/Multimodal/Services/MediaExtractionService.php`  
**Current behavior:** Page exceptions are caught and summarized; overall response becomes `ALL_PAGES_FAILED`.  
**Fix:** Capture failure codes as typed evidence; log sanitized diagnostics with a correlation ID; return a safe, meaningful failure explanation and a retryable flag.  
**Tests:** 400, 429, timeout, invalid JSON, missing derivative, all-pages-failed and partial-page success.

### EX-02 — Make output mode suitable for strict JSON extraction

**File:** `app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php`  
**Current behavior:** `buildPayload()` specifies `temperature`, `max_tokens` and `stream`, but does not explicitly set `chat_template_kwargs.enable_thinking`.  
**Fix:** Verify the selected hosted Omni model's current accepted parameters and, for extraction-only calls, set a validated non-reasoning output mode such as:

```php
'chat_template_kwargs' => [
    'enable_thinking' => false,
],
```

Do not blindly add provider-specific options without checking the live endpoint and regression tests. Also handle empty final content, finish reason indicating truncation, and provider response structures without leaking chain-of-thought text.  
**Tests:** Strict JSON response, empty content, truncation, refusal, unknown option returned by provider and recovery without fabricating extraction fields.

### EX-03 — Improve failed model invocation diagnostics

**Files:** `app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php`, `app/Ai/Gateway/ApprovedModelGateway.php`  
**Current behavior:** Failed model invocation records commonly store an exception class rather than a useful safe error code, and unsuccessful provider details disappear into a generic page failure.  
**Fix:** Use normalized status/error categories, HTTP status, attempt count, model purpose, media/page correlation, duration, and finish reason when available. Keep logs redacted.  
**Tests:** Confirm errors remain diagnosable without exposing a secret, base64 binary, document details or full provider response.

### GW-01 — Ensure token reservation is settled after rate-limit exceptions

**File:** `app/Ai/Gateway/ApprovedModelGateway.php::admitPerceptionDispatch()`  
**Confirmed sequence:** Reserve tokens in a transaction → call `enforceProviderRateLimit($provider)` → enter `try`/`catch`.  
**Problem:** The rate-limit call can throw before token settlement logic starts.  
**Fix:** Move rate-limit enforcement inside a protected exception/finalization path; settle/release exactly once for *every* failure after reservation. Do not subtract tokens twice.  
**Tests:** Exhaust RPM, verify `reserved_tokens` returns to its pre-request value, retry denied/accepted behavior, concurrent requests and interrupted connections.

### SEC-01 — Remove or strictly route legacy image attachments

**Files:** `app/Http/Controllers/Api/V1/AiChatController.php`, `app/Ai/Agents/DeepSeekAgent.php`  
**Current behavior:** `/ai/chat` accepts a free-form `image` string and can pass a base64 or `RemoteImage` directly to an SDK, outside Phase 2.3 governed private upload and confirmation.  
**Fix:** Deprecate/disable this API field or make it resolve only an already uploaded, permitted, confirmed private `media_id`. Block arbitrary remote image URLs and local paths; require authenticated media ownership, expiry checks and content validation.  
**Tests:** Raw remote URL and base64 bypass attempts rejected; correct confirmed-media path succeeds; disabled multimodal gates deny ingestion while text-only chat remains functional.

### SEC-02 — Enforce appropriate original branch and FY scope

**Files:** `app/Policies/AiMediaAssetPolicy.php`, media retrieval/chat/handoff controllers  
**Current behavior:** `canAccessPrincipal()` checks `isActive`, company and actor; it does not compare stored `branch_id` or `financial_year_id`.  
**Fix:** Explicitly establish a policy for cross-branch/year viewing versus transaction reuse. When used as task or transaction input, require matching branch/FY or a separately authorised, audited reassignment/revalidation.  
**Tests:** Same actor/company but different branch or FY cannot silently reuse a document for a write proposal; legitimate read-only access follows the chosen policy.

### WF-01 — Do not feed unresolved conflicting media into action planning

**Files:** `app/Http/Controllers/Api/V1/AiChatController.php`, `app/Ai/Multimodal/Services/NormalizedInputBuilder.php`  
**Current behavior:** The chat endpoint can construct normalized context including `conflicts` and pass it to the reasoning model; the explicit workflow handoff checks and rejects conflicts.  
**Fix:** Before action planning or proposal staging, require explicit user clarification or block the action if `conflicts` is nonempty. Show all conflicts together and persist corrected source provenance.  
**Tests:** Supplier discrepancy between voice and image, conflicting quantities/prices and cross-document mismatches never produce actionable proposals until resolved.

### WF-02 — Treat material company mismatch as an action blocker

**Files:** `app/Ai/Multimodal/Schemas/InvoiceExtractionV1.php`, `app/Ai/Multimodal/Services/NormalizedInputBuilder.php`, workflow handoff  
**Current behavior:** An invoice billed to a different company produces a warning; the shown normalization logic does not make company mismatch a hard stop by itself.  
**Fix:** Block staging any financial/write action if the document's buyer/seller identity conflicts with the active company unless an explicit authorised business exception is defined. An ambiguous identity should produce a clarification, not a silent accept.  
**Tests:** Supplier invoice billed to Lenz Private Limited cannot be used to stage a zoloERP Textiles Purchase Bill/Purchase Order transaction without an appropriate authorised resolution.

### UI-01 — Preserve a staged file until upload/extraction succeeds

**File:** `public/js/ai-media-input.js::submitStagedAttachment()`  
**Current behavior:** `stagedAttachment` is cleared before the asynchronous call completes. On error the preview object URL is revoked and the staged attachment is not automatically restorable.  
**Fix:** Retain a resumable staged-file reference during validation/extraction and restore user-visible retry controls after failure. Clear it only after success or explicit user dismissal. Do not automatically repeat a potentially billable model call.  
**Tests:** Network offline, HTTP 413/422/429/503, timeout, and failed model extraction preserve actionable retry UX.

### UI-02 — Add safe extraction retry without duplicate upload

**Files:** `public/js/ai-media-input.js`, `app/Http/Controllers/Api/V1/AiMediaController.php`, `MediaExtractionService`  
**Current behavior:** A failed upload with stored media may be retried through a backend extraction route, but the frontend error card doesn't provide an obvious way to invoke it.  
**Fix:** Return media ID and safe retry metadata when extraction fails *after* ingestion; provide **Retry Extraction** on an existing valid media object, with exponential backoff, retryability checks and explicit consent for charged retries. Use `POST /ai/media/{id}/extract` with an appropriate new idempotency/revision strategy.  
**Tests:** Retry same media ID, controlled force re-extract, expired or purged media rejection, no duplicate tenant asset and predictable quota usage.

### SEC-03 — Bind idempotency keys to the uploaded content identity

**File:** `app/Ai/Multimodal/Services/MediaIngestionService.php`  
**Current behavior:** An existing asset is returned by `(company_id, actor_id, idempotency_key)` without comparing the current file hash. The existing test intentionally checks replay with a new file using the same key.  
**Fix:** Verify SHA-256 (and suitable media metadata) before returning an existing asset. Same key + same file is idempotent; same key + different content returns `409 IDEMPOTENCY_CONFLICT`. Account for concurrent same-key requests in the database/locking strategy.  
**Tests:** Same-key/same-file success, same-key/different-file rejection, concurrent same-key creation, expired record semantics.

### SEC-04 — Strengthen PDF safety assumptions

**Files:** `app/Ai/Multimodal/Services/MediaValidationService.php`, `DefaultPdfRasterizer.php`  
**Current behavior:** Basic active-content detection inspects only the first 512 KiB, then bounded rasterization runs via pdftoppm/Imagick.  
**Fix:** Use a bounded PDF parser/security inspection or sandboxed rasterizer isolation to mitigate active content, decompression bombs and pathological documents. Serve sanitized raster derivatives for preview; keep private original retention limited. Treat rasterization as a security-sensitive operation.  
**Tests:** Active-content marker after 512 KiB, malformed objects, encrypted PDFs, giant dimensions, page bombs, timeout, embedded external references and oversized payloads.

### QA-01 — Prove branch-head and live-provider behavior

**Evidence:** The audit saw developer-reported passing targeted PHPUnit tests but no GitHub Actions run for commit `cac0ca3`. Unit/feature tests predominantly mock NVIDIA HTTP responses.  
**Fix:** Run full PHPUnit, static checks, Blade/JS validation, browser automation, real NVIDIA smoke tests in a disposable non-production company, and record immutable evidence for the exact tested SHA. Keep API-secret-bearing tests outside public CI.  
**Tests:** JPEG, PNG, PDF, WAV, WebM, mixed image/voice, unavailable master records, corrections, preview and approval, and safe failure recovery.

---

## 4. Current UI improvements verified in source

The latest commit `cac0ca3` changed `resources/views/backend/ai/workspace.blade.php`, `public/js/ai-media-input.js` and AI Workspace CSS. Source inspection found these improvements:

- Old Blade merge-conflict remnants and syntax disruptions cleaned up.
- A single authoritative `#chat-form` submit handler is registered with `.off('submit').on('submit', ...)`.
- Native form GET behavior is blocked, and the view uses a POST form and JavaScript handler.
- Attachments render as structured chips instead of raw HTML in the user message.
- Invoice review uses compact KPI values for subtotal, GST and total.
- The original preview can be collapsed to let full-width line-item content remain readable.
- Purchase Bill extraction now warns that direct Purchase Bill posting is restricted and offers a separately requested draft Purchase Order route.

**Still test in the actual browser:** no duplicate submissions; no `_token` in URL/history; Enter key behavior with a staged file; correct message persistence; source disclosure; and accessibility on mobile and desktop. Developer claims of local 53/53 tests passing are not equivalent to independently verified branch-head CI.

---

## 5. Implementation order for Codex

### Stage A — Diagnose and unblock the screenshot error (highest priority)

- [ ] Add safe per-page `error_code`, provider status, model purpose, attempt count and correlation ID.
- [ ] Classify and preserve `RATE_LIMITED`, `PROVIDER_HTTP_*`, `TIMEOUT`, `INVALID_PERCEPTION_JSON`, missing derivative and schema rejection.
- [ ] Inspect actual failure for the sample invoice **before** assuming the reasoning option is the root cause.
- [ ] Verify accepted NVIDIA model name, endpoint, request shape and response structure in a real, redacted smoke test.
- [ ] Use the provider-supported non-thinking extraction setting when validated, and explicitly handle truncated output.
- [ ] Ensure safe error response says whether the existing media asset is retryable.
- [ ] Add regression tests for all failure classifications, not merely success mocks.

### Stage B — Close correctness/security gaps

- [ ] Move provider RPM enforcement inside the post-reservation settlement guard.
- [ ] Remove or route legacy `image` chat input through authenticated private media.
- [ ] Define/implement branch/FY reuse policy across media routes and handoff.
- [ ] Block action preparation when normalized input conflicts remain unresolved.
- [ ] Block financial action staging on material active-company mismatch.
- [ ] Enforce content hash on repeated upload idempotency keys.
- [ ] Strengthen PDF safety and sandbox preprocessing assumptions.

### Stage C — Improve failure recovery and user experience

- [ ] Restore the staged file and original user prompt after upload/extraction failure.
- [ ] Add retry on an existing media ID when available; provide sensible backoff and quota messages.
- [ ] Show the actual recorded model key, status and safe failure category on extraction cards.
- [ ] Distinguish "media uploaded", "perception extracted", "user confirmed", "ERP verified", "proposal staged" and "ERP executed".
- [ ] Never imply that Purchase Bill posting is available through a Purchase Order capability.

### Stage D — Full acceptance and release evidence

- [ ] Run all AI tests and security regressions on the exact branch head.
- [ ] Run browser tests covering text, JPEG, PNG, multi-page PDF, WAV/WebM, user corrections, and one-page/all-page failures.
- [ ] Run real NVIDIA calls for each supported modality against a disposable environment with controlled spending.
- [ ] Verify own-tenant and cross-tenant access, branch/FY changes, retries, concurrency and quotas.
- [ ] Verify no unapproved business writes and no direct postings from media extraction.
- [ ] Record the tested commit SHA, test output, provider endpoint results (redacted), known residual risks and release decision.

---

## 6. Test scenarios and expected results

| ID | Test input/scenario | Expected outcome |
|---|---|---|
| T01 | Clear single-page purchase invoice PNG | Valid extraction review with correct fields and recorded Omni model |
| T02 | JPEG version of same invoice | Same normalized values, no automatic ERP write |
| T03 | Three-page PDF, all pages valid | One normalized document, no carried-forward double counts |
| T04 | PDF page 2 fails while 1 and 3 succeed | Partial warning + usable source evidence |
| T05 | All PDF pages fail | Safe underlying categories + `ALL_PAGES_FAILED` summary + retry path |
| T06 | Provider returns invalid JSON | `INVALID_PERCEPTION_JSON`, no fabricated invoice |
| T07 | Provider returns empty/truncated content | Truncation/empty final result classified, not falsely marked successful |
| T08 | Provider 429 before dispatch | Token reservation fully settled; retry-after indicated |
| T09 | Provider timeout/503 | Bounded retries and redacted error; no duplicate ERP transaction |
| T10 | WAV purchase instruction | Transcript review, voice model attribution, user confirmation |
| T11 | Browser WebM voice | Server transcodes; transcript follows same workflow |
| T12 | Tamil-English mixed voice | Supported fallback tested, or explicit unsupported-language response |
| T13 | Image says ABC Traders, voice says Delta Distributors | Cross-modal conflict blocks action until corrected |
| T14 | Uploaded invoice billed to another company | Financial action blocked until correct company/authorised resolution |
| T15 | Missing supplier, product and warehouse | One consolidated clarification card |
| T16 | Create a missing supplier | Separate setup approval; parent PO task resumes only after real receipt |
| T17 | Unconfirmed extraction attached to chat | Denied with clear confirmation-required error |
| T18 | Legacy raw remote image URL sent to `/ai/chat` | Rejected or routed through validated private media path |
| T19 | Same actor/company switches branch/FY | Write handoff denied or explicitly revalidated per scope policy |
| T20 | Same upload key, different file | 409 conflict, not silent replay of previous content |
| T21 | Network fails mid-upload | Staged attachment recoverable for retry |
| T22 | Failed stored extraction retried by ID | Reuses valid media asset; accounts for new provider usage |
| T23 | Dangerous PDF with actions beyond first 512 KiB | Rejected/contained by hardened PDF processing |
| T24 | Approval of PO proposal | Only exact approved version can execute; PO does not post stock or bill |
| T25 | AI action execution disabled | Extraction and previews may work; no ERP write is performed |

### Suggested Laravel test commands

In a **disposable development/test environment**:

```powershell
php artisan optimize:clear
php artisan test tests/Unit/Ai/Multimodal
php artisan test tests/Feature/Ai/Multimodal
php artisan test tests/Feature/Ai/AiChatApiAndWorkspaceTest.php
php artisan test tests/Feature/Ai
```

Also run your repository's configured code-format/static analysis, frontend lint/browser test and CI pipeline. Do not claim complete pass until the same commit passes the defined release gates.

---

## 7. Implementation constraints

1. **Do not replace** the existing governed Action Foundation with model-authored database updates.
2. Keep `AI_ACTION_EXECUTION_ENABLED=false` for diagnostic and preview-only runs.
3. Treat Omni extraction and voice transcripts as **untrusted input** until ERP validation and appropriate human confirmation.
4. Keep Nemotron 3.5 Lightning responsible for approved ERP orchestration; keep Omni focused on perception and extraction.
5. Use existing Laravel ERP business services for supplier/product/warehouse resolution, tax/pricing and transaction lifecycle.
6. Preserve Phase 2.1 full-pass missing-field clarification and Phase 2.2 separately approved dependent master creation.
7. No supplier/product/warehouse creation or invoice posting solely because a model inferred a missing record.
8. Retain media privately, enforce expiry, avoid leaking customer data or API credentials in errors, screenshots or logs.
9. Avoid speculative fixes. Reproduce the failure, name a credible mechanism, fix it and add a regression test.
10. Preserve current working UI, chat history, permissions and model usage telemetry.

---

## 8. Definition of done

Do not mark this remediation complete until:

- [ ] The sample invoice produces correct review data **or** a specific safe failure with diagnostic evidence.
- [ ] A failed Omni API response no longer collapses into an unexplained message.
- [ ] Perception model payload and response shape are verified against the **real** NVIDIA endpoint.
- [ ] Provider rate-limit failures leave no stranded token reservations.
- [ ] Raw image and cross-tenant/cross-scope bypasses are closed.
- [ ] Unresolved cross-modal and company-identity conflicts prevent action staging.
- [ ] Failed uploads and extraction calls have a safe, usable retry workflow.
- [ ] Idempotency replay guarantees same content, not merely the same key.
- [ ] Security and PDF-processing risks have documented tests and controls.
- [ ] Corrected invoice/transcript values retain source provenance and confirmation records.
- [ ] Latest branch-head PHPUnit, frontend and browser regression suites pass.
- [ ] Actual provider calls and approval-only end-to-end scenarios pass in a disposable company.
- [ ] Audit/CI evidence is linked to the **exact commit SHA** deployed.

---

## 9. Reference source files

- [`MediaExtractionService.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Ai/Multimodal/Services/MediaExtractionService.php)
- [`NvidiaOmniPerceptionClient.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Ai/Multimodal/Clients/NvidiaOmniPerceptionClient.php)
- [`ApprovedModelGateway.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Ai/Gateway/ApprovedModelGateway.php)
- [`AiMediaController.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Http/Controllers/Api/V1/AiMediaController.php)
- [`AiChatController.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Http/Controllers/Api/V1/AiChatController.php)
- [`AiMediaAssetPolicy.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Policies/AiMediaAssetPolicy.php)
- [`MediaIngestionService.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Ai/Multimodal/Services/MediaIngestionService.php)
- [`MediaValidationService.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Ai/Multimodal/Services/MediaValidationService.php)
- [`NormalizedInputBuilder.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Ai/Multimodal/Services/NormalizedInputBuilder.php)
- [`NormalizedInputWorkflowBridge.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/app/Ai/Multimodal/Services/NormalizedInputWorkflowBridge.php)
- [`ai-media-input.js`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/public/js/ai-media-input.js)
- [`workspace.blade.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/resources/views/backend/ai/workspace.blade.php)
- [`InvoiceExtractionTest.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/tests/Feature/Ai/Multimodal/InvoiceExtractionTest.php)
- [`MediaUploadSecurityTest.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/tests/Feature/Ai/Multimodal/MediaUploadSecurityTest.php)
- [`NormalizedInputWorkflowTest.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/cac0ca386e6038c1693ffece4e62454f3d314a15/tests/Feature/Ai/Multimodal/NormalizedInputWorkflowTest.php)

---

## 10. Codex handoff instruction

> **Task:** Work on branch `feature/ai-unified-input-clarification`. Investigate `ALL_PAGES_FAILED` first using source/log/model-invocation evidence and the existing test invoice; do not guess the NVIDIA root cause. Then implement and test the audit remediations in this document in the stated priority order. Prefer precise changes to the current Laravel 13/PHP 8.5 services and Blade/JS components. Preserve the existing company/branch/FY security model, two-phase approvals, Action Foundation, Phase 2.1 clarification, Phase 2.2 dependent masters and safe no-write preview mode. Add regression tests for every significant mechanism, run the full applicable AI suite and browser tests, and report the actual changed files, test commands/results, unresolved risks and tested commit SHA. Do not turn on execution against a real accounting company.

**Document outcome:** Implementation guidance only; no GitHub files, code or deployment settings were modified by producing this report.
