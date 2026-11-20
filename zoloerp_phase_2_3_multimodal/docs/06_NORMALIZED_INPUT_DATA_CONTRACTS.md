# 06 — Typed Normalized Input, Provenance, Versioning, and Schema Contracts

## Why a common contract matters

Text, image, audio and OCR output must enter the exact same company-scoped input/clarification engine. Avoid sending a raw LLM answer as a “fact card” with implied authority. The normalization layer can emit candidate slot values; only Laravel validators and ERP domain resolvers can make them trusted for action proposals.

## Data model

**NormalizedInputV1** fields:

| Property | Type | Meaning |
|---|---|---|
| `input_id` | UUID | Server-assigned unique normalized input |
| `schema_version` | string | `normalized.input.v1` |
| `mode` | enum | `company` only on generic endpoint; isolated `bootstrap` elsewhere |
| `scope` | server-only object | Actor, company, branch, FY. Never populated from media or LLM |
| `modality` | enum | `text`, `image`, `pdf`, `audio`, `mixed` |
| `media_refs` | UUID[] | Opaque, permission-scoped media/extraction references |
| `raw_text` | string? | Sanitized typed message or confirmed transcript |
| `intent_candidates` | object[] | Untrusted potential action keys, need capability validation |
| `slots` | map | Field path -> candidate value and origin |
| `issues` | object[] | Missing, ambiguous, conflicting, unreadable, unsupported |
| `confirmation_state` | enum | `unreviewed`, `partial`, `confirmed` |
| `revision` | int | Monotonic optimistic-concurrency version |
| `created_at` | ISO timestamp | Server time |

### FieldValue contract

```json
{
  "field_path": "items[line-uuid].quantity",
  "raw_value": "fifty",
  "normalized_value": "50.00",
  "data_type": "decimal_string",
  "source_kind": "audio_model",
  "source_reference": {"media_id": "<uuid>", "page": null, "segment": null},
  "verification": "unverified",
  "confidence_band": "unknown",
  "provenance_revision": 1,
  "requires_user_confirmation": false
}
```

Do **not** store `0.94` as trustworthy calibrated confidence merely because an LLM emitted it. Prefer `unknown`, `high_evidence`, `weak_evidence` labels grounded in explicit consistency checks. Preserve verbatim OCR source snippets only when needed and redact financial identifiers where policy requires.

## Trust and precedence rules

1. `user_confirmed` value has precedence over `model_extracted`, unless it violates a mandatory ERP constraint; it must never be silently overwritten by a retry.
2. `verified_erp` record reference has precedence over an unconfirmed model entity-name guess. The ERP may still become stale, requiring revalidation.
3. Derived defaults are tagged `derived_default` and invalidated when upstream dependencies change (supplier, product, warehouse, FY, tax category).
4. `model_extracted` may fill candidate slots but cannot be used to compute final tax, totals, stock or account selection.
5. Mismatched sources (e.g., invoice says 12, spoken note says 15) create an explicit `conflict` issue; ask the user which interpretation to apply.
6. The ingestion system may propose an action key, but `ActionCapabilityRegistry` must confirm the action exists and is allowed before creating a proposal.

## Item addressing

Use stable `client_line_id` values. A correction to `items[line-a].qty` changes only that line's quantity, not its sibling price or other line items. New lines need a server-validated client ID. Deletion is an explicit mutation (`_destroy: true`) with expected revision; reorder is a separate ordered `line_ids[]` patch. Reject duplicate IDs and out-of-scope line references.

## Sample envelope

```json
{
  "schema_version": "normalized.input.v1",
  "input_id": "<uuid>",
  "modality": "mixed",
  "raw_text": "Use this invoice but change the quantity to 12.",
  "media_refs": ["<image-media-uuid>"],
  "intent_candidates": [{"action_key": "purchase_bill.create.v1", "untrusted": true}],
  "slots": {
    "supplier.name": {"normalized_value": "ABC Traders", "source_kind": "image_model", "verification": "unverified"},
    "items[line-a].qty": {"normalized_value": "12.00", "source_kind": "user_explicit", "verification": "user_confirmed"}
  },
  "issues": [{"field_path": "supplier.id", "kind": "not_resolved", "reason": "ERP supplier lookup pending"}],
  "confirmation_state": "partial",
  "revision": 2
}
```

The `scope` object is intentionally omitted from this user-visible example; server-side scope must never be accepted as a mutable body field.

## Validation and error contract

- Schema validation uses JSON Schema / Laravel rules with a strict version (`normalized.input.v1`), maximum nesting, maximum 100 lines initially and decimal string formats.
- Dates must be ISO `YYYY-MM-DD` only after a documented locale-aware parse; retain original date text if ambiguous (`01/02/26` is uncertain).
- Currency must be verified against company configuration; don't infer currency from “₹” without checking context.
- Unknown fields are rejected or stored as `unmapped_observation` for review, never pushed to domain command payloads.
- Revision conflict: HTTP 409 with `{ "error_code": "REVISION_CONFLICT", "server_revision": 4, "task": { ... redacted safe latest state ... } }`; no overwriting latest user corrections.
- Expired task/media: HTTP 410 with a stable code and a way to start fresh. Unauthenticated/foreign scope: 404/403 without identifiers.

## Promotion into governed actions

NormalizedInput is not itself an Action Proposal. Promotion occurs only after all applicable required fields are resolved and Laravel's action-specific schema passes. Use a short transaction with `lockForUpdate()`, compare `expected_revision`, mark task `proposal_created`, and create exactly one linked immutable proposal per revision/idempotency key. If action creation fails, leave task in a recoverable state; do not create a half-approved proposal.

## Audit

Store model ID, prompt-template version, processing time, user-confirmed amendments, source references and a hash of canonical normalized payload. Keep full raw financial media separate from action audit with explicit retention. Approval binding must remain over **the final domain action proposal**, not over an unverified OCR response.
