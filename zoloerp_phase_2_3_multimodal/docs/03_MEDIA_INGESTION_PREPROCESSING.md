# 03 — Media Ingestion, Validation, Private Storage, and Preprocessing

## Purpose

All media must pass a single trusted server-side ingestion boundary before any model invocation. The UI must not send arbitrarily large base64 blobs to `/api/v1/ai/chat`; use an upload-to-private-storage workflow that returns an opaque `media_id`. Neither user-supplied paths nor document names may influence file-system paths or provider URLs.

## Input policy (proposed defaults, configurable)

| Modality | Accepted at upload | Preprocessor | Example initial limit |
|---|---|---|---|
| Photo / scan | JPEG, PNG | Strip metadata, fix orientation, bound pixels, RGB encoding | 10 MB; 20 megapixels |
| PDF document | PDF, not password-protected in first release | Render pages to PNG/JPEG at 150–200 DPI | 20 MB; 5 pages |
| Browser microphone | WebM/Opus, optionally WAV/MP3 | Decode/convert to mono PCM WAV 16kHz, validate duration | 120 seconds; 20 MB |
| Uploaded audio | WAV, MP3, FLAC | Decode to suitable PCM WAV, sample and duration check | 120 seconds; 20 MB |
| Text | UTF-8 text | Normalize whitespace/Unicode, validate length | Existing `AI_MAX_INPUT_CHARS` |

NVIDIA's NIM endpoint examples support image and audio media content parts, but do not accept PDF directly in the chosen image adapter. Page rasterization is therefore a **zoloERP application responsibility**. GIF/media formats should remain disabled initially despite model NIM supporting them, because image decompression and animation multiply risk and cost.

## Upload validation pipeline

1. Authenticate and resolve `company_id`, `branch_id`, `financial_year_id`, `actor_id` server-side. **Mode A bootstrap uses an isolated endpoint and installation-admin identity**; generic media uploads are company-scoped.
2. Require a valid CSRF token for session-authenticated requests or Sanctum authorization for tokens. Check `ai.use` and applicable media capability, the company AI feature gate and input quota.
3. Inspect file magic bytes / `finfo`, not only the browser MIME, filename or extension. Reject executables, disguised HTML/SVG, ZIP bomb payloads, truncated media and oversized files.
4. Decode media in isolated tooling with bounded CPU, memory, decompressed pixels/pages, execution time and file handles. Optional malware scanning must run before model transmission.
5. Strip EXIF GPS and metadata not needed for the business record. Preserve only necessary rotation/orientation information, transform to standardized image bytes and do not retain locations in extraction output.
6. Compute SHA-256 of original bytes for audit/deduplication (scoped by tenant). A hash is not sufficient to authorize access or to declare invoice uniqueness.
7. Store original and processed derivatives under random server-generated keys on a **private disk**, never `public/`, `storage:link` or arbitrary public URLs.
8. Save `ai_media_assets` metadata, mark `ready`, and schedule the perception job. The job must revalidate media state/scope and fail closed if revoked or expired.
9. Return media ID/status; never return full storage path or model credentials.

## Proposed asset schema

```text
ai_media_assets
  id UUID PK
  company_id BIGINT NOT NULL      # Mode B only
  branch_id BIGINT NOT NULL
  financial_year_id BIGINT NOT NULL
  actor_id BIGINT NOT NULL
  thread_id UUID NULL
  workflow_task_id UUID NULL
  source_kind ENUM(image,pdf,audio)
  original_file_name_sanitized VARCHAR(180) NULL
  original_mime VARCHAR(80)
  canonical_mime VARCHAR(80)
  size_bytes BIGINT
  sha256 CHAR(64)
  private_disk VARCHAR(40)
  private_key VARCHAR(255)       # never exposed to frontend
  state VARCHAR(32)
  expires_at DATETIME
  created_at, updated_at

ai_media_extractions
  id UUID PK
  media_id UUID
  revision INT
  model_provider VARCHAR(40)
  model_key VARCHAR(160)
  extraction_schema_version VARCHAR(24)
  extracted_json JSON
  source_evidence_json JSON
  confirmation_status VARCHAR(32)
  error_code VARCHAR(80) NULL
  created_at, updated_at
```

Use explicit compound-scoped queries for any asset lookup. Foreign keys/indexes may follow existing ERP conventions but don't infer tenancy from a UUID alone. Consider one row per PDF page/segment in extraction metadata; no public page URLs.

## PDF processing

- Reject encrypted, malformed, oversized, or excessively complex PDF files rather than bypassing safety checks.
- Render approved pages into images in a worker sandbox; maintain page index (`1..N`), pixel dimensions and a document-level extraction job ID.
- Process per page within a bounded queue; combine line items with page ordering and detect duplicate page summaries. Do not double-count carried-forward subtotals.
- If one page fails, mark the extraction partial and show that page as not processed; do not pretend the invoice was fully captured.
- Sanitize PDF metadata, embedded URLs and text. The document is data, not instructions.

## Audio preprocessing

- Browser `MediaRecorder` commonly produces `audio/webm`. Convert via an **approved, installed and sandboxed audio transcoder** to a NVIDIA-supported format such as WAV. Do not claim WebM can be sent directly to Omni.
- Reject zero-byte recordings, silence, excessive duration, corrupt streams and unlikely sample rates. Preserve evidence of language and ambiguous numbers; transcript must remain editable.
- Never trust browser-provided duration or MIME alone. Transcoding cannot write outside dedicated temporary directories.

## Deletion, retention and privacy

Suggested configurable default: delete raw upload and rendered derivatives after 24 hours following successful handoff, unless a legal/user-defined attachment retention policy requires retaining the original invoice in the ERP's **separate official document module**. The AI media copy is transient, not the statutory invoice archive. Keep minimal metadata hashes and action audit as required. On cancellation or expiry, queue deletion promptly; test race safety when the extraction worker is running. Admin-configured retention must comply with actual company policy rather than a blanket rule.

## Idempotency and retries

`POST /media` accepts optional client idempotency token scoped to actor+tenant. A retry may return the same permitted media asset, not create a duplicate extraction. Jobs use `media_id + asset_revision + schema_version` to avoid duplicate provider calls when practical; quota accounting covers real dispatches. On an uncertain provider response, mark `retryable`, not `completed`. Do not create ERP proposals from partial media ingestion.

## Tests

MIME spoofing; EXIF stripping; huge dimensions; zip/polyglot; corrupted PDF; 6-page refusal; cross-tenant UUID lookup; expired media; same user double submit; queue retry; interrupted worker; backend-only private store; unsupported WebM without transcoder; disabling feature during queued job; no remote SSRF through image or audio URL; policy-compliant cleanup.
