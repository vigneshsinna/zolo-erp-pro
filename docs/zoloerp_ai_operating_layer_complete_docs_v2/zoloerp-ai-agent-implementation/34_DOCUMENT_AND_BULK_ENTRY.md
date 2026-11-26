# 34 — Document Extraction, Matching, Excel/CSV Bulk Entry and Error Review

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Why documents are critical
Many ERP users already receive invoices, purchase orders, delivery notes and customer product lists as PDF/image/Excel. The AI should **convert these documents into editable, validated ERP drafts**, not blindly post OCR text. Document capture becomes a separate ingress to the same underlying action-capability registry, so chat, upload and manual forms all use one domain workflow.

## Ingestion pipeline
`Authenticated upload → MIME/size/file extension/virus scan → private quarantine storage → classification (purchase bill/sales order/price list/product import) → extraction (text first; OCR only if necessary) → field provenance/confidence → normalized schema → ERP record lookup → mismatch & duplicate report → editable review → deterministic domain validation → exact preview → approval → safe domain command → receipt`. No automatic web fetch or external sharing of supplier documents.

## Supplier invoice extraction schema
Header: supplier identity/GSTIN (masked where needed), invoice number/date, order/receipt references, currency/FX, place of supply, due date, tax inclusive/exclusive. Lines: description, product code, HSN/SAC if relevant, unit, quantity, rate, discount, CGST/SGST/IGST/cess, totals. Supporting data: source page, extraction confidence, field warnings, mismatch against ERP price/tax/received quantity, scanned checksum. Store source document ID; don't embed the full invoice in all chat memory or traces.

## Matching and reconciliation
- Supplier reference: exact company-owned supplier ID/GSTIN/code first, then user-confirmed candidates.
- Product: exact SKU/alias and UOM variant, then bounded fuzzy matches requiring confirmation.
- Amounts: calculate from verified fields with domain `CommercialPricing`/tax rules, compare to vendor invoice; surface differences, no AI arithmetic as authority.
- PO/receipt: ensure source doc ownership and remaining unbilled/received quantities; avoid duplicate stock/GL effects.
- Duplicate document: supplier+invoice number+company+appropriate period, plus checksum heuristics; don't rely solely on OCR confidence.

## Bulk spreadsheet lifecycle
1. Select allowed import type and existing ERP import schema; map file columns to approved target fields.
2. Preview at least first N rows, identify required fields, duplicate keys, UOM and tax mapping; identify user-selected field overrides.
3. Validate **all rows** in bounded chunks against company/FY/branch and domain policy; return per-row errors with stable row IDs.
4. Display dry-run: `valid`, `needs-review`, `rejected`, `duplicate`, `already-existing`, with totals for each category.
5. Approval binds exact file checksum, schema version, scope and accepted row set; no post-approval silent remapping.
6. Execute chunked idempotent commands using domain services with per-row receipt; allow resume/retry only for unfinished rows; preserve completed rows.
7. Provide exportable error report (CSV/XLSX as supported) and filtered UI for corrections.

## Privacy and performance
Scan uploads before parsing; cap pages, file size, rows and cost per company; optional external OCR provider requires separate data-processing review. Never index payroll/bank documents into general RAG. Encrypt private files; support retention and restricted downloads. Use workers for long jobs and status polling. A failed batch must not leave invisible partially posted records.

## Required tests
Rotated or low-quality PDF; totals differ; duplicate supplier invoice; ambiguous product; tax-inclusive rate; inconsistent unit; invalid Unicode; formula-injection in Excel cells; 1000-row import; partial failure and restart; revoked document ACL; malicious prompt inside invoice; switched company; bulk duplicate retry; correct parent-child order linking; zero unapproved posts.
