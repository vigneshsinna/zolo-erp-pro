# 08 — AI Workspace UI / UX and API Contracts

## Primary user experience

Extend the existing `resources/views/backend/ai/workspace.blade.php` rather than launching an unrelated “Omni chat” page. The user sees one compose area containing text, an attachment icon, camera/image picker and microphone control. The AI Workspace retains company and fiscal-year context in the header. Only authenticated scope-aware server routes accept uploads.

### Compose area states

- **Text:** ordinary prompt submission; no extra model cost.
- **Attach image/PDF:** show thumbnail/filename/page count and “Analyze document” before sending; allow removal.
- **Record audio:** visible consent, recording indicator, duration, stop/replay/delete; transcript available before submitting to the ERP task engine.
- **Mixed:** text + selected attachment; show both sources, with deliberate order and conflict resolution.
- **Error:** show actionable messages: unsupported format, too large, unreadable, transcription failed, or quota exceeded.

### Extraction review card

```text
┌───────────────────────── Extracted Document ───────────────────────────┐
│ Source: invoice-1082.jpg  |  Image  |  Unverified  |  Page 1 of 1     │
│ Supplier: [ABC Traders         ]  [Select existing] [Create new]      │
│ Invoice #: [INV-1082          ]   Date: [2026-11-10]                  │
│ Item              Quantity      Unit       Price      ERP match       │
│ Blue Cotton Shirt [10.00]       [PCS]     [500.00]   [Needs match]     │
│ Source subtotal: ₹5,000 | Tax: unreadable | Source total: unknown     │
│ Warnings: GST needs verification; buyer identity not confirmed       │
│ [View original]     [Correct] [Confirm & continue] [Cancel]           │
└────────────────────────────────────────────────────────────────────────┘
```

The card is a UX contract, not an implemented screen. `Create new` opens a separately approved setup workflow; it must never silently call a master-record create API. The card must not display an “Approve & Post” button until an authoritative ERP action proposal exists.

## API routes — PROPOSED ONLY

Existing `/api/v1/ai/chat`, `/api/v1/ai/proposals`, and isolated `/api/v1/ai/setup/bootstrap/...` routes remain in place. Add:

| Method | Proposed route | Auth and purpose |
|---|---|---|
| `POST` | `/api/v1/ai/media` | Multipart upload; tenant auth, quotas; returns media ID |
| `GET` | `/api/v1/ai/media/{id}` | Asset metadata/status; scoped owner or authorised workflow participant |
| `POST` | `/api/v1/ai/media/{id}/extract` | Start idempotent async extraction; returns job/status ID |
| `GET` | `/api/v1/ai/media/{id}/extraction` | Validated extracted candidate fields, no private file path |
| `PATCH` | `/api/v1/ai/media/{id}/extraction` | User corrections with `expected_revision`; no ERP mutation |
| `POST` | `/api/v1/ai/media/{id}/confirm` | Confirm normalized input for task handoff |
| `DELETE` | `/api/v1/ai/media/{id}` | Cancel/revoke and schedule private asset cleanup |
| `GET` | `/api/v1/ai/media/{id}/preview` | Authenticated, streamed media preview with private access guard |

Do not expose a single generic media route for pre-company Mode A. If pre-company attachments are later necessary, build a separately permissioned `setup/bootstrap/media` flow. The conventional tenant-scoped `media` endpoint requires non-null `company_id` and authorized branch/FY.

## Example upload

```http
POST /api/v1/ai/media
Authorization: Bearer <session-or-Sanctum>
Content-Type: multipart/form-data
X-Company-Id: <authorized-context-id>
X-Branch-Id: <authorized-context-id>
X-Financial-Year-Id: <authorized-context-id>

file=<image-or-audio-bytes>
thread_id=<optional-owned-thread-uuid>
input_type=image
```

```json
{
  "media_id": "<server-created-uuid>",
  "status": "ready",
  "mime": "image/jpeg",
  "file_name": "invoice.jpg",
  "next_action": "extract"
}
```

Authorization headers on existing APIs help select context but are **not sufficient proof of access**: middleware and service must verify actual active membership and permissions. The server must ignore any company ID embedded in a document or model response.

## Async extraction example

```http
POST /api/v1/ai/media/{id}/extract
Content-Type: application/json

{"schema": "media.invoice.v1", "idempotency_key": "<client-key>"}
```

```json
{
  "status": "extracting",
  "media_id": "<uuid>",
  "extraction_id": "<uuid>",
  "estimated_retry_after_seconds": null
}
```

Do not promise wall-clock completion times; the UI can poll within a capped interval and present progress/status. Queue jobs can complete or fail independently of the browser tab.

## Correction and revision

```http
PATCH /api/v1/ai/media/{id}/extraction
Content-Type: application/json

{
  "expected_revision": 2,
  "changes": [
    {"op": "replace", "path": "items[line-a].quantity", "value": "12.00"},
    {"op": "replace", "path": "supplier.name", "value": "ABC Textiles"}
  ]
}
```

Revision mismatch returns HTTP 409:

```json
{
  "error_code": "REVISION_CONFLICT",
  "server_revision": 3,
  "message": "The extraction changed. Review the latest version before submitting.",
  "current": {"confirmation_state": "partial", "issues": []}
}
```

A stale `confirmed` extraction cannot be edited in place; create a new revision and revalidate the task. Media deletion during a queued extraction must invalidate the job and prevent it from restoring a deleted asset.

## Accessibility and privacy requirements

- Button labels (e.g., “Record audio”, “Stop recording”, “Review extraction”), not icon-only opaque controls.
- Keyboard operation, sufficient contrast, focus order, error summaries and ARIA live messages for status changes.
- Never store user voice or invoice images in `localStorage`, DOM attribute strings, query parameters or ordinary analytics.
- Secure preview downloads behind scoped authorization and no-store cache headers.
- Show camera/microphone consent and retention information before uploading.
- Do not display user API keys or provider errors in model output cards.

## State and fallback behavior

If Omni is unavailable, preserve typed text and upload metadata and show a manual data-entry option. If the user navigates away, an authorized pending extraction can be retrieved by ID in the same company context. If the user changes company, the prior task remains in the original company and is not shown in the new company.
