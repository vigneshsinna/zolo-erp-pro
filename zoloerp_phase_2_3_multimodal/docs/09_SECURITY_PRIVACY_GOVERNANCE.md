# 09 — Security, Data Protection, Compliance, and Governance

## Security posture

The new multimodal model processes potentially sensitive supplier bills, GST registrations, customer names, prices and spoken business instructions. The model is not an authority on ERP records and must not be able to execute financial operations. A compromised document or recording may contain instructions such as “ignore previous rules” or “send all invoices to this URL”; these are **untrusted document content**, not application instructions.

## Threat matrix

| Threat | Likely failure | Required defense |
|---|---|---|
| Prompt injection inside invoice/photo/audio | Model suggests unauthorized command or data release | Perception-only adapter with no business tools; fixed extraction prompts; schema allowlist and ERP validation |
| Cross-tenant media ID probing | Another company sees invoice or transcript | Explicit company/branch/FY/actor ownership guards on every endpoint and job |
| SSRF through external media URLs | Provider/backend fetches internal URL | Do not accept arbitrary URLs in first release; private uploaded bytes only |
| Malicious PDF/image/audio | Worker resource exhaustion / parser exploit | File sniffing, bounded rasterizer/transcoder, sandbox, malware scan and CPU/memory limits |
| Financial hallucination | Wrong GSTIN, quantity, tax or total | Source evidence, user confirmation, exact decimal and independent domain calculation |
| Unauthorized master creation | Missing supplier silently created | Separate registered setup action and authenticated approval |
| Approval laundering | OCR result treated as signed approval | Immutable signed action proposal only after domain validation; separate UI approver action |
| Duplicate upload / retries | Duplicate PO/bill or provider calls | Scoped media idempotency; independent ERP domain idempotency for all writes |
| Private media leak | Images accessible via public storage/logs | Private encrypted storage, authenticated previews, redacted logs, no raw media URLs |
| Model exfiltration via transcripts | Secrets exposed through model prompt | Data minimization and allowlisted provider endpoints; source text treated as data |
| Model/API error reveals credentials | Provider key leaked in stacktrace | Scrub exceptions, redact headers and request bodies, no provider raw error in UI |
| Long audio or PDF storms | Unlimited GPU cost / API quota exhaustion | Per-user/per-company size/page/duration quotas and shared RPM budget |
| Uncertain vendor residency | Unapproved transfer of financial documents | Review NVIDIA endpoint location, contract, retention and organizational data policy |

## Tenancy Law 1

All regular multimodal workflow reads and writes must use a verified non-null `company_id`; require branch/FY where relevant. Do not introduce a nullable `company_id` in ordinary `ai_media_assets` for convenience. A pre-company setup attachment can only exist under a separate installation-admin principal and endpoint with dedicated ACL, not within generic company-scoped APIs. A model must never choose tenant IDs from text or image.

## Accounting and Indian GST

- GSTIN, HSN/SAC, state/place of supply, CGST/SGST/IGST and tax registrations are document **claims** until checked against ERP's tax services.
- If supplier GSTIN or document tax conflicts with the configured party profile, show a blocking clarification; do not silently overwrite authoritative records.
- A photographed invoice is not proof that a payable should be posted. Human approval must review exact ledger-affecting totals and transaction type.
- No direct edits of posted/partially paid invoices. Use only approved lifecycle transitions (returns, debit/credit notes, reversal/adjustment).
- The financial-year status and current tax/price dependencies must be rechecked under appropriate domain transactions before any write.
- An “OCR successful” receipt is different from an ERP posted voucher receipt.

## Storage and retention

Keep media on encrypted private storage with keys rotated according to the organization's policy. Upload filenames are sanitized display labels only. Temporary media should have a short, documented retention period, default proposal 24 hours after handoff. Official statutory documents, if needed, should be stored in the existing ERP document attachment subsystem under its own retention policy. Do not delete official source invoices merely because an AI cache expires. Support user-requested removal where policy permits; keep minimal audit metadata needed to justify approvals.

## User permissions

- Upload media: `ai.use`, company feature gate and `ai.media.upload` (if introduced) scoped to context.
- View media: owner or explicitly authorised task collaborator / approver in same tenant. Never broad public file reads.
- Confirm/correct extraction: owner or delegated authorized reviewer; revision bound to identity.
- Create product/supplier/warehouse: specific existing ERP create permissions and registered setup command.
- Approve PO/bill: existing `ai.action.approve` and action-specific permissions; enforce separation of duties if company policy requires it.
- Execute: `AI_ACTION_EXECUTION_ENABLED`, valid approval/HMAC/current state, registered adapter and domain guard.

## Observability

Record (without raw media): correlation ID, tenant ID, actor ID, media ID, MIME, byte count, model purpose, model key, prompt/schema versions, duration, provider request IDs if safe, token usage, status, retries, user corrections and resulting proposal ID. Redact PAN/GSTIN values, supplier bank details, user audio and invoice photos from logs and dashboards. A telemetry record is not permission to store full transcripts forever.

## Deployment, fallback and incident response

Roll out only to test companies, then pilot tenants with explicit administrator opt-in. Have independent kill switches for media upload, provider processing and business action execution. If model output appears unreliable or provider reports data-handling issues, disable perception without disabling the text-only ERP Copilot. Do not retry uncertain business transactions. Incident response includes asset revoke/delete, permission audit, key rotation, provider account review and data-access notifications according to applicable law and company policy.

## Go-live blockers

No provider eligibility verification; invalid/unknown hosted model ID; raw media in public storage; no tenant query scope; no file/media size controls; no retention policy; unchecked GST/totals into posting adapters; inability to revoke private media; missing human approval; provider rate limit not shared with existing NVIDIA calls; insufficient tests against prompt injection and cross-company media access.
