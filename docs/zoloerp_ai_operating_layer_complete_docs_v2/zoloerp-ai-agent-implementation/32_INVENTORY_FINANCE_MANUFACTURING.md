# 32 — Inventory, Accounting, Manufacturing and Other High-Risk Effects

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Principle
Inventory, general ledger and production involve **coupled business state**. The agent can help plan and explain, but all committed effects must use the ERP's authoritative services and transaction invariants. Never make one freeform AI plan into multiple manually inserted stock/GL rows.

## Inventory use cases
| Prompt | Proposed workflow | Guardrails |
|---|---|---|
| "How many available?" | Read `inventory.stock_summary.v1` | Scope warehouse, units, reservations, valuation permission |
| "Move 50 from WH-A to WH-B" | Prepare warehouse transfer; approve; execute movement command | Same company, authorized warehouses, batches/variants, stock availability and exact UOM |
| "Adjust damaged 5 units" | Proposed loss/adjustment; privileged approval | Evidence/reason, valuation, audit, posting lock, separate financial effect |
| "Reorder items below minimum" | Read shortages and prepare purchase order lines | No automatic purchase commitment before approval |

Inspect `InventoryMovementService`, `StockMovementCommand`, `InventoryPolicy`, `InventoryReconciliationService`, `InventoryAvailabilityService` and UOM conversion/stock identity. Distinguish physical projection vs movement ledger, committed/reserved/available, exact lot/variant/batch. Do not aggregate unrelated units or hide reconciliation difference.

## Accounting use cases
| Prompt | Proposed workflow | Special policy |
|---|---|---|
| "Show trial balance" | Read authoritative report | All accounts/period/control reconciliation under actor role |
| "Explain lower profit" | Compare verified P&L and sales/COGS | Narrative cites source, no invented causal story |
| "Prepare expense journal" | Draft balanced entry with exact accounts and values | Restricted finance role, locked FY, reason, mandatory approval |
| "Allocate payment" | Resolve open items/payment source and produce allocation proposal | No duplicate settlement, accurate invoice balance |
| "Reverse posted invoice" | Reviewed domain reversal flow | Cannot delete financial history; authority and linked receipts |

Before building journal capability, identify **the actual existing journal command service** and trace permissions, balanced debit/credit, currency, posting date, fiscal locks, tax implications and immutable ledger constraints. `FinancialReportService` is a **read owner**, not a journal posting API. Any AI suggestion like "Debit Purchases, Credit Supplier" must be validated against actual ERP posting accounts and business event, not treated as authoritative accounting advice.

## Manufacturing/job work
Use `ProductionService::plan/complete/reverse`, `BomService`, material movement and job-work services only after verifying industry capability flag, warehouse, BOM revision, available materials, waste/scrap, labor and partial completion. User asks "Produce 500 shirts" → select BOM/product, inspect material requirements and shortages, validate schedule, preview planned consumption/outputs, obtain approval, create production order. Completing production and releasing stock are separate authorized workflow steps. Never bypass lot traceability or scrap accounting.

## Tax and financial reporting
Tax summary can explain currently posted GST balances, but not certify regulatory filing correctness. For bills/invoices call actual `TaxDeterminationService`; validate place of supply, HSN/SAC, invoice tax mode, registration, TDS and statutory flags where applicable. No hardcoded 5%/18% GST rule in the AI stack. Multi-currency amounts and FX dates require ERP-approved conventions, not model calculations.

## Operational multi-step correctness
Order → delivery → invoice, purchase → receipt → bill, production → material issue → completion, stock transfer → posting and accounting must be represented as explicit independent operations. Detect when one domain command already posted related stock or ledger entries and **never create a second duplicate effect**. Each operation emits an actual service receipt; AI only summarizes it.

## Tests
Stock transfer balanced across two warehouses; unauthorized WH; batch/UOM mismatch; reserved stock exceeds available; inventory projection vs ledger diverges; journal unbalanced; wrong account/period; duplicate allocations; production partial completion and reversal; return linked to source; concurrent posting; provider fails after successful domain commit. Finance/warehouse owners must sign exact source effects before enabling each capability.
