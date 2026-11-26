# 30 — Purchase Order, Goods Receipt and Supplier Bill Workflows

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Business objective
A user can create a purchase order from natural language; later record actual receipt; then enter/post the supplier invoice with correct tax, liabilities and inventory impact through **existing ERP purchasing services**. These are separate lifecycle operations, not one freeform SQL transaction.

## Verified domain candidates
- `CommercialApplicationService::create(kind, data, key, actor, context, replacesId?)`
- `PurchaseApplicationService::create(PurchaseCommand $command)`
- `CommercialDraftService::save/load/assertPostable`
- `OrderLifecycleService::initializePurchase`, `remaining`, `receivePurchase`, `consume`
- `PurchaseReceiptService`, `PurchasePostingService::post`
- `TaxDeterminationService::prepare` (under `app/Services/Tax`)
The correct command kind, posting events, tax rules and stock/GL side effects **must be traced in code and approved by purchasing/finance**. Do not assume that one signature is interchangeable with another.

## Workflow A — Create Purchase Order (Unified Clarification Engine)
**Example:** "Order 100 metres of blue fabric from ABC Traders at ₹120 per metre for Warehouse 1."
1. Intent Mapping: Detects `purchase_order.create.v1` and verifies user's `purchase.create` capability.
2. Unified Intake & Draft: Creates or resumes pre-proposal draft in `ai_workflow_tasks` with `expected_revision`.
3. Vectorized Batch Resolution (`BatchRecordResolutionService`): Resolves supplier, warehouse, and all product lines in a single pass. Eliminates legacy stop-at-first-error bottleneck.
4. Completeness Evaluation (`InputCompletenessEvaluator`):
   - If supplier, warehouse, or line items are ambiguous or missing: surfaces a single consolidated clarification card (`workflow.clarification.v1`) with candidate buttons (max 5) and input fields.
   - User submits batch corrections via `PATCH /api/v1/ai/workflow-tasks/{id}/inputs`.
5. Proposal Promotion: Once 100% complete, acquires transaction row lock and creates immutable `ActionProposal` with live `CommercialPricing` calculation, SHA-256 source-state hash, and cryptographic HMAC approval binding.
6. Execution: Upon human approval, `ApprovedActionExecutor` delegates to `CommercialApplicationService::create('purchase')` (status 4). Returns authoritative PO receipt; zero unapproved inventory or ledger impact.

**Required inputs:** supplier, product/quantity/UOM, branch and FY (server resolved), correct warehouse, date, currency/rate under ERP policy and all mandatory domain fields. **Never default an ambiguous supplier/product.**

## Workflow B — Record Goods Receipt
**Example:** "Received 70 of the 100 units on PO-42, batch B-23, damaged 2."
1. Locate the **authorised PO** and `OrderLifecycleService::remaining` quantities; verify posted stage and open balances.
2. Confirm receipt location, accepted vs damaged/rejected quantity, UOM/batch/serial and document date.
3. Preview what stock movement and payable/accrual (if any) ERP will create; check quantity limits, receiving period and idempotency.
4. Require authorised receiving approval; call actual receipt service once, link receipt to PO.
5. When partial, leave remaining PO quantity open; do not auto-mark full receipt or infer invoice.

## Workflow C — Supplier Bill from user text or uploaded invoice
**Example:** "Create bill INV-789 from ABC Traders against PO-42 and the receipt."
1. Extract/resolve actual supplier invoice number/date, items/rates/tax amounts and currency; map to supplier, PO, receipt and product.
2. Reject duplicate supplier invoice under ERP rules; show quantity mismatches against receipts/orders and UOM/price/tax variances.
3. Use `TaxDeterminationService` for IGST/CGST/SGST, cess/rounding and eligibility; preserve vendor invoice's declared tax for review and flag mismatch, rather than silently overriding.
4. Preview **whether ERP is configured to update stock on receipt, bill or both with distinct effects**. Do not duplicate inventory posted by receipt.
5. Ask missing due date, payment terms, supporting document or tax clarification only if required.
6. Explicit finance-authorised posting approval, recheck period, duplicates, supplier status and source-state hash.
7. Call established purchase posting path with idempotency; return actual bill ID, payable/GL posting refs and stock movements only if the domain service produced them.

## Key invariant: no double accounting/stock
A PO, goods receipt and bill are separate business facts. The AI must **not** issue its own second GL journal or stock movement after purchase posting. If current ERP service creates one, rely on that receipt and reconcile. A response may say "Stock updated" only when an actual authoritative receipt shows the stock movement.

## Exception handling
Supplier unknown/inactive; duplicate invoice; invalid GSTIN; no exact SKU; conversion units differ; partial/over receipt; price deviation; tax-inclusive vs exclusive rate; foreign FX; old FY; changed PO after proposal; credit terms; already posted receipt; provider error after successful bill; reversal request. Each exception yields specific clarification, safe denial or a reviewed reversal workflow.

## Acceptance matrix
- PO created from one sentence, correct supplier/product/UOM and exact ERP receipt.
- Wrong company/branch supplier rejected.
- Draft edit changes amount and invalidates old approval.
- Two approval clicks / worker retries create one PO.
- Partial goods receipt updates only actually accepted quantity.
- Bill against existing receipt doesn't duplicate stock.
- Supplier invoice duplicate, closed FY and GST mismatch block automatic posting.
- Full PO→receipt→bill history visible with financial/stock trace, no fabricated success.

## Implementation gate
Start with PO only. Complete receipt+bill only after purchasing, tax, inventory and finance owners approve exact current domain semantics and sample accounting outputs. Keep feature flags separate: `ai.purchase_order.create`, `ai.purchase_receipt.record`, `ai.purchase_bill.post`.


---

## V3 cross-cutting requirements
The initial company/master configuration workflow is specified in `37_GUIDED_INITIAL_SETUP_AGENT.md` with source-confirmed vs future capabilities in `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md`. **ERP Transaction Lifecycle Enforcement** in `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` is mandatory for every existing-record edit, cancellation, invoice correction, return, reversal and adjustment. A chat instruction never creates permission to modify a submitted, posted or paid record directly; proposed revisions return through fresh ERP validation and human approval. Current code remains a read-only Copilot pending separate action implementation.
