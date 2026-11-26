# 27 — Natural-Language Intent, Slot Filling and Record Resolution

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Purpose
Turn the user's language into a **proposed typed task**, not a raw SQL statement and not a business command. The model identifies intent and candidate fields; Laravel schema validation, deterministic search and business policies decide valid records and values.

## Normalized intent envelope
```json
{
  "intent_key": "purchase_bill.prepare.v1",
  "input_source": "chat",
  "entities": {
    "supplier_query": "ABC Traders",
    "items": [{"product_query":"Cotton Shirt","quantity":"50","uom":"pcs","unit_price":"350.00"}]
  },
  "missing_fields": ["supplier_invoice_no", "goods_received_status"],
  "user_message_id": "server-generated-id"
}
```
This is **illustrative and untrusted**. The server attaches actor/company/branch/FY and approved task version; it never accepts model-proposed `company_id` as authorization.

## Intent taxonomy
Define stable versioned intent families: `report.*`, `master.customer.*`, `master.supplier.*`, `master.product.*`, `purchase_order.*`, `purchase_receipt.*`, `purchase_bill.*`, `sales_quote.*`, `sales_order.*`, `sales_invoice.*`, `payment.*`, `inventory_transfer.*`, `stock_adjustment.*`, `journal.*`, `manufacturing.*`, `document_import.*`, `workflow_schedule.*`. Unknown or unsupported intents return safe unsupported clarification rather than being routed to arbitrary PHP.

## Record resolution stages (Single-Pass Batch Vectorization)
In accordance with Phase 2.1 (`BatchRecordResolutionService`), resolution executes in a single bulk pass across all lines and headers rather than halting on the first error:
1. Server derives authenticated `CompanyContext` (company, branch, financial year) and authorized actor permissions.
2. Normalize user phrase (trim, case, Unicode, local aliases), preserving original raw input in draft audit.
3. Vectorized Set-Based Queries:
   - **Parties (Suppliers / Customers):** Match company-scoped party records via exact ID, GSTIN, code, legal name, or phone. Ambiguous matches return bounded candidate pickers (maximum 5 options).
   - **Warehouses:** Resolves active primary warehouse for the authenticated branch by default; matches explicit warehouse names or codes.
   - **Products Across All Lines:** Collects all product codes/barcodes across line items and executes a single bulk `Product::where('company_id', $companyId)->where(function ($q) use ($codes) { $q->whereIn('code', $codes)->orWhereIn('barcode', $codes); })->get()` query, eliminating N+1 query storms.
   - **Units of Measurement:** Resolves `purchase_unit_id` for purchasing flows or `sale_unit_id` for sales flows, applying verified conversion rates from `product_unit_conversions`.
4. Dirty-Slot Memoization: Re-evaluations inspect `resolution_json` and skip lines whose inputs have not mutated since previous evaluation.
5. Missing Entity Gate: If no match exists, present selectable candidate options or an explicit action to trigger an in-flight master setup proposal (`setup.customer.create.v1`, `setup.product.create.v1`) without losing active draft state.

## Required slots per command
Requirements are declared via `ActionRequirementsRegistry` by domain-specific providers (`PurchaseOrderRequirementsProvider`, `SalesOrderRequirementsProvider`, `QuotationRequirementsProvider`, etc.). Each slot specifies `dataType`, `entityType`, `isRequired`, `resolutionPolicy`, `defaultPolicy`, and dynamic dependencies. Line items are indexed by stable client IDs (`client_line_id`, e.g. `items[line-1].product_id`), supporting in-place line additions, quantity modifications, and deletions (`_destroy: true`).

## Clarification policy (Single Consolidated Clarification Card)
Never ask repetitive one-by-one questions that stop at the first missing field.
1. **Single-Pass Evaluation:** When inputs are incomplete, ambiguous, or invalid, `InputCompletenessEvaluator` collects all issues across headers and all line items into a single diagnostic payload.
2. **Interactive Clarification Fact Card (`workflow.clarification.v1`):** Surfaces in the AI Workspace displaying:
   - **Confirmed Fields Summary:** Read-only pills of verified supplier/customer, warehouse, order date, and validated line items.
   - **Candidate Disambiguation Buttons:** Bounded candidate selectors (max 5) for ambiguous parties or products.
   - **Missing Input Controls:** Dedicated inputs for required dates, quantities, or prices.
   - **Advisory Stock / Credit Warnings:** Non-blocking availability badges for low stock or credit limit cautions.
3. **Consolidated Correction Patch:** The client submits all corrections simultaneously via `PATCH /api/v1/ai/workflow-tasks/{id}/inputs` with `expected_revision`. Stale edits fail closed with HTTP 409 Conflict. Once zero blocking issues remain, the task is promoted to an authoritative, sealed `ai_action_proposals` record.

## Entity and instruction confidence
Confidence is for UI presentation and choosing whether to ask a question, **never** for skipping authorization or approval. Product matching uses deterministic source identity; if model provides 'Blue Cotton' but two SKUs exist, require human selection. Quantity/UOM/currency phrases require strict parsing, decimal representation and reviewed conversions. Locale-aware number parsing of `1,00,000` vs `100,000` must be tested; don't let an LLM reinterpret ambiguous prices.

## Multi-intent handling
"Create PO, receive items and bill supplier" is **a workflow plan** with separate child operations and gate conditions. It does not authorize execution of every step immediately. Persist an explicit DAG: order → receipt (after actual goods confirmation) → bill (after invoice validation), with accounting effects from domain services. A user may cancel remaining steps while preserving already posted records.

## Test corpus
Exact/alias/fuzzy; same-name cross-tenant; inactive/different warehouse; missing UOM; negative/infinite/overflow quantity; rate with misplaced decimal; ambiguous tax; Tamil transliteration; multi-intent compound command; unsupported write; repeated clarification; switched company; altered source record; adversarial prompt in product name. Expected outputs include intent, candidate order, required clarifications, permission decision and no unapproved side effect.


---

## V3 cross-cutting requirements
The initial company/master configuration workflow is specified in `37_GUIDED_INITIAL_SETUP_AGENT.md` with source-confirmed vs future capabilities in `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md`. **ERP Transaction Lifecycle Enforcement** in `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` is mandatory for every existing-record edit, cancellation, invoice correction, return, reversal and adjustment. A chat instruction never creates permission to modify a submitted, posted or paid record directly; proposed revisions return through fresh ERP validation and human approval. Current code remains a read-only Copilot pending separate action implementation.
