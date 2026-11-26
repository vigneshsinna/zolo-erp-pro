# D13 — Deterministic First, LLM Second (ERP Copilot)

**Baseline:** \`feature/ai-unified-input-clarification\` at \`206d15d2013c1518383b3fd78f3e3432a5b8587c\`. Independent follow-up to D01–D12 and T00–T11; **do not rebuild** the existing action registry, model gateway, credit meter or conversational PO workflow.

## Non-negotiable routing rule

**Authoritative ERP facts and rules execute in PHP/MySQL, never in a text-generation model.** Only send unstructured input, ambiguous wording, exception reasoning and message drafting to \`ApprovedModelGateway\`. Model output cannot set tax policy, derive a posting amount, waive a permission, choose a company/warehouse, or bypass human approval.

### Implemented in this PR

A strict \`DeterministicReadRouter\` inspects the entire typed instruction **before** the general \`ErpCopilot\` provider path. Only explicitly supported, unambiguous read requests match. Accepted requests:

| Intent | Execution | LLM HTTP calls | Model credits |
|---|---|---|---|
| Receivables aging | Existing \`receivables.outstanding.v1\` handler and FinancialReportService | 0 | 0 |
| Payables aging | Existing \`payables.summary.v1\` handler | 0 | 0 |
| Stock summary | Existing \`inventory.stock_summary.v1\` handler and availability service | 0 | 0 |
| Available stock below reorder threshold | Branch-level inventory availability vs product \`alert_quantity\` | 0 | 0 |
| Open purchase/sales orders | Existing \`orders.open.v1\`, explicitly filtered by type | 0 | 0 |
| GST reporting summary | Existing \`tax.gst_summary.v1\` | 0 | 0 |
| Sales summary | Existing \`sales.summary.v1\` | 0 | 0 |
| Validate GSTIN checksum | \`Gstin::normalize()\` / \`Gstin::checkDigit()\` | 0 | 0 |
| Exact supplier invoice-number lookup | New read-only \`erp_checks.duplicate_purchase\` handler, scoped to visible company/branch/FY purchases | 0 | 0 |
| Basic two-number arithmetic | Brick Math BigDecimal; no floating-point monetary guessing | 0 | 0 |

The chat response includes \`interaction_type: deterministic_erp\`, an audited ERP run, authoritative facts where available, \`model_calls: 0\` and \`credits_debited: 0\`. Read tools pass the existing \`ErpToolExecutor\` manifest, permission, feature and telemetry checks. The request does not invoke \`ApprovedModelGateway::admitRequest\`, model dispatch, RPM reservation, token reservation or credit metering.

**Strict separation:** The model path remains for nonmatching natural-language requests and authorized multimodal parsing. No deterministic pattern may fabricate ERP IDs or silently dispatch a write.

### Deterministic-only checks with missing context

For an instruction to **calculate GST/RCM**, **check a duplicate without an invoice number**, or **compare PO–GRN–Bill without the required documents**, the chat replies with a bounded clarification and **does not call the LLM**. This does **not** mean that the full check has been performed.

- Actual Section 15 GST/RCM determinations remain in the authoritative \`TaxDeterminationService\` (registration, supply type, date, HSN/SAC, GST rates, exemptions, reverse-charge rules).
- Invoice extraction already emits \`erp_checks.duplicate_purchase\` as a warning. The new standalone lookup matches exact supplier invoice numbers for visible purchases. **Same invoice number is a potential collision, not proof of a duplicate**; supplier, date, status and accounting context remain important.
- A full PO–GRN–Bill 3-way mismatch/variance analyzer is **not delivered** in this PR. Reuse order, GRN, bill and allocation domain data with normalized units and tolerance rules in a separately tested read handler. No invented match conclusions.
- Stock alert uses \`Product.alert_quantity\` (existing ERP field called “reorder level” in parts of the UI) against branch **available** quantity. Positive configured thresholds only; all stock math remains SQL/PHP, with bounded returned rows.
- A validated GSTIN **format/checksum** does not prove the GST registration is active, and should never be presented as such.

## Other high-value PHP-first opportunities

Prioritize these by reusing existing ERP services rather than writing duplicate business logic:

1. Invoice taxes, discounts, CGST/SGST vs IGST, RCM and freight: \`TaxDeterminationService\`, exact decimal rounding, official tax setup; no rate guessed by a model.
2. GSTIN and invoice-number formats, HSN/SAC/UOM validation, same-company supplier/product/warehouse matching.
3. Stock on hand, available, reserved, below safety threshold, batch/lot quantities, expiry dates and projected shortages. Do not use LLM for comparisons.
4. PO/GRN/Bill quantity-rate-tax tolerance checks, missing GRNs, overbilling, duplicate billing and returns — implement via commercial-order allocation/receipt services.
5. AR/AP aging buckets, credit terms and due dates, payment allocation, partial payment and outstanding balance.
6. Cash/credit term state machines, financial-year lock, company/branch boundaries, currency decimal math, tax-inclusive totals and round-off.
7. Exact invoice/document lookups, workflow statuses, approval permissions, duplicate action hashes, retry/idempotency and audit-readback.
8. Reorder proposals and dashboard summaries from verified thresholds; human approves actual purchase/stock writes.

**LLM is appropriate** for image/PDF OCR, audio transcription, ambiguous entity resolution, *why*-diagnosis of already computed exceptions, multilingual phrasing and drafting contextual messages. Always ground its narrative in authorized ERP facts. Never trust it to decide amounts, posting, tax or access control.

## Acceptance tests

- Recognized read intents produce **no rows** in \`ai_model_invocations\`, **no provider HTTP requests**, **zero credit debits** and **zero NVIDIA RPM bucket usage**.
- Authenticated company/branch/FY permission checks are still enforced for direct tools. An unauthorized report fails closed and does not retry through an LLM.
- Malformed/ambiguous requests do not select a different tool or switch into a write operation.
- Reorder count/rows use the selected branch's inventory and positive product \`alert_quantity\`. Do not rely on catalog aggregate \`qty\`.
- Duplicate invoice lookup filters by company, branch, FY and accessible warehouses; invoice matches are labeled possible.
- GSTIN only confirms checksum and format; actual registration or GST liability remains unverified.
- Existing \`AiChatController\` history, source provenance, telemetry and streamed response formats continue to work.
- Strict one-question-at-a-time PO UX from PR #24 is unaffected; conversation corrections keep the same task.

## Performance and rollout

Zero model credits is enforceable for these routes, but **less than 20 ms is a benchmark target, not a guarantee**: SQL report complexity, tenant size, indexing and cold-cache latency can exceed that. Measure p50/p95/p99 by operation and do not optimize by bypassing scope, audit or reconciliation. Existing application/model-credit limits remain unchanged for genuine model calls.

There are **no schema migrations** in this PR. Rollback by reverting the feature branch commit; preserve ERP/audit records. Maintain feature gates and normal approval protections. Stage under a disposable tenant and run MySQL concurrency, cross-company/FY, full AI and normal ERP CI suites before merge.
