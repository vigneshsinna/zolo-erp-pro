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

## Record resolution stages
1. Server derives scope and allowed capability set.
2. Normalize user phrase (trim, case, Unicode, local aliases), preserving original user input.
3. Query authorized exact IDs/codes/reference numbers first, then reviewed aliases, then fuzzy candidates with strict limit and ranking.
4. Include distinguishing fields: product SKU, UOM, variant, batch, warehouse; customer/supplier code, branch/region; document number/date; show only permitted data.
5. If no match: ask whether user means a different record or wants to create new master (separate permission/flow). If multiple high-ranked candidates: ask to select explicitly; don't silently choose on LLM confidence.
6. Validate selected record under current company/branch/FY and check active state; save candidate ID + type + source version + user-confirmed flag; recheck at proposal/execute.

## Required slots per command
For each action define required, defaultable, optional and conditional slots in a schema. Example PO: supplier; requested date, branch and FY resolved server-side; warehouse/delivery site; line item product ID, quantity, UOM; agreed prices/discount, GST metadata where applicable; delivery terms/expected date if ERP requires them. Distinguish 'missing but safely defaultable under ERP policy' from 'must ask'. Any legal/tax/financial required values demand domain validation even if inferred.

## Clarification policy
Ask one concise question covering the next blocking ambiguity, or show an editable form when several fields are missing. Persist what was already confirmed and its provenance. If user says "change quantity to 75" target the selected task line, not an unrelated past invoice. A confirmed supplier ID is sticky only while company/branch scope and record version remain valid. Support undo/edit and show summary of accumulated choices.

## Entity and instruction confidence
Confidence is for UI presentation and choosing whether to ask a question, **never** for skipping authorization or approval. Product matching uses deterministic source identity; if model provides 'Blue Cotton' but two SKUs exist, require human selection. Quantity/UOM/currency phrases require strict parsing, decimal representation and reviewed conversions. Locale-aware number parsing of `1,00,000` vs `100,000` must be tested; don't let an LLM reinterpret ambiguous prices.

## Multi-intent handling
"Create PO, receive items and bill supplier" is **a workflow plan** with separate child operations and gate conditions. It does not authorize execution of every step immediately. Persist an explicit DAG: order → receipt (after actual goods confirmation) → bill (after invoice validation), with accounting effects from domain services. A user may cancel remaining steps while preserving already posted records.

## Test corpus
Exact/alias/fuzzy; same-name cross-tenant; inactive/different warehouse; missing UOM; negative/infinite/overflow quantity; rate with misplaced decimal; ambiguous tax; Tamil transliteration; multi-intent compound command; unsupported write; repeated clarification; switched company; altered source record; adversarial prompt in product name. Expected outputs include intent, candidate order, required clarifications, permission decision and no unapproved side effect.


---

## V3 cross-cutting requirements
The initial company/master configuration workflow is specified in `37_GUIDED_INITIAL_SETUP_AGENT.md` with source-confirmed vs future capabilities in `39_INITIAL_SETUP_CAPABILITY_AND_TEST_MATRIX.md`. **ERP Transaction Lifecycle Enforcement** in `38_TRANSACTION_LIFECYCLE_ENFORCEMENT.md` is mandatory for every existing-record edit, cancellation, invoice correction, return, reversal and adjustment. A chat instruction never creates permission to modify a submitted, posted or paid record directly; proposed revisions return through fresh ERP validation and human approval. Current code remains a read-only Copilot pending separate action implementation.
