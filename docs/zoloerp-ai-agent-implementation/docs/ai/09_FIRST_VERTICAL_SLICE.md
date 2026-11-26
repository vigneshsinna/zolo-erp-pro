# 09 — Read-Only Receivables Copilot Vertical Slice and Regression Baseline

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Purpose and historical note
This was the **first implementation milestone** in the original pack and is now represented in source, not an unimplemented proposal. Preserve it as a regression benchmark for future phases. Do not copy obsolete one-off SDK Tool wrappers; use `AiToolManifest` and `GenericSdkTool`.

## End-to-end read flow
User requests 'Who are my five largest overdue customers?' → API resolves authenticated actor/company/branch/FY → permit `receivables.outstanding.v1` via manifest → SDK advertises `CustomerOutstanding` provider alias → `ErpToolExecutor` validates as-of date and limit, refreshes actor scope and report permission → `ReadCustomerOutstanding` calls `FinancialReportService::ageing` with explicit actor → aggregates open amounts using exact decimals → returns top five, total from all rows, reconciliation difference, warnings, period, source link → controller/UI renders authoritative fact card and model commentary.

## Contract fixtures
- Company A with two branches and FY boundaries, Company B with overlapping record IDs.
- Customer A with partially paid invoices, customer B with multiple open items, customer C with credit/negative balance, aged unpaid lines and fully paid items.
- Balanced and intentionally mismatched AR control accounts.
- Query top five vs top one: total must not change; top list must be sorted correctly.
- FY out of range returns a validation failure; no silent clamping; company timezone used.
- Cross-company/branch/customer/other-user conversation is denied.
- Permission revoked mid-run: no tool result, and old facts not replayed unexamined.
- Provider 429/timeout, invalid tool schema, malicious input, no-data and unreconciled warnings.

## Example response contract (synthetic)
```json
{
  "tool": "receivables.outstanding.v1",
  "status": "ok",
  "scope": {"company_id": 22, "branch_id": 9, "financial_year_id": 17},
  "as_of": "2026-11-10",
  "data": {"total_outstanding": "842500.00", "top_customers": []},
  "reconciled": true,
  "warnings": [],
  "source_refs": ["/accounting/ageing?party_type=customer"]
}
```
Paths and values are illustrative; actual route and response fields are controlled by current code/golden tests. Don't expose the example identifiers as real company data.

## Minimum acceptance
All financial figures match `FinancialReportService` under same context and date; no cross-tenant leakage; error is not disguised as 0; signed amounts handled; source/report route accessible to actor; clear warning when control reconciliation fails; SDK function-call round-trip tested; output sanitized and accurately displayed on desktop/mobile; no AI-triggered ERP business mutations.

## Current status to verify before next phase
At current reviewed SHA `14195d8`, the complete ERP acceptance workflow passes. Before action development, still require domain financial reconciliation and independently recorded, synthetic staging NVIDIA tool-calling/usage evidence. Preserve this first slice as a golden non-regression benchmark.


---
