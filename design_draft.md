# zoloERP AI Operating Layer — Generalized Multi-Slot Input Collection & Clarification Engine (Phase 2.1)

*   **Author:** vigneshsinna
*   **Status:** Draft (In Progress)
*   **Date:** 2026-11-11
*   **Predecessor Documents:** `docs/zoloerp_ai_operating_layer_complete_docs_v3/ZOLOERP_PHASE_2_1_UNIFIED_INPUT_COLLECTION_AND_CLARIFICATION_ENGINE.md`, `concepts_brief.md`
*   **Target Codebase:** `vigneshsinna/zolo-erp-pro` (`feature/ai-unified-input-clarification`)

--------------------------------------------------------------------------------

## Overview

This technical design document specifies the architecture, data models, state transitions, and user experience for the **Generalized Multi-Slot Input Collection & Clarification Engine (Phase 2.1)** within the zoloERP AI Operating Layer.

While Phase 2 successfully delivered the governed commercial action foundation and operational Purchase Order workflow (`purchase_order.create.v1`), natural-language interactions remained bounded by early-return validation: the system stops at the first missing or ambiguous reference (e.g., supplier), triggering repetitive conversational back-and-forth loops before downstream fields (such as products, quantities, prices, or warehouses) are ever inspected.

Phase 2.1 solves this by introducing a **centralized pre-proposal task orchestration layer** that operates universally across:
1. **Commercial Sales & Collections:** Sales Orders (`sales_order.create.v1`), Quotations (`quotation.create.v1`), Sales Invoices (`sales_invoice.create.v1`), and Customer Payments (`payment.create.v1`).
2. **Commercial Purchasing & Inbound:** Purchase Orders (`purchase_order.create.v1`), Goods Receipt Notes (`grn.create.v1`), and Supplier Bills (`purchase_bill.create.v1`).
3. **Inventory & Manufacturing:** Stock Transfers (`stock_transfer.create.v1`), Stock Adjustments (`stock_adjustment.create.v1`), and Production Orders (`production_order.create.v1`).
4. **Mode B Master Setup:** Product Master (`setup.product.create.v1`), Customer Master (`setup.customer.create.v1`), Supplier Master (`setup.supplier.create.v1`), Warehouse (`setup.warehouse.create.v1`), and Units of Measure (`setup.unit.create.v1`).
5. **Multi-Modal Intake Surfaces:** Natural-language chat, transcribed voice notes, and document/bill OCR image extractions.

The architecture strictly upholds the core invariant: **Untrusted Model, Authoritative Server**. The Large Language Model performs probabilistic intent extraction and slot tagging. A deterministic, server-side requirements evaluator (`ActionRequirementsRegistry`, `InputCompletenessEvaluator`) and company-scoped batch resolvers (`BatchRecordResolutionService`) evaluate entity validity, applicability, and completeness. Incomplete requests are retained as durable task drafts (`ai_workflow_tasks`), presenting a single, consolidated **"Details Required"** clarification interface. Only when all blocking issues are resolved is a typed, immutable Action Proposal (`ai_action_proposals`) generated for human review and authoritative execution.

---

## The Problem

zoloERP Pro operators interacting via conversational, voice, or document inputs encounter four operational bottlenecks when attempting multi-slot commercial or operational entries:

1. **Stop-at-First-Error Conversational Bottleneck:**
   Existing action tools (such as `PreparePurchaseOrder`) execute sequential entity lookups and return immediately upon the first failure (`status: needs_input`). For example, in the prompt *"Create a sales order for 50 rolls of Cotton Poplin and 20 rolls of Denim Fabric for Arvind Garments to Main Warehouse"*, if `Arvind Garments` is ambiguous, the tool returns a question regarding the customer without inspecting either fabric line or the warehouse. When the user resolves the customer, the system asks about the fabric lines, creating an inefficient multi-turn interaction.

2. **Multi-Line Evaluation Blindness:**
   Commercial orders in manufacturing, wholesale, and retail routinely contain 5 to 50 line items. Stopping at line 1 leaves errors on lines 2 through 10 undiscovered until the user fixes line 1. Users require a complete diagnostic report of every line item in a single response.

3. **Absence of Durable Pre-Proposal Task State:**
   Prior to Phase 2.1, the system only possessed two states: conversational chat messages in `ai_messages` or sealed, reviewable proposals in `ai_action_proposals`. There was no intermediate, durable storage for incomplete drafts. Consequently, partial extractions were lost across page reloads, multi-step corrections, or background task pauses. Attempting to store incomplete inputs in `ai_action_proposals` breaks the cryptographic approval and immutability invariants of the proposal engine.

4. **Risk of Silent Entity Creation vs. Missing Data Deadlock:**
   When an input references an unknown product, customer, or unit, naive systems either hallucinate new database records (violating ERP data integrity) or halt indefinitely. Operators require grounded disambiguation: selectable choices for existing records, or an explicit, governed pathway to trigger a separate, approved master-setup workflow without losing their active transaction draft.

---

## Goals

*   **G-1 (Universal Cross-Module Applicability):** Define a unified requirements and clarification pipeline that serves Sales, Purchasing, Inventory, Accounting, and Master Setup identically, eliminating module-specific ad-hoc validation loops.
*   **G-2 (Single-Pass Full Evaluation):** Resolve all independently addressable entities (parties, products across all lines, warehouses, accounts, tax profiles) in a single company-scoped batch pass (`BatchRecordResolutionService`).
*   **G-3 (Consolidated Clarification Response):** Return all currently identifiable missing, invalid, and ambiguous fields in a single, structured payload and render them as a consolidated "Details Required" card in the AI Workspace desk UI.
*   **G-4 (Durable Task Draft Persistence & Concurrency):** Persist incomplete workflow task state in `ai_workflow_tasks` with append-only auditing in `ai_workflow_task_events` and monotonic optimistic concurrency locking (`expected_revision`), preventing race conditions and lost user edits.
*   **G-5 (Strict Lifecycle Separation):** Completely decouple mutable task drafts (`ai_workflow_tasks`) from immutable, reviewable action proposals (`ai_action_proposals`). Promotion to a proposal occurs strictly when zero blocking issues remain.
*   **G-6 (Multi-Modal Provenance Tracking):** Maintain explicit per-field provenance (user text, voice transcript, document OCR extraction, verified ERP default, or explicit user selection) to support targeted disambiguation.
*   **G-7 (Zero Autonomous Writes & Authoritative Validation):** Preserve strict separation of concerns: input collection performs zero ERP master or financial ledger mutations. Financial previews and execution remain exclusively governed by authoritative domain services (`CommercialPricing`, `CommercialApplicationService`).

---

## Non-Goals

*   **NG-1 (No Silent Master Creation):** The clarification engine will never automatically insert missing customers, suppliers, products, or warehouses. Missing records must be selected from existing matches or routed through an independent, approved setup action.
*   **NG-2 (No LLM-Driven Financial Calculations):** The clarification engine will not permit the model to calculate subtotals, tax splits, discount allocations, or grand totals. All financial metrics are computed by domain pricing services once inputs are complete.
*   **NG-3 (No Client-Controlled Scoping):** The clarification engine will never accept `company_id`, `branch_id`, `financial_year_id`, or `actor_id` from client payloads or LLM extractions. All scoping resolves server-side via `CompanyContextResolver`.
*   **NG-4 (No Execution Bypass):** A fully resolved task draft does not automatically execute against the ERP. It generates an Action Proposal that requires explicit human review and authorization before domain execution.

---

## Open Questions & Decisions

*   **Q-1: How should line items be addressed across incremental user corrections?**
    *   *Decision:* Line items must use stable client-issued identifiers (`client_line_id`, e.g., `line-1`, `line-2`, or UUIDs) rather than sequential array indices (`items[0]`). Array indices are fragile when users insert, delete, or re-order lines during clarification. All field issue paths must reference `items[client_line_id].field_name`.
*   **Q-2: What is the retention and expiration policy for incomplete task drafts?**
    *   *Decision:* Active workflow tasks in `ai_workflow_tasks` expire after 24 hours of inactivity (`expires_at = now() + 24 hours`). Expired tasks cannot be converted into proposals; the user must refresh or initiate a new task. Completed tasks (`status = 'proposal_created'`) are retained for 30 days for audit and correlation before archiving.
*   **Q-3: How does the system handle multi-modal extractions with low OCR/voice confidence?**
    *   *Decision:* Extracted values with model confidence below threshold (or lacking exact database match) are marked as `unverified` or `ambiguous` rather than `resolved`. They are presented to the operator in the "Details Required" card with explicit confirmation radio buttons or candidate pickers before promotion.

---

## Critical User Journeys (CUJs)

### CUJ 1: Multi-Line Commercial Sales Order Input Collection
*   **Given:** An authenticated sales representative in Company Context (Company 1, Main Branch, FY 2026-2027).
*   **When:** The user enters: *"Book a sales order for Arvind Garments: 50 rolls of Cotton Poplin at ₹450/roll and 25 rolls of Indigo Denim at ₹850/roll to delivery warehouse WH-SOUTH."*
*   **Then:**
    1. Intent extractor identifies `sales_order.create.v1` and extracts customer query, 2 line items, and warehouse query.
    2. `ActionRequirementsRegistry` loads the schema for `sales_order.create.v1`.
    3. `WorkflowTaskStateService` creates a task draft (`status = 'collecting'`, `revision = 1`).
    4. `BatchRecordResolutionService` executes company-scoped parallel lookups:
       - Customer: `Arvind Garments` matches 1 unique active customer record (ID 12).
       - Warehouse: `WH-SOUTH` matches 1 unique warehouse in the branch (ID 4).
       - Line 1: `Cotton Poplin` matches 2 active SKUs (`POP-COT-60`, `POP-COT-80`).
       - Line 2: `Indigo Denim` matches 1 unique active SKU (`DEN-IND-12OZ`, ID 108).
    5. `InputCompletenessEvaluator` flags Line 1 product as `ambiguous` and detects missing shipping date.
    6. System outputs one consolidated "Details Required" card displaying:
       - Confirmed: Customer (Arvind Garments), Warehouse (WH-SOUTH), Line 2 (Indigo Denim, 25 rolls @ ₹850).
       - Ambiguous: Line 1 product candidates (`POP-COT-60` vs `POP-COT-80`).
       - Missing: Expected Delivery Date.
    7. User selects `POP-COT-60` and enters date `2026-11-20`.
    8. `TaskCorrectionService` applies patch (`expected_revision = 1`), re-evaluates, finds 0 blocking issues, and promotes the task to an Action Proposal (`sales_order.create.v1`) with domain-calculated pricing and tax breakdown.

### CUJ 2: Purchase Order with Ambiguous Supplier and Unregistered Product
*   **Given:** A purchasing officer preparing a commercial order.
*   **When:** The user enters: *"Create PO for 100 kgs of Raw Chemical Solvent from Apex Industries at ₹120/kg."*
*   **Then:**
    1. Intent extractor identifies `purchase_order.create.v1`.
    2. `BatchRecordResolutionService` checks company masters:
       - Supplier: `Apex Industries` matches two active suppliers in different states (`Apex Industries PB` vs `Apex Industries HR`).
       - Product: `Raw Chemical Solvent` returns zero matches in the product catalog.
    3. `InputCompletenessEvaluator` flags Supplier as `ambiguous` and Product as `missing`.
    4. System returns a single consolidated card:
       - Supplier: Select between `Apex Industries PB (GSTIN: 03AAAAA1234A1Z5)` or `Apex Industries HR (GSTIN: 06AAAAA1234A1Z5)`.
       - Product: No catalog match found. Options: "Search catalog with different term" or "Propose Product Master Creation (setup.product.create.v1)".
    5. The active purchase order task remains safely persisted in `ai_workflow_tasks` while the user clarifies the supplier or triggers the separate setup workflow.

### CUJ 3: Multi-Modal Document OCR Bill Ingestion
*   **Given:** An accounts payable clerk uploads a scanned invoice image from a vendor.
*   **When:** The system processes the image through OCR extraction.
*   **Then:**
    1. Vision extractor identifies `purchase_bill.create.v1` and populates invoice number, invoice date, supplier name, 4 line items, and tax totals.
    2. `BatchRecordResolutionService` resolves the supplier and matches 3 of the 4 items to existing master SKUs.
    3. Item 4 fails resolution due to an abbreviated description on the invoice.
    4. The clarification engine generates a "Details Required" card highlighting the single unmatched line, showing auto-suggested close matches based on HSN code and vendor purchase history.
    5. Once the clerk maps the 4th item, authoritative ERP validation verifies invoice totals against tax breakdown rules and creates the formal `purchase_bill.create.v1` proposal.


---

## Technical Plan (Architecture)

### 1. High-Level Architecture & End-to-End Pipeline

The Generalized Multi-Slot Input Collection & Clarification Engine operates as a deterministic pre-proposal orchestration pipeline. It sits directly between user input surfaces and the authoritative Action Foundation layer.

```text
+----------------------------------------------------------------------------------------------------+
|                                     MULTI-MODAL INTAKE LAYER                                       |
|  - Natural-Language Chat (Text)                                                                    |
|  - Transcribed Voice Notes (Audio)                                                                 |
|  - Document / Invoice / Bill Scans (Vision OCR)                                                    |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                              INTENT EXTRACTION & SLOT DISCOVERY                                    |
|  - Probabilistic Model maps prompt/document to Action Key (e.g., sales_order.create.v1)            |
|  - Extracts raw candidate slot values, stable client_line_ids, and input provenance                |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                         CAPABILITY REQUIREMENTS REGISTRY & CONTEXT RESOLUTION                      |
|  - Resolves authenticated CompanyContext (Company, Main Branch, Active FY) server-side             |
|  - Loads authoritative ActionFieldRequirement rules from ActionRequirementsRegistry                |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                            WORKFLOW TASK DRAFT PERSISTENCE (ai_workflow_tasks)                     |
|  - Creates or loads active task draft: UUID, action_key, actor_id, company_id, branch_id, FY_id    |
|  - Merges incoming slots with persisted state; increments revision (optimistic locking)            |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                              BATCH RECORD RESOLUTION (SINGLE PASS)                                 |
|  - BatchRecordResolutionService resolves all independent entities concurrently:                    |
|    * Parties: Customer or Supplier (Exact ID, GSTIN, Company Name, Bounded Candidates)             |
|    * Warehouses: Active Branch Default or Explicit Name/Code                                       |
|    * Products: Evaluates ALL line items concurrently (ID, Code/SKU, Alias, Name, Barcode)          |
|    * Units: Base, Sales, or Purchase Unit mapping per line item                                    |
|    * Accounts & Tax Profiles: Validates tax registrations and nominal accounts                     |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                           INPUT COMPLETENESS & APPLICABILITY EVALUATOR                             |
|  - Evaluates each field against ActionFieldRequirement predicates and domain validators            |
|  - Classifies status: resolved, missing, invalid, ambiguous, unverified, blocked, not_applicable   |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   +--------------------------------+
                                                   |                                |
                                    Blocking Issues Remain?                         | Zero Issues
                                                   |                                | (Ready)
                                          YES      v                                v
+--------------------------------------------------+-----+   +----------------------+--------------------+
|        CONSOLIDATED CLARIFICATION RESPONSE             |   |       ACTION PROPOSAL PROMOTION            |
|  - ClarificationResponseBuilder generates single       |   |  - ActionProposalService::create()         |
|    structured payload                                  |   |  - Authoritative CommercialPricing preview |
|  - AI Workspace displays "Details Required" Fact Card: |   |  - Live SHA-256 Source State Hash          |
|    * Confirmed Fields Summary (Read-only)              |   |  - Cryptographic HMAC Approval Seal        |
|    * Ambiguous Matches (Clickable candidate pickers)   |   +----------------------+--------------------+
|    * Missing Inputs (Typed controls)                   |                          |
|    * Invalid Fields (Error explanations)               |                          v
|    * Pending Dependent Checks (Tax/total calculations) |   +--------------------------------------------+
+--------------------------+-----------------------------+   |        ACTION REVIEW CENTER & APPROVAL     |
                           |                                 |  - Interactive Desk Review & Sign-Off      |
                           v                                 +----------------------+---------------------+
+--------------------------------------------------------+                          |
|             CONSOLIDATED USER CORRECTION               |                          v
|  - User supplies batch patches in one submission       |   +--------------------------------------------+
|  - TaskCorrectionService applies patch with            |   |       AUTHORITATIVE ERP DOMAIN EXECUTION   |
|    expected_revision, cascades dependency invalidation |   |  - Short-transaction row locking           |
|  - Returns to BATCH RECORD RESOLUTION                  |   |  - Live re-validation against source state |
+--------------------------------------------------------+   |  - CommercialApplicationService execution  |
                                                             +--------------------------------------------+
```

---

### 2. Core Architectural Components & Interfaces

#### 2.1 Action Requirements Registry & Provider Contract
The registry stores code-reviewed requirements schemas authored in PHP, eliminating LLM hallucination of required fields.

```php
namespace App\Ai\Contracts;

interface ActionRequirementsProviderInterface
{
    public function actionKey(): string;

    /**
     * @return list<ActionFieldRequirement>
     */
    public function requirements(WorkflowTaskContext $context): array;

    /**
     * Deterministic, authoritative domain validation without database mutation.
     *
     * @param array<string, mixed> $resolvedInputs
     * @return array<string, string> Field errors keyed by stable field path.
     */
    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array;
}
```

The `ActionRequirementsRegistry` manages providers across all ERP domains:
*   **Purchasing:** `PurchaseOrderRequirementsProvider` (`purchase_order.create.v1`), `PurchaseBillRequirementsProvider` (`purchase_bill.create.v1`).
*   **Sales & Commercial:** `SalesOrderRequirementsProvider` (`sales_order.create.v1`), `QuotationRequirementsProvider` (`quotation.create.v1`), `SalesInvoiceRequirementsProvider` (`sales_invoice.create.v1`).
*   **Inventory:** `StockTransferRequirementsProvider` (`stock_transfer.create.v1`), `StockAdjustmentRequirementsProvider` (`stock_adjustment.create.v1`).
*   **Master Setup (Mode B):** `ProductSetupRequirementsProvider` (`setup.product.create.v1`), `CustomerSetupRequirementsProvider` (`setup.customer.create.v1`), `SupplierSetupRequirementsProvider` (`setup.supplier.create.v1`), `WarehouseSetupRequirementsProvider` (`setup.warehouse.create.v1`), `UnitSetupRequirementsProvider` (`setup.unit.create.v1`).

#### 2.2 Action Field Requirement DTO
Each field definition encapsulates:
*   `fieldPath`: Stable identifier (e.g., `customer_id`, `warehouse_id`, `items[line-id].product_id`, `items[line-id].qty`).
*   `dataType`: `string`, `integer`, `decimal`, `date`, `boolean`, `entity_id`, `list`.
*   `requiredWhen`: Deterministic closure evaluated against current task context and previously confirmed values.
*   `allowedSources`: `['user', 'document_ocr', 'erp_default', 'user_selection']`.
*   `resolutionPolicy`: `unique_exact`, `bounded_fuzzy_candidates`, `explicit_confirmation_required`.
*   `dependencies`: Array of prerequisite field paths (e.g., `items[line-id].unit_id` depends on `items[line-id].product_id`).
*   `uiControl`: `text`, `number`, `date_picker`, `entity_picker`, `radio_group`.
*   `sensitivity`: Masking rules for audit logs.

#### 2.3 Single-Pass Batch Record Resolution Service
`BatchRecordResolutionService` executes all entity lookups concurrently within the actor's authorized `CompanyContext`:
*   **Parties (Customers & Suppliers):** Matches exact ID, normalized GSTIN/VAT, exact business name, and scoped fuzzy search. Ambiguous matches return at most 5 candidates with grounded metadata (ID, Legal Name, GSTIN, State).
*   **Products across ALL Lines:** Evaluates every line item concurrently using `ProductAliasService` and `Product::query()`. Matches exact SKU/Code, barcode, exact name, or returns candidate SKUs. Never terminates on line 1 failure.
*   **Warehouses:** Resolves explicit warehouse name/code or falls back to active branch primary warehouse.
*   **Units of Measure:** Matches explicit UOM or resolves against product's authorized sale/purchase units.

#### 2.4 Input Completeness Evaluator
The `InputCompletenessEvaluator` processes the requirements matrix, resolved entities, and domain validations. Every field receives one of eight statuses:
1.  `resolved`: Validated, unique entity confirmed.
2.  `missing`: Field is applicable but no value is supplied.
3.  `invalid`: Value fails format, range, or domain logic.
4.  `ambiguous`: Multiple valid database candidates exist; requires user disambiguation.
5.  `unverified`: Value extracted with low model confidence or unverified OCR match.
6.  `blocked_by_dependency`: Cannot evaluate until a prerequisite field is known.
7.  `not_applicable`: Field's `requiredWhen` predicate returned false.
8.  `derived`: Populated automatically by ERP business logic (e.g., branch tax registration).

A task is strictly marked `ready_for_proposal` if and only if:
`count(blocking_issues) === 0 && domain_validation_errors === []`.

#### 2.5 Workflow Task State Persistence (`ai_workflow_tasks`)
Durable storage separates mutable draft workflows from immutable action proposals:

```sql
-- AI Workflow Tasks Table
CREATE TABLE ai_workflow_tasks (
    id CHAR(36) NOT NULL PRIMARY KEY,
    action_key VARCHAR(100) NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'company',
    actor_id BIGINT UNSIGNED NOT NULL,
    thread_id VARCHAR(120) NULL,
    company_id BIGINT UNSIGNED NULL,
    branch_id BIGINT UNSIGNED NULL,
    financial_year_id BIGINT UNSIGNED NULL,
    bootstrap_task_id VARCHAR(120) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'collecting',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    slots_json JSON NOT NULL,
    resolution_json JSON NULL,
    issues_json JSON NULL,
    provenance_json JSON NULL,
    linked_proposal_id CHAR(36) NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_wf_tasks_company_actor (company_id, actor_id, status),
    INDEX idx_wf_tasks_actor_status (actor_id, status),
    INDEX idx_wf_tasks_thread (thread_id),
    INDEX idx_wf_tasks_proposal (linked_proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI Workflow Task Events (Append-Only Audit)
CREATE TABLE ai_workflow_task_events (
    id CHAR(36) NOT NULL PRIMARY KEY,
    task_id CHAR(36) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    patch_json JSON NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wf_task_events_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 2.6 Optimistic Concurrency & Task Correction Service
To prevent race conditions during collaborative or multi-turn sessions:
*   Every patch submission must supply `expected_revision`.
*   `TaskCorrectionService::applyPatch()` executes an atomic conditional update:
    `UPDATE ai_workflow_tasks SET revision = revision + 1, ... WHERE id = :id AND revision = :expected_revision`.
*   If stale, the server aborts with HTTP `409 Conflict`, returning the latest task revision and current state.
*   **Dependency Invalidation:** When a user updates a root field, dependent slots are automatically invalidated:
    *   Changing Customer/Supplier → Invalidate tax determinations, credit checks, pricing tiers, and currency.
    *   Changing Product → Invalidate line UOM, tax rate, discount, and unit cost.
    *   Changing Warehouse → Invalidate branch stock checks and delivery logistics.

---

### 3. REST API Contract

All company-scoped endpoints are gated by `auth:sanctum`, `company.context`, and `ai.use` permissions.

| Method | Endpoint | Description & Access Control |
|---|---|---|
| `POST` | `/api/v1/ai/workflow-tasks` | Initiates a new task from extracted intent or explicit action key. |
| `GET` | `/api/v1/ai/workflow-tasks/{id}` | Returns current task state, verified fields, candidates, and blocking issues. Scoped strictly to task owner. |
| `PATCH` | `/api/v1/ai/workflow-tasks/{id}/inputs` | Submits batch corrections with `expected_revision`. Re-evaluates all fields and returns updated status. |
| `POST` | `/api/v1/ai/workflow-tasks/{id}/validate` | Triggers authoritative re-validation without executing or mutating ERP state. |
| `POST` | `/api/v1/ai/workflow-tasks/{id}/prepare-proposal` | Promotes a `ready` task into an immutable `ai_action_proposals` record with authoritative preview. |
| `POST` | `/api/v1/ai/workflow-tasks/{id}/cancel` | Cancels active task. Performs zero ERP writes. |

#### Structured Clarification Payload (HTTP 200 / `needs_input`)
```json
{
  "status": "needs_input",
  "task_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "revision": 2,
  "action_key": "sales_order.create.v1",
  "confirmed_fields": [
    {"field": "customer_id", "label": "Customer", "display": "Arvind Garments (ID: 12)", "source": "exact_match"},
    {"field": "warehouse_id", "label": "Warehouse", "display": "Main Warehouse", "source": "branch_default"},
    {"field": "items[line-2].product_id", "label": "Line 2 Product", "display": "Indigo Denim 12oz (DEN-IND-12OZ)", "source": "exact_sku"},
    {"field": "items[line-2].qty", "label": "Line 2 Quantity", "display": "25 rolls", "source": "user"}
  ],
  "required_inputs": [
    {
      "field": "items[line-1].product_id",
      "label": "Line 1 Product",
      "status": "ambiguous",
      "reason": "Multiple products match 'Cotton Poplin'",
      "control": "entity_picker",
      "candidates": [
        {"id": 101, "code": "POP-COT-60", "label": "Cotton Poplin 60s Count", "attributes": {"unit": "roll", "stock": 140}},
        {"id": 102, "code": "POP-COT-80", "label": "Cotton Poplin 80s High Density", "attributes": {"unit": "roll", "stock": 85}}
      ]
    },
    {
      "field": "delivery_date",
      "label": "Expected Delivery Date",
      "status": "missing",
      "reason": "Delivery date is required for sales orders",
      "control": "date_picker",
      "candidates": []
    }
  ],
  "pending_checks": [
    "Authoritative GST calculation (awaiting line 1 HSN/tax category)",
    "Customer credit limit verification"
  ],
  "can_prepare_proposal": false
}
```

---

### 4. AI Workspace UI: "Details Required" Fact Card

Rather than dumping unstructured chat questions, the AI Workspace renders a responsive, high-density card:

1.  **Header**: Action title (e.g., *"Sales Order Draft — Arvind Garments"*), progress indicator (*"4 of 6 required inputs ready"*), and task revision tag.
2.  **Confirmed Details (Collapsible Section)**: Read-only summary of verified fields with green status tags and provenance badges (*"Exact Match"*, *"Branch Default"*).
3.  **Actionable Clarifications (Main Section)**:
    *   **Ambiguous Match Items**: Compact radio/pill selectors showing Code, Name, Stock, and UOM.
    *   **Missing Fields**: Native HTML inputs (`<input type="date">`, `<input type="number">`).
    *   **Invalid Fields**: Highlighted input container with inline validation message and acceptable constraints.
4.  **Pending Dependent Validations**: Informational banner explaining domain calculations that will execute once references resolve.
5.  **Control Buttons**:
    *   `[ Submit All Details ]` — Dispatches a single `PATCH` call with all selections.
    *   `[ Edit Confirmed Fields ]` — Re-opens confirmed inputs for modification.
    *   `[ Cancel Task ]` — Discards draft with zero ledger consequences.


---

## Alternatives Considered

### Alternative A: Conversational Multi-Turn LLM Loop (Early-Return / Stop-at-First-Error)

*   **Mechanism**: The LLM queries entity resolution tools sequentially. Upon encountering the first missing or ambiguous record, the tool returns `needs_input` immediately. The LLM then asks the user for that specific record in chat, receives the response, and continues to the next field in subsequent turns.
*   **Pros**:
    *   Simple tool implementation with no complex server-side requirements evaluator.
    *   Does not require pre-proposal state persistence or batch resolution logic.
*   **Cons**:
    *   Unacceptable user latency: a 5-item order with an ambiguous customer and two missing products requires 4 to 6 conversational turns to resolve.
    *   Hides downstream validation failures (e.g., line 4 product missing, warehouse stock unavailable, delivery date in closed fiscal period) until earlier lines are addressed.
    *   Substantially higher LLM token consumption due to repeated chat context replays.
*   **Reason for Rejection**:
    *   *Rejected by author*: Unacceptable user latency and repetitive turn friction. Enterprise commercial orders in manufacturing, wholesale, and retail require a single, consolidated diagnostic pass showing all missing, invalid, and ambiguous fields at once.

### Alternative B: Ephemeral In-Memory Task State (Session / Redis Cache Only)

*   **Mechanism**: Store incomplete task drafts strictly in Redis or PHP session storage without creating persistent database tables (`ai_workflow_tasks`).
*   **Pros**:
    *   Zero new database migrations.
    *   Low read/write latency and automatic key expiration via Redis TTL.
*   **Cons**:
    *   Volatile: drafts are lost on Redis eviction, server restart, session expiration, or multi-tab browser context switches.
    *   Cannot support asynchronous multi-modal pipelines (such as background OCR invoice processing or webhook document ingestion).
    *   Lacks enterprise auditability: no durable trace of how extracted values were transformed or corrected prior to proposal creation.
*   **Reason for Rejection**:
    *   *Rejected by author*: Ephemeral storage lacks durability and enterprise auditability. Asynchronous document ingestion and collaborative multi-user workflows require durable, database-backed task state with optimistic concurrency locking and append-only event auditing.

### Alternative C: Storing Incomplete Drafts in `ai_action_proposals` with Status `draft`

*   **Mechanism**: Reuse the existing `ai_action_proposals` table by adding a `draft` or `collecting` status flag for incomplete user inputs.
*   **Pros**:
    *   Reuses existing tables without introducing `ai_workflow_tasks`.
*   **Cons**:
    *   Violates the core security invariant of `ai_action_proposals`, which requires that every proposal represents an immutable, sealed, cryptographically hashed entity with an exact payload hash, source-state hash, and authoritative preview.
    *   Storing mutable, partially validated drafts in the proposals table corrupts hash validation, complicates review queues, and creates the risk of premature approval of incomplete records.
*   **Reason for Rejection**:
    *   *Rejected by author*: Violates the immutability and cryptographic sealing invariants of the Action Foundation. Proposals must remain strictly immutable and fully validated. Mutable task drafts must reside in a dedicated pre-proposal table (`ai_workflow_tasks`).

### Alternative D: Client-Driven Validation via Frontend Forms Only

*   **Mechanism**: The AI model extracts raw JSON and redirects the user to standard ERP web entry forms, leaving validation entirely to client-side JavaScript.
*   **Pros**:
    *   Reuses existing frontend form scripts.
*   **Cons**:
    *   Inapplicable for headless channels (voice notes, mobile REST APIs, background document ingestion).
    *   Fails the "Untrusted Model, Authoritative Server" architectural boundary: client code cannot authoritatively govern statutory GST determinations, customer credit limit enforcement, or cross-branch inventory locks.
*   **Reason for Rejection**:
    *   *Rejected by author*: Violates the Untrusted Model, Authoritative Server boundary. Multi-modal AI input requires headless, server-side deterministic evaluation that functions identically across web chat, mobile APIs, voice, and document pipelines.


---

## Detailed Implementation Plan

The implementation is structured into five sequential development slices. Each file change is accompanied by its explicit rationale and architectural scope.

### Slice 1: Database Schema & Core Contracts

1.  **`database/migrations/2026_11_11_000002_create_ai_workflow_tasks_tables.php`**
    *   *Rationale*: Creates additive tables `ai_workflow_tasks` and `ai_workflow_task_events`. Establishes durable storage for mutable draft workflows with foreign index keys for `company_id`, `actor_id`, `thread_id`, and `linked_proposal_id`. Enforces Tenancy Law 4 (additive only, zero mutation of historical migrations).
2.  **`app/Ai/Contracts/ActionRequirementsProviderInterface.php`**
    *   *Rationale*: Defines the interface for capability requirements providers with `actionKey()`, `requirements(WorkflowTaskContext $context)`, and `validate(WorkflowTaskContext $context, array $resolvedInputs)`.
3.  **`app/Ai/DTOs/ActionFieldRequirement.php`**
    *   *Rationale*: Readonly PHP 8.5 DTO specifying field path, data type, required-when predicate, allowed sources, resolution policy, dependencies, domain validation, UI control, and sensitivity.
4.  **`app/Ai/DTOs/WorkflowTaskContext.php`**
    *   *Rationale*: Encapsulates tenant scope (`CompanyContext`), actor ID, thread ID, task ID, current revision, and operational mode (`company` vs `bootstrap`).
5.  **`app/Ai/DTOs/FieldIssue.php`**
    *   *Rationale*: Encapsulates individual field diagnostic issues: field path, status (`missing`, `invalid`, `ambiguous`, `unverified`, `blocked_by_dependency`), user-facing label, human-readable reason, accepted format/range, and selectable candidate records.
6.  **`app/Ai/DTOs/EvaluationResult.php`**
    *   *Rationale*: Aggregates resolved values, all detected field issues, pending dependent checks, and the boolean `isReadyForProposal` flag.
7.  **`app/Ai/DTOs/BatchResolutionResult.php`**
    *   *Rationale*: Holds the resolved entity IDs, candidate match collections, and resolution statuses across parties, products, warehouses, units, and accounts.

---

### Slice 2: Requirements Registry & Domain Providers

8.  **`app/Ai/Registries/ActionRequirementsRegistry.php`**
    *   *Rationale*: Central registry managing domain providers. Discovers requirements schemas by action key and prevents arbitrary client-submitted schemas.
9.  **`app/Ai/Requirements/PurchaseOrderRequirementsProvider.php`**
    *   *Rationale*: Implements requirements definition for `purchase_order.create.v1`: validates supplier, warehouse, order date, and per-line product, quantity, unit cost, and tax rates.
10. **`app/Ai/Requirements/SalesOrderRequirementsProvider.php`**
    *   *Rationale*: Implements requirements definition for `sales_order.create.v1`: validates customer, delivery warehouse, shipping date, and per-line product, quantity, unit price, and discounts.
11. **`app/Ai/Requirements/QuotationRequirementsProvider.php`**
    *   *Rationale*: Implements requirements definition for `quotation.create.v1`: validates customer lead, validity date, payment terms, and line items.
12. **`app/Ai/Requirements/PurchaseBillRequirementsProvider.php`**
    *   *Rationale*: Implements requirements definition for `purchase_bill.create.v1`: validates supplier, vendor invoice number, invoice date, line items, and tax breakdown.
13. **`app/Ai/Requirements/ProductSetupRequirementsProvider.php`**
    *   *Rationale*: Implements requirements definition for `setup.product.create.v1`: validates product code, name, category, unit, purchase unit, sales unit, and HSN/tax category.
14. **`app/Ai/Requirements/CustomerSetupRequirementsProvider.php`**
    *   *Rationale*: Implements requirements definition for `setup.customer.create.v1`: validates customer name, group, tax registration (GSTIN), and billing address.
15. **`app/Ai/Requirements/SupplierSetupRequirementsProvider.php`**
    *   *Rationale*: Implements requirements definition for `setup.supplier.create.v1`: validates supplier name, tax registration (GSTIN), and state code.

---

### Slice 3: Batch Resolution & Evaluation Engine

16. **`app/Ai/Services/BatchRecordResolutionService.php`**
    *   *Rationale*: Implements single-pass parallel lookups across authorized company entities. Resolves parties (customers/suppliers), all line-item products concurrently, warehouses, units of measure, and nominal accounts. Limits ambiguous candidate lists to a maximum of 5 records with grounded metadata.
17. **`app/Ai/Services/InputCompletenessEvaluator.php`**
    *   *Rationale*: Evaluates all field requirements against resolved values in a single full pass. Collects all missing, invalid, and ambiguous fields into an `EvaluationResult` without returning early on the first failure.
18. **`app/Ai/Services/WorkflowTaskStateService.php`**
    *   *Rationale*: Handles `ai_workflow_tasks` table CRUD, monotonic revision increments (`revision = revision + 1`), append-only event logging in `ai_workflow_task_events`, 24-hour inactivity expiration, and task-to-proposal linking.
19. **`app/Ai/Services/TaskCorrectionService.php`**
    *   *Rationale*: Merges consolidated user patches into active task slots using stable `client_line_id`. Enforces optimistic concurrency (`expected_revision`), returning HTTP 409 Conflict on revision mismatch. Triggers cascading dependency invalidation when upstream reference fields are modified.
20. **`app/Ai/Services/ClarificationResponseBuilder.php`**
    *   *Rationale*: Constructs the structured JSON clarification response and Fact Card metadata containing confirmed fields, required inputs, selectable candidate pickers, and pending dependent checks.

---

### Slice 4: Tool Refactoring, API Endpoints & UI Components

21. **`app/Ai/Tools/PreparePurchaseOrder.php`**
    *   *Rationale*: Refactors the existing tool to delegate to `WorkflowTaskStateService`, `BatchRecordResolutionService`, and `InputCompletenessEvaluator`. Replaces the sequential early-exit return with a consolidated `needs_input` diagnostic card.
22. **`app/Ai/Tools/PrepareSalesOrder.php`**
    *   *Rationale*: New AI SDK Tool enabling natural-language sales order creation. Extracts customer, delivery warehouse, and multiple product lines, initiating an `ai_workflow_tasks` draft under `sales_order.create.v1`.
23. **`app/Http/Controllers/Api/V1/AiWorkflowTaskController.php`**
    *   *Rationale*: REST API controller exposing `/api/v1/ai/workflow-tasks/*` endpoints: task initiation, state retrieval, batch patch submission (`expected_revision`), validation probe, proposal promotion, and task cancellation. Enforces Sanctum auth, `company.context`, and ownership.
24. **`app/Http/Controllers/Api/V1/AiChatController.php`**
    *   *Rationale*: Updates chat response packaging to associate conversational turns with active `task_id` when an action tool returns `needs_input`.
25. **`app/Providers/AiServiceProvider.php`**
    *   *Rationale*: Registers `ActionRequirementsRegistry` as a singleton in the Laravel container, binds all domain requirements providers, and registers the workflow task services.
26. **`routes/api.php`**
    *   *Rationale*: Defines versioned routes under `/api/v1/ai/workflow-tasks` protected by Sanctum and `company.context` middleware.
27. **`resources/views/backend/ai/workspace.blade.php`**
    *   *Rationale*: Implements the "Details Required" Fact Card component rendering confirmed summaries, candidate selection radio pickers, native input controls, and single-click "Submit All Details" AJAX action.
28. **`public/js/ai-task-card.js`**
    *   *Rationale*: Client-side script managing interactive candidate selection, single-pass patch compilation, optimistic revision tracking, and 409 conflict handling.

---

### Slice 5: Automated Verification Test Suites

29. **`tests/Feature/Ai/TaskEngine/WorkflowTaskStateTest.php`**
    *   *Rationale*: Verifies task creation, optimistic revision locking (HTTP 409 on stale revision), 24-hour expiration, and append-only event trail recording in `ai_workflow_task_events`.
30. **`tests/Feature/Ai/TaskEngine/BatchRecordResolutionTest.php`**
    *   *Rationale*: Verifies single-pass parallel resolution for customers, suppliers, multi-line products, UOMs, and warehouses. Proves candidate lists are bounded to 5 items and cross-company records are rejected.
31. **`tests/Feature/Ai/TaskEngine/InputCompletenessEvaluatorTest.php`**
    *   *Rationale*: Verifies that the evaluator checks all fields concurrently, reports multiple line-item errors in a single pass, and gates proposal promotion strictly until zero blocking issues remain.
32. **`tests/Feature/Ai/Commercial/MultiSlotSalesOrderWorkflowTest.php`**
    *   *Rationale*: End-to-end integration test verifying sales order natural-language intake, multi-line disambiguation, single-pass correction submission, proposal promotion, and authoritative execution.
33. **`tests/Feature/Ai/Commercial/MultiSlotPurchaseOrderWorkflowTest.php`**
    *   *Rationale*: End-to-end integration test verifying purchase order intake, ambiguous supplier and product candidate selection, and promotion to `purchase_order.create.v1`.

---

### Slice 6: Repository Master Architecture Documentation Updates

34. **`AGENTS.md`**
    *   *Rationale*: Updates root master index: splices Phase 2.1 into Phased AI Roadmap diagram (`PH2 -> PH2.1 -> PH3`) and adds stories `AI-025` through `AI-029` to the delivery catalog.
35. **`SESSION_MEMORY.md`**
    *   *Rationale*: Records Phase 2 completion (109/109 tests green) and establishes Phase 2.1 on `feature/ai-unified-input-clarification` as the active operational development track.
36. **`docs/zoloerp-ai-agent-implementation/docs/ai/03_ARCHITECTURE.md`**
    *   *Rationale*: Updates architecture blueprint to document the Pre-Proposal Task Orchestration Layer between the Model/Chat Gateway and the Action Foundation.
37. **`docs/zoloerp-ai-agent-implementation/docs/ai/06_DATABASE_SCHEMA.md`**
    *   *Rationale*: Adds DDL and indexing specifications for `ai_workflow_tasks` and `ai_workflow_task_events`.
38. **`docs/zoloerp-ai-agent-implementation/docs/ai/20_DELIVERY_BACKLOG.md`**
    *   *Rationale*: Inserts Phase 2.1 section with deliverables `UC-001` through `UC-007` and updated dependency gates.
39. **`docs/zoloerp-ai-agent-implementation/docs/ai/27_INTENT_AND_RECORD_RESOLUTION.md`**
    *   *Rationale*: Expands record resolution documentation to specify `BatchRecordResolutionService` multi-line lookups and candidate bounding.
40. **`docs/zoloerp-ai-agent-implementation/docs/ai/29_WORKFLOW_ORCHESTRATION.md`**
    *   *Rationale*: Documents task state machine (`collecting` -> `validating` -> `ready` -> `proposal_created`) and human change request loop-back paths.
41. **`docs/zoloerp-ai-agent-implementation/docs/ai/30_PURCHASE_WORKFLOWS.md`**
    *   *Rationale*: Updates purchase order workflow from early-return stop to consolidated batch clarification.
42. **`docs/zoloerp-ai-agent-implementation/docs/ai/31_SALES_AND_COLLECTION_WORKFLOWS.md`**
    *   *Rationale*: Updates sales order and quotation workflows to incorporate the multi-slot input collection engine.
43. **`docs/zoloerp-ai-agent-implementation/docs/ai/41_UNIFIED_INPUT_COLLECTION_AND_CLARIFICATION.md`**
    *   *Rationale*: Permanent specification document incorporating the complete Phase 2.1 architecture and contract suite into the repository documentation library.
