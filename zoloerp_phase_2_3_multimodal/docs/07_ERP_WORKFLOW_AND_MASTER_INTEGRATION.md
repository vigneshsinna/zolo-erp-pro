# 07 — Integration with Phase 2.1 Clarification, Phase 2.2 Masters, and Governed ERP Actions

## Business goal

The user should get the same governed outcome regardless of whether they type a request, speak it or send a bill photograph. **Perception is just another source of candidate fields**. It must not bypass record resolution, required-field collection, lifecycle policies, financial calculations or approval.

## Current and proposed boundaries

| Phase | Status at inspected branch | Phase 2.3 dependency |
|---|---|---|
| Foundation | Existing action registry, immutable proposals, approvals, execution | Reuse without changes to authorization contract |
| Guided setup | Setup and bootstrap action adapters exist | Optional post-company master setup; never let media endpoint create initial company |
| Purchase Order | `PreparePurchaseOrder`, `RecordResolutionService`, PO adapters exist | Use after normalized task and resolved slots |
| 2.1 consolidated clarification | Specification imported; existence of complete engine **not assumed** | Implement normalized task handoff once ready |
| 2.2 dependent-master resumption | Proposed concept; **not assumed implemented** | Future workflow handoff for missing supplier/product/warehouse |
| 2.3 media perception | New feature, not yet implemented | Generates normalized input and provenance |

## Requirements reconciliation

For each action, retrieve code-reviewed server-side requirements from an action requirements provider; do not ask Omni to list mandatory fields. Determine applicable requirements from actual current slots: `requirements(context, currentSlots)`. Resolve every independent entity in one pass or bounded set-based queries. Accumulate all currently identifiable missing/ambiguous fields rather than returning at the first error; defer only genuinely dependent validations.

**Example:** photo shows “ABC Traders, Cotton Shirt Blue, 10 PCS” but ERP cannot resolve the supplier, item or branch warehouse. The UI asks for all three in one card. It does **not** fabricate IDs or silently create them. User may select existing, correct name or start a separately approved master-creation subtask.

## Dependent master creation (Phase 2.2)

1. Detect missing or ambiguous ERP master in a normal action task.
2. Offer choices `select_existing`, `correct_input`, `create_new` (only if permitted), or `cancel`.
3. If `create_new`, instantiate a **separate** governed setup action (`setup.supplier.create.v1`, `setup.product.create.v1`, `setup.warehouse.create.v1`, etc.), collect mandatory details and resolve tenant context.
4. Show its *own* deterministic preview and require separately authenticated approval.
5. Execute through approved setup domain service and idempotency check; obtain returned ERP master ID.
6. Link the new master ID back to the parent task with source `verified_erp`, invalidate downstream derived defaults and rerun requirements/ERP checks.
7. Display original Purchase Order preview and obtain **a new, separate order approval**. A parent task may need more than one authorized approver; never grant privileges merely because the user controls the AI chat.

**No hidden writes:** even a “simple supplier setup” cannot silently execute from model inference. If product creation fails, the original task remains `waiting_on_dependency` with confirmed original quantities preserved.

## Per-action domain routing

| Target action | Authoritative operation | Special rules |
|---|---|---|
| Purchase order | Existing guarded purchase-order path through `CommercialApplicationService::create(kind='purchase', status=4)` / related order lifecycle | PO is unposted order; approval bound to exact supplier/items/cost/tax/warehouse |
| Sales order | `OrderLifecycleService::createSale()` | **Do not use** `CommercialApplicationService::create(kind='sale')` because that creates a posted Sale/Sales Invoice |
| Sales invoice | `CommercialApplicationService::create(kind='sale')`, when a separate reviewed AI action capability exists | Financial, stock and ledger impact; distinct lifecycle and approval |
| Purchase bill | Reviewed `CommercialApplicationService` purchase posting path, when a separate AI action capability exists | Tax, receipt/accounting and duplicate invoice protections |
| Product | `ProductMasterService::create()` when registered | Company-scoped UOM/category/tax and product validations |
| Company | `CompanyDirectoryService::createCompany()` **Mode A only** | Dedicated installation-admin bootstrap endpoint; no `company_id` bypass in generic tasks |
| Unit, customer, supplier, warehouse | Their reviewed domain setup services/adapters | Tenant scope, permissions, duplicate checks |
| Journal, returns, adjustments | Separate domain commands only after future review | No direct posted-record field edits |

**Important:** Phase 2.3 does not implement any of the missing action adapters above merely by extracting an invoice. Until the capability registry contains the corresponding validated action, the correct UX is “Extraction ready; this transaction type is not yet enabled for AI posting.”

## Workflow continuation after user corrections

A user can respond: “Supplier is ABC Textiles, make quantity 12, and use Warehouse 2.” Update only those fields in a versioned task; preserve other confirmed slots, invalidate affected derived tax/price/routing and rerun entity checks. If the user changes the underlying image or voice clip, generate a new extraction revision and require reconfirmation of affected fields.

## Live-state and lifecycle invariants

- Model-extracted or confirmed fields never constitute execution authorization.
- A posted or partially paid invoice cannot be directly edited by the AI; use domain-sanctioned return, credit note, reversal or adjustment actions.
- Purchase order creation rechecks supplier/product/tax/warehouse/FY and exact approved totals in the domain transaction with appropriate locks.
- Source hashes and proposal versions bind the approved action, not a stale OCR snapshot.
- `ApprovedActionExecutor` never blindly replays an uncertain financial commit; reconcile via durable domain receipt/idempotency.
- Company switches invalidate task scope and prevent cross-tenant media reuse; user can open a different company only after explicitly starting or switching to its task context.

## End-to-end invariants for mixed requests

**Image+voice:** “Here is my bill. Add two units to the first line.” Do not overwrite the bill's visible original value; mark user-requested delta separately and recalculate. If the goal is editing a *posted* document, reject direct edit and propose a formally permitted correction workflow.

**Voice+text:** Text confirmation “₹540 not ₹450” overrides uncertain transcript, subject to ERP validation. User confirmation creates a new task revision and invalidates previous proposal approval.

**Repeated uploads:** An identical image may share a processing cache only within the same tenant and permission context; it must not authorize another user to access the original media or existing business document.

## User-facing progress statuses

`Uploaded` → `Extracting` → `Review extracted details` → `Resolve missing details` → `Waiting for master approval` (optional) → `Preparing ERP preview` → `Awaiting transaction approval` → `Executed` / `Needs reconciliation` / `Cancelled`.

The AI must accurately identify which stage is pending; it should not say “Order created” when only extraction or a pending proposal exists.
