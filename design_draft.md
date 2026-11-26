# zoloERP AI Operating Layer — Generalized Multi-Slot Input Collection & Clarification Engine (Phase 2.1)

*   **Author:** vigneshsinna
*   **Status:** In Review (EGM Remediated)
*   **Date:** 2026-11-11
*   **Predecessor Documents:** `docs/zoloerp_ai_operating_layer_complete_docs_v3/ZOLOERP_PHASE_2_1_UNIFIED_INPUT_COLLECTION_AND_CLARIFICATION_ENGINE.md`, `concepts_brief.md`, `consolidated_review_report.md`
*   **Target Codebase:** `vigneshsinna/zolo-erp-pro` (`feature/ai-unified-input-clarification`)

---

## Overview

This technical design document specifies the architecture, data models, state transitions, and user experience for the **Generalized Multi-Slot Input Collection & Clarification Engine (Phase 2.1)** within the zoloERP AI Operating Layer.

While Phase 2 delivered the governed commercial action foundation and operational Purchase Order capability (`purchase_order.create.v1`), natural-language interactions remain bounded by early-return validation: the existing `PreparePurchaseOrder` tool stops at the first unresolved supplier or warehouse, returning immediately with `status: needs_input` before downstream products, quantities, prices, or line items are inspected.

Phase 2.1 eliminates this bottleneck by introducing a deterministic pre-proposal task collection engine. The engine evaluates all identifiable required, optional, and conditional inputs in a single batch pass, persists incomplete inputs in a mutable draft table (`ai_workflow_tasks`), and returns a consolidated **"Details Required"** clarification card in the AI Workspace.

### Scope & Phasing
1. **Primary Operational Vertical Slice (Phase 2.1 Release Target):**
   - Refactor `PreparePurchaseOrder` to eliminate the early-return failure pattern.
   - Deliver full-pass batch resolution and multi-line clarification for Purchase Orders (`purchase_order.create.v1`).
2. **Generalized Engine Extensibility (Immediate Successors):**
   - Shared architecture applies identically to Sales Orders (`sales_order.create.v1`), Quotations (`quotation.create.v1`), Purchase Bills (`purchase_bill.create.v1`), and Master Setup (`setup.product.create.v1`).
   - Subsequent modules plug into the engine by registering their own `ActionRequirementsProviderInterface` without modifying the core state machine.

The architecture strictly maintains the foundational invariant: **Untrusted Model, Authoritative Server**. The Large Language Model extracts intent and slot candidates; server-side requirements providers and ERP domain services authoritatively decide entity validity, pricing, tax calculations, and execution.

---

## The Problem

zoloERP Pro operators entering commercial documents via chat, pasted text, or structured instructions face four operational limitations:

1. **Stop-at-First-Error Bottleneck:**
   In `PreparePurchaseOrder.php`, if a supplier lookup returns no exact match, the tool immediately outputs:
   ```php
   if ($supplierRes['status'] === 'none') {
       return json_encode([
           'status' => 'needs_input',
           'missing_field' => 'supplier',
           // ...
       ]);
   }
   ```
   The warehouse and product lines remain uninspected. If the user clarifies the supplier in the next turn, the tool halts again on the warehouse, and then on ambiguous products, forcing 3 to 5 repetitive question-and-answer turns.

2. **Incomplete Multi-Line Diagnostic Evaluation:**
   Commercial orders in manufacturing, wholesale, and retail frequently contain 5 to 50 line items. Halting on line 1 conceals errors on lines 2 through 10. Operators require a complete diagnostic report of every line item in a single response.

3. **Absence of Durable Pre-Proposal Task State:**
   Prior to Phase 2.1, system state existed only as conversational messages in `ai_messages` or sealed proposals in `ai_action_proposals`. There was no intermediate, durable storage for incomplete drafts. Storing incomplete inputs in `ai_action_proposals` violates the cryptographic sealing and immutability invariants of the Action Foundation.

4. **Entity Disambiguation vs. Master Data Integrity:**
   When an input references an unknown product, customer, or unit, systems without explicit entity gates either create unverified database records (corrupting master data) or halt without actionable feedback. Operators require grounded disambiguation: selectable choices for existing records, or an explicit pathway to trigger a separate, approved master-setup workflow without losing their active draft.

---

## Goals

*   **G-1 (Consolidated Diagnostic Clarification):** Return all currently identifiable missing, invalid, and ambiguous fields across an entire document (including all line items) in a single structured payload and UI card.
*   **G-2 (Batched Record Resolution):** Resolve independently addressable entities (parties, products across all lines, warehouses, units) in a single batched pass (`BatchRecordResolutionService`) using set-based SQL `WHERE IN` queries and dirty-slot memoization.
*   **G-3 (Strict Tenancy Isolation):** Enforce `company_id BIGINT UNSIGNED NOT NULL` on all commercial workflow tasks. Compound every query with `WHERE id = :id AND company_id = :company_id AND actor_id = :actor_id`.
*   **G-4 (Task Draft Persistence & Concurrency):** Persist incomplete workflow drafts in `ai_workflow_tasks` with optimistic concurrency locking (`expected_revision`), rejecting stale submissions with HTTP 409 Conflict.
*   **G-5 (Strict Lifecycle Separation):** Decouple mutable task drafts (`ai_workflow_tasks`) from immutable action proposals (`ai_action_proposals`). Promotion to a proposal occurs only when zero blocking issues remain.
*   **G-6 (Atomic Proposal Promotion):** Enforce pessimistic row locking and atomic state transition (`status = 'proposal_created'`) during `/prepare-proposal`, sealing the task against post-promotion mutations (HTTP 423 Locked).
*   **G-7 (Authoritative Domain Delegation):** Route completed proposals to authoritative domain services: Purchase Orders to `CommercialApplicationService::create('purchase')` with status 4; Sales Orders to `OrderLifecycleService::createSale()`. Perform zero ledger mutations during input collection.

---

## Non-Goals

*   **NG-1 (No Silent Master Creation):** The clarification engine will never automatically insert missing customers, suppliers, products, or warehouses. Missing records must be selected from existing matches or routed through an independent, approved setup action.
*   **NG-2 (No LLM-Driven Financial Calculations):** The model will not compute line subtotals, tax splits, or grand totals. All financial metrics are computed by `CommercialPricing` once inputs are complete.
*   **NG-3 (No Shared Table for Bootstrap Tasks):** Mode A Pre-Company Bootstrap tasks will not share `ai_workflow_tasks`. Bootstrap remains isolated under `ai_bootstrap_tasks` and `/api/v1/ai/setup/bootstrap/*`, preserving non-null `company_id` on commercial tasks.
*   **NG-4 (No Speculative Multi-Modal Dependencies in Phase 2.1):** Phase 2.1 focuses strictly on text-based multi-slot commercial collection (chat, pasted text). OCR bill extraction and audio transcription are reserved for Phase 3 after dedicated vision packages are integrated.

---

## Open Questions & Decisions

*   **Q-1: How are repeating line items addressed and mutated?**
    *   *Decision:* Line items use stable client identifiers (`client_line_id`, e.g., `line-1`, `line-2`, or UUIDs). Addressing follows `items[client_line_id].field_name`. Line additions pass new `client_line_id`s; modifications update specific field paths; deletions supply `_destroy: true`.
*   **Q-2: What is the retention and expiration lifecycle for task drafts?**
    *   *Decision:* Workflow tasks expire after 24 hours of inactivity (`config('ai_workflow.task_ttl_hours', 24)`). Patching or validating an expired task returns HTTP 410 Gone. Promoted tasks (`proposal_created`) are retained for 30 days for correlation.
*   **Q-3: Should ephemeral event sourcing (`ai_workflow_task_events`) be implemented in Phase 2.1?**
    *   *Decision:* No. Storing an append-only event trail of every intermediate keystroke for a transient 24-hour draft adds unnecessary database writes and schema weight. The mutable `ai_workflow_tasks` table is sufficient. Formal, tamper-proof auditing is handled exclusively at the proposal and execution stages (`ai_action_proposals`, `ai_action_executions`).

---

## Critical User Journeys (CUJs)

### CUJ 1: Purchase Order with Ambiguous Supplier and Multi-Line Products (Phase 2.1 Primary Slice)
*   **Given:** An authenticated purchasing officer in Company Context (Company 1, Branch 1, FY 2026-2027).
*   **When:** The user enters: *"Create PO for 100 kgs of Raw Polymer at ₹120/kg and 50 rolls of Packing Film at ₹80/roll from Apex Industries to Main Warehouse."*
*   **Then:**
    1. `PreparePurchaseOrder` extracts `purchase_order.create.v1`, supplier query, warehouse query, and two product lines.
    2. `BatchRecordResolutionService` executes batched, company-scoped queries:
       - Supplier: `Apex Industries` matches two active suppliers (`Apex Industries PB` vs `Apex Industries HR`).
       - Warehouse: `Main Warehouse` matches active branch warehouse ID 1.
       - Line 1: `Raw Polymer` matches 1 unique active SKU (`POL-RAW-01`, ID 45).
       - Line 2: `Packing Film` matches 2 active SKUs (`FILM-CLR-50`, `FILM-HD-50`).
    3. `InputCompletenessEvaluator` checks all fields in one pass. It does not stop at the supplier. It flags Supplier as `ambiguous` and Line 2 Product as `ambiguous`.
    4. System returns a single consolidated "Details Required" card:
       - Confirmed: Warehouse (Main Warehouse), Line 1 (Raw Polymer, 100 kgs @ ₹120).
       - Ambiguous Supplier: Choices between `Apex Industries PB (GSTIN: 03AAAAA1234A1Z5)` and `Apex Industries HR (GSTIN: 06AAAAA1234A1Z5)`.
       - Ambiguous Line 2 Product: Choices between `FILM-CLR-50` and `FILM-HD-50`.
    5. User selects `Apex Industries PB` and `FILM-CLR-50` in one submission.
    6. System applies patch with `expected_revision = 1`, finds 0 blocking issues, and promotes the task to a governed Action Proposal (`purchase_order.create.v1`) with domain-calculated GST pricing and cryptographic hashes.

### CUJ 2: Commercial Sales Order Multi-Line Input Collection (Immediate Extension)
*   **Given:** An authenticated sales representative in active company context.
*   **When:** The user enters: *"Book sales order for Arvind Garments: 50 rolls Cotton Poplin @ ₹450 and 25 rolls Indigo Denim @ ₹850 to WH-SOUTH."*
*   **Then:**
    1. Tool initiates `sales_order.create.v1` task draft.
    2. Batch resolver resolves Customer (Arvind Garments, ID 12), Warehouse (WH-SOUTH, ID 4), Line 2 (Indigo Denim, ID 108), but finds Line 1 (Cotton Poplin) ambiguous.
    3. Completeness evaluator detects ambiguous Line 1 and missing delivery date.
    4. Consolidated card displays confirmed items, candidate picker for Line 1, and delivery date input.
    5. User resolves selections; task promotes to `sales_order.create.v1` proposal.
    6. Upon approval, system invokes `OrderLifecycleService::createSale()` to create unposted sales order (status draft/ordered), preserving inventory and ledger isolation.

---

## Technical Plan (Architecture)

### 1. High-Level Architecture & End-to-End Pipeline

```text
+----------------------------------------------------------------------------------------------------+
|                                      TEXT INTAKE LAYER                                             |
|  - Conversational Chat, Pasted Text, Structured User Commands                                      |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                              INTENT EXTRACTION & SLOT DISCOVERY                                    |
|  - AI SDK Tool (e.g., PreparePurchaseOrder) maps prompt to action key                              |
|  - Extracts raw slot values, stable client_line_ids, and user inputs                               |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                         CAPABILITY REQUIREMENTS & CONTEXT RESOLUTION                               |
|  - Resolves authenticated CompanyContext (Company, Branch, FY) server-side                         |
|  - Loads ActionFieldRequirement rules from ActionRequirementsRegistry with current draft slots     |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                            WORKFLOW TASK DRAFT PERSISTENCE (ai_workflow_tasks)                     |
|  - Creates or loads draft: UUID, action_key, actor_id, company_id (NOT NULL), branch_id, FY_id     |
|  - Tracks revision counter (1, 2, ...); enforces optimistic concurrency                            |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                              BATCH RECORD RESOLUTION (SINGLE PASS)                                 |
|  - BatchRecordResolutionService executes set-based SQL WHERE IN queries:                           |
|    * Parties: Supplier or Customer (Exact ID, GSTIN, Name, Bounded Candidates max 5)               |
|    * Warehouses: Active Branch Default or Explicit Name/Code                                       |
|    * Products: Batched query across ALL line items (WHERE code IN (...) OR barcode IN (...))        |
|    * Units: Purchase unit (PO) or Sale unit (SO) per line item                                     |
|  - Dirty-slot memoization skips previously resolved clean lines                                    |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   v
+----------------------------------------------------------------------------------------------------+
|                           INPUT COMPLETENESS & APPLICABILITY EVALUATOR                             |
|  - Evaluates each field: resolved, missing, invalid, ambiguous, derived                            |
|  - Checks domain validators without database mutation                                              |
+--------------------------------------------------+-------------------------------------------------+
                                                   |
                                                   +--------------------------------+
                                                   |                                |
                                    Blocking Issues Remain?                         | Zero Issues
                                                   |                                | (Ready)
                                          YES      v                                v
+--------------------------------------------------+-----+   +----------------------+--------------------+
|        CONSOLIDATED CLARIFICATION RESPONSE             |   |       ACTION PROPOSAL PROMOTION            |
|  - ClarificationResponseBuilder generates single       |   |  - Gated by expected_revision & row lock   |
|    structured payload                                  |   |  - ActionProposalService::create()         |
|  - AI Workspace displays "Details Required" Card:      |   |  - Authoritative CommercialPricing preview |
|    * Confirmed Fields Summary (Read-only)              |   |  - Live SHA-256 Source State Hash          |
|    * Ambiguous Matches (Clickable candidate pickers)   |   |  - Cryptographic HMAC Approval Seal        |
|    * Missing Inputs (Typed controls)                   |   |  - Task status transitions to sealed       |
|    * Invalid Fields (Error explanations)               |   +----------------------+--------------------+
+--------------------------+-----------------------------+                          |
                           |                                                        v
                           v                                 +--------------------------------------------+
+--------------------------------------------------------+   |        ACTION REVIEW CENTER & APPROVAL     |
|             CONSOLIDATED USER CORRECTION               |   |  - Operational Desk Review & Sign-Off      |
|  - User submits batch patch with expected_revision     |   +----------------------+---------------------+
|  - TaskCorrectionService applies atomic update,        |                          |
|    cascades invalidation to derived_default slots      |                          v
|  - Returns to BATCH RECORD RESOLUTION                  |   +--------------------------------------------+
+--------------------------------------------------------+   |       AUTHORITATIVE ERP DOMAIN EXECUTION   |
                                                             |  - Short-transaction row locking           |
                                                             |  - Live revalidation against source state  |
                                                             |  - PO: CommercialApplicationService        |
                                                             |  - SO: OrderLifecycleService::createSale   |
                                                             +--------------------------------------------+
```

---

### 2. Core Architectural Components & Interfaces

#### 2.1 Action Requirements Provider Interface
The interface accepts `$currentSlots` to support dynamic line-item expansion:

```php
namespace App\Ai\Contracts;

use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;

interface ActionRequirementsProviderInterface
{
    public function actionKey(): string;

    /**
     * @param array<string, mixed> $currentSlots Current draft inputs for dynamic line expansion
     * @return list<ActionFieldRequirement>
     */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array;

    /**
     * Deterministic domain validation of complete inputs without ERP mutation.
     *
     * @param array<string, mixed> $resolvedInputs
     * @return array<string, string> Field errors keyed by stable field path.
     */
    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array;
}
```

#### 2.2 Action Field Requirement DTO
Each requirement definition encapsulates:
*   `fieldPath`: Stable identifier (`supplier_id`, `warehouse_id`, `items[client_line_id].product_id`, `items[client_line_id].qty`).
*   `dataType`: `string`, `integer`, `decimal`, `date`, `boolean`, `entity_id`.
*   `isRequired`: Boolean flag distinguishing mandatory fields from optional fields (e.g. order remarks, discounts).
*   `requiredWhen`: Optional closure evaluated against context and confirmed values.
*   `resolutionPolicy`: `unique_exact`, `bounded_candidates` (max 5), `explicit_confirmation`.
*   `dependencies`: Array of prerequisite field paths.
*   `inputControl`: `text`, `number`, `date_picker`, `entity_picker`.

#### 2.3 Single-Pass Batch Record Resolution Service
`BatchRecordResolutionService` executes batched, company-scoped queries:
*   **Parties:** Resolves supplier or customer via `RecordResolutionService` (exact ID, normalized GSTIN, exact name, or max 5 bounded candidates).
*   **Products across ALL Lines:** Collects all product queries from draft slots. Executes bulk query:
    ```php
    Product::where('company_id', $context->companyId)
        ->where(function ($q) use ($codes) {
            $q->whereIn('code', $codes)->orWhereIn('barcode', $codes);
        })
        ->get();
    ```
    Residual unmapped lines trigger individual alias or candidate searches, bounded to 5 candidates per ambiguous line.
*   **Warehouses:** Resolves explicit warehouse or defaults to active branch primary warehouse.
*   **Units of Measure:** Resolves `purchase_unit_id` for purchase orders, `sale_unit_id` for sales orders.
*   **Resolution Memoization:** Caches resolved foreign keys in `resolution_json`. Unchanged clean lines bypass database queries on subsequent patch iterations.

#### 2.4 Input Completeness Evaluator
The evaluator classifies each field into five discrete operational statuses:
1.  `resolved`: Unique, validated foreign key or valid primitive confirmed.
2.  `missing`: Mandatory field (`isRequired === true` or `requiredWhen() === true`) with no value.
3.  `invalid`: Value fails format, range, or domain logic.
4.  `ambiguous`: Multiple valid database candidates exist; requires user selection.
5.  `derived`: Auto-populated by ERP business rules (e.g. branch tax registration).

A task is strictly `ready` when `count(missing + invalid + ambiguous) === 0 && domain_validation_errors === []`. Optional fields that are omitted remain `not_provided` and do not block proposal readiness.

#### 2.5 Workflow Task State Persistence (`ai_workflow_tasks`)
Durable storage strictly enforces non-null company tenancy:

```sql
CREATE TABLE ai_workflow_tasks (
    id CHAR(36) NOT NULL PRIMARY KEY,
    action_key VARCHAR(100) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    thread_id VARCHAR(120) NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    financial_year_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'collecting',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    slots_json JSON NOT NULL,
    resolution_json JSON NULL,
    issues_json JSON NULL,
    linked_proposal_id CHAR(36) NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_wf_tasks_company_actor (company_id, actor_id, status),
    INDEX idx_wf_tasks_thread (thread_id),
    INDEX idx_wf_tasks_proposal (linked_proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 2.6 Concurrency, Invalidation & Atomic Promotion
1.  **Optimistic Concurrency on Patch Submissions:**
    `PATCH /api/v1/ai/workflow-tasks/{id}/inputs` requires `expected_revision`. Executes:
    ```sql
    UPDATE ai_workflow_tasks
    SET revision = revision + 1, slots_json = :slots, updated_at = NOW()
    WHERE id = :id AND company_id = :companyId AND actor_id = :actorId
      AND revision = :expectedRevision AND status IN ('collecting', 'needs_input')
      AND (expires_at IS NULL OR expires_at > NOW());
    ```
    If 0 rows are affected:
    - If task is expired (`expires_at <= NOW()`): Returns HTTP 410 Gone (`{"error": "task_expired"}`).
    - If task is sealed (`status = 'proposal_created'`): Returns HTTP 423 Locked (`{"error": "task_sealed"}`).
    - If revision drifted: Returns HTTP 409 Conflict (`{"error": "revision_conflict", "server_revision": int, "task": {...}}`).

2.  **Cascading Invalidation:**
    Slots are tagged with `source: 'user_explicit' | 'derived_default'`. When an upstream reference field changes:
    - Modifying Supplier/Customer → Purges downstream `derived_default` tax profiles, pricing tiers, and credit terms.
    - Modifying Product → Purges line UOM, unit cost, and tax category.
    - Modifying Warehouse → Purges branch delivery validation.

3.  **Atomic Proposal Promotion (`POST {id}/prepare-proposal`):**
    Requires `expected_revision`. Wrapped in `DB::transaction()`:
    - Acquires row lock: `SELECT * FROM ai_workflow_tasks WHERE id = :id FOR UPDATE`.
    - Asserts `status === 'ready'` and `revision === expected_revision`.
    - Invokes `ActionProposalService::create($principal, $task->action_key, $resolvedSlots, $task->thread_id)`.
    - Updates task: `status = 'proposal_created'`, `linked_proposal_id = $proposal->id`.
    - Commits transaction and returns HTTP 201 with proposal preview.

---

### 3. REST API Contract

All endpoints require `auth:sanctum`, active `company.context`, and `ai.use` permission. Every query enforces `company_id = $context->companyId AND actor_id = $actorId`.

| Method | Endpoint | Description & Access Control |
|---|---|---|
| `POST` | `/api/v1/ai/workflow-tasks` | Initiates new task draft (`action_key`, optional initial slots). |
| `GET` | `/api/v1/ai/workflow-tasks/{id}` | Returns active task state, confirmed summary, candidates, and blocking issues. |
| `PATCH` | `/api/v1/ai/workflow-tasks/{id}/inputs` | Submits batch corrections with `expected_revision`. Returns updated status or 409 Conflict / 410 Gone. |
| `POST` | `/api/v1/ai/workflow-tasks/{id}/prepare-proposal` | Promotes `ready` task to an immutable proposal under transaction lock with `expected_revision`. |
| `POST` | `/api/v1/ai/workflow-tasks/{id}/cancel` | Cancels active draft (`status = 'cancelled'`). Zero ERP writes. |

#### 3.1 Line Item Patch Payload Specification
```json
{
  "expected_revision": 2,
  "patches": {
    "supplier_id": 45,
    "items[line-1].product_id": 102,
    "items[line-2]._destroy": true,
    "items[line-3].product_id": 204,
    "items[line-3].qty": 50,
    "items[line-3].net_unit_cost": 120.00
  }
}
```

#### 3.2 Structured Clarification Response (HTTP 200 / `needs_input`)
```json
{
  "status": "needs_input",
  "task_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "revision": 2,
  "action_key": "purchase_order.create.v1",
  "confirmed_fields": [
    {"field": "warehouse_id", "label": "Warehouse", "display": "Main Warehouse", "source": "branch_default"},
    {"field": "items[line-1].qty", "label": "Line 1 Quantity", "display": "100 kgs", "source": "user_explicit"},
    {"field": "items[line-1].net_unit_cost", "label": "Line 1 Cost", "display": "₹120.00", "source": "user_explicit"}
  ],
  "required_inputs": [
    {
      "field": "supplier_id",
      "label": "Supplier",
      "status": "ambiguous",
      "reason": "Multiple suppliers match 'Apex Industries'",
      "input_control": "entity_picker",
      "candidates": [
        {"id": 45, "code": "SUP-APX-PB", "label": "Apex Industries Punjab (GSTIN: 03AAAAA1234A1Z5)"},
        {"id": 46, "code": "SUP-APX-HR", "label": "Apex Industries Haryana (GSTIN: 06AAAAA1234A1Z5)"}
      ]
    },
    {
      "field": "items[line-2].product_id",
      "label": "Line 2 Product",
      "status": "ambiguous",
      "reason": "Multiple SKUs match 'Packing Film'",
      "input_control": "entity_picker",
      "candidates": [
        {"id": 101, "code": "FILM-CLR-50", "label": "Packing Film Clear 50 micron"},
        {"id": 102, "code": "FILM-HD-50", "label": "Packing Film Heavy Duty 50 micron"}
      ]
    }
  ],
  "pending_checks": [
    "Authoritative GST calculation (awaiting supplier tax state)",
    "Authoritative purchase order totals"
  ],
  "can_prepare_proposal": false
}
```

---

## Risks & Mitigations

| Risk / Failure Mode | Severity | Mitigation Strategy |
|---|:---:|---|
| **Query Storms on Multi-Line Resolution** | High | Set-based bulk SQL queries (`WHERE code IN (...) OR barcode IN (...)`), maximum candidate cap of 5 per field, and dirty-slot memoization in `resolution_json`. |
| **Cross-Tenant Data Leakage** | Critical | Enforce `company_id BIGINT UNSIGNED NOT NULL`. Compound every query and patch with `$task->company_id === $context->companyId`. Reject unowned entity IDs at validation. |
| **Concurrent Double Promotion** | High | Require `expected_revision` on `/prepare-proposal`. Wrap promotion in `DB::transaction()` with `SELECT ... FOR UPDATE` row locking. Atomically seal task to prevent duplicate proposals. |
| **Post-Promotion Task Tampering** | Medium | Status guard on `updateInputs`: only allow updates when `status IN ('collecting', 'needs_input')`. Reject patches on promoted tasks with HTTP 423 Locked. |
| **Stale Pricing / Tax Leakage on Entity Switch** | High | Slot sourcing tags (`user_explicit` vs `derived_default`). Purge all `derived_default` pricing and tax slots whenever root party or warehouse references change. |
| **Payload Bloat on Large Orders** | Medium | Bounded candidate pickers (max 5 items). Return diagnostic summaries in primary card, deferring full catalog search to on-demand search endpoints. |

---

## Alternatives Considered

### Alternative A: Conversational Multi-Turn LLM Loop (Early-Return / Stop-at-First-Error)
*   **Mechanism**: The LLM queries tools sequentially. On encountering an unresolved entity, the tool returns `needs_input` immediately; the LLM prompts the user in chat, receives the response, and then continues.
*   **Pros**: Simple tool logic; no pre-proposal state persistence required.
*   **Cons**: Unacceptable latency; 5 to 10 conversational turns for a multi-line order; high token consumption; hides downstream line errors.
*   **Decision**: Rejected due to high user friction and repetitive turn latency. Commercial ERP entry requires a single consolidated diagnostic pass showing all missing and ambiguous fields together.

### Alternative B: In-Memory / Ephemeral Task State (Session / Redis Cache Only)
*   **Mechanism**: Store draft tasks strictly in Redis or PHP session storage without dedicated database tables.
*   **Pros**: Zero database migrations; automatic TTL expiration.
*   **Cons**: Volatile; drafts lost on cache eviction or multi-tab context switches; cannot support asynchronous background processing or durable multi-turn workflows.
*   **Decision**: Rejected because ephemeral storage lacks durability and multi-tab session reliability. Database-backed state with optimistic concurrency locking is required.

### Alternative C: Storing Incomplete Drafts in `ai_action_proposals` with Status `draft`
*   **Mechanism**: Reuse the existing `ai_action_proposals` table by adding a `draft` status flag for incomplete user inputs.
*   **Pros**: Reuses existing tables without adding `ai_workflow_tasks`.
*   **Cons**: Violates the immutability and cryptographic sealing invariants of the Action Foundation. Storing mutable drafts in the proposal table corrupts payload hash verification and risks premature execution of incomplete actions.
*   **Decision**: Rejected because proposals must remain strictly immutable, cryptographically sealed, and fully validated. Mutable task drafts must reside in a dedicated pre-proposal table (`ai_workflow_tasks`).

---

## Detailed Implementation Plan

### Slice 1: Database Schema & Core Contracts

1.  **`[Create]` `database/migrations/2026_11_11_000002_create_ai_workflow_tasks_tables.php`**
    *   *Rationale*: Creates additive table `ai_workflow_tasks` with `company_id BIGINT UNSIGNED NOT NULL`, indexes `(company_id, actor_id, status)`, `thread_id`, and `linked_proposal_id`. Drops speculative `ai_workflow_task_events` table per review recommendations. Enforces Tenancy Law 4.
2.  **`[Modify]` `app/Ai/Contracts/ActionRequirementsProviderInterface.php`**
    *   *Rationale*: Updates interface signature to accept `$currentSlots` array: `requirements(WorkflowTaskContext $context, array $currentSlots = []): array`. Enables dynamic line-item expansion for commercial orders.
3.  **`[Modify]` `app/Ai/DTOs/ActionFieldRequirement.php`**
    *   *Rationale*: Ensures typed properties: `$fieldPath`, `$dataType`, `$isRequired`, `$requiredWhen`, `$allowedSources`, `$resolutionPolicy`, `$dependencies`, `$inputControl`.
4.  **`[Modify]` `app/Ai/DTOs/WorkflowTaskContext.php`**
    *   *Rationale*: Encapsulates `$principal`, `$context` (`CompanyContext`), `$mode`, `$taskId`, `$threadId`, and `$metadata`.
5.  **`[Modify]` `app/Ai/DTOs/FieldIssue.php`**
    *   *Rationale*: Encapsulates field diagnostic issues: `$field`, `$status`, `$label`, `$reason`, `$inputControl`, `$candidates`, `$clientLineId`.
6.  **`[Modify]` `app/Ai/DTOs/EvaluationResult.php`**
    *   *Rationale*: Aggregates resolved values, field issues collection, pending checks, and exposes `isReady(): bool`.
7.  **`[Modify]` `app/Ai/DTOs/BatchResolutionResult.php`**
    *   *Rationale*: Holds resolved entity collections, bounded candidate arrays, and lookup statuses across parties, products, warehouses, and units.

---

### Slice 2: Requirements Registry & Primary PO Provider

8.  **`[Modify]` `app/Ai/Registries/ActionRequirementsRegistry.php`**
    *   *Rationale*: Central registry managing domain providers. Discovers requirements schemas by action key and prevents arbitrary client-submitted schemas.
9.  **`[Modify]` `app/Ai/Requirements/PurchaseOrderRequirementsProvider.php`**
    *   *Rationale*: Primary Phase 2.1 vertical slice provider for `purchase_order.create.v1`. Dynamically expands `items[client_line_id]` requirements from `$currentSlots`. Validates supplier, warehouse, order date, and per-line product, quantity, unit cost, and tax rates.
10. **`[Create]` `app/Ai/Requirements/SalesOrderRequirementsProvider.php`**
    *   *Rationale*: Extensibility provider for `sales_order.create.v1`. Validates customer, delivery warehouse, delivery date, and per-line product, quantity, unit price, and discounts.

---

### Slice 3: Batch Resolution & Evaluation Engine

11. **`[Modify]` `app/Ai/Services/BatchRecordResolutionService.php`**
    *   *Rationale*: Implements single-pass set-based bulk lookups (`WHERE code IN (...) OR barcode IN (...)`) across authorized company products. Adds customer resolution (`resolveCustomer()`) and sales UOM resolution. Implements dirty-slot memoization via `resolution_json` and enforces candidate cap of 5.
12. **`[Modify]` `app/Ai/Services/RecordResolutionService.php`**
    *   *Rationale*: Adds `resolveCustomer(CompanyContext $context, string $query)` matching exact ID, GSTIN, company name, or bounded candidate list.
13. **`[Modify]` `app/Ai/Services/InputCompletenessEvaluator.php`**
    *   *Rationale*: Evaluates all field requirements against resolved values in a single pass. Distinguishes mandatory missing fields from omitted optional fields. Returns complete `EvaluationResult` without early exit.
14. **`[Modify]` `app/Ai/Services/WorkflowTaskStateService.php`**
    *   *Rationale*: Manages `ai_workflow_tasks` table CRUD, monotonic revision increments (`revision = revision + 1`), 24-hour inactivity expiration (`config('ai_workflow.task_ttl_hours', 24)`), and task-to-proposal linking.
15. **`[Modify]` `app/Ai/Services/TaskCorrectionService.php`**
    *   *Rationale*: Applies batch patches with `expected_revision`. Implements line item mutation semantics (`_destroy: true`). Enforces status guard `status IN ('collecting', 'needs_input')`. Cascades invalidation to downstream `derived_default` slots upon root entity mutation.
16. **`[Modify]` `app/Ai/Services/ClarificationResponseBuilder.php`**
    *   *Rationale*: Constructs structured JSON clarification payload and Fact Card metadata containing confirmed fields, required inputs, selectable candidate pickers, and pending dependent checks.

---

### Slice 4: Tool Refactoring, API Endpoints & UI Components

17. **`[Modify]` `app/Ai/Tools/PreparePurchaseOrder.php`**
    *   *Rationale*: Refactors tool to delegate to `WorkflowTaskStateService`, `BatchRecordResolutionService`, and `InputCompletenessEvaluator`. Replaces the sequential early-exit return with a consolidated `needs_input` diagnostic card.
18. **`[Modify]` `app/Http/Controllers/Api/V1/AiWorkflowTaskController.php`**
    *   *Rationale*: REST API controller exposing `/api/v1/ai/workflow-tasks/*` endpoints: task creation (`store`), state retrieval (`show`), batch patch submission with `expected_revision` (`updateInputs`), atomic proposal promotion (`prepareProposal`), and cancellation (`cancel`). Enforces Sanctum auth, `company.context`, ownership, and HTTP 409/410/423 status semantics.
19. **`[Modify]` `app/Http/Controllers/Api/V1/AiChatController.php`**
    *   *Rationale*: Updates chat response packaging to associate conversational turns with active `task_id` when an action tool returns `needs_input`.
20. **`[Modify]` `app/Providers/AiServiceProvider.php`**
    *   *Rationale*: Registers `ActionRequirementsRegistry` as a singleton, binds requirements providers, and registers workflow task services.
21. **`[Modify]` `routes/api.php`**
    *   *Rationale*: Defines versioned routes under `/api/v1/ai/workflow-tasks` protected by Sanctum and `company.context` middleware.
22. **`[Modify]` `resources/views/backend/ai/workspace.blade.php`**
    *   *Rationale*: Implements the "Details Required" Fact Card component rendering confirmed summaries, candidate selection radio pickers, native input controls, and single-click "Submit All Details" AJAX action.
23. **`[Create]` `public/js/ai-task-card.js`**
    *   *Rationale*: Client-side script managing interactive candidate selection, single-pass patch compilation, optimistic revision tracking, and 409 conflict handling.

---

### Slice 5: Automated Verification Test Suites

24. **`[Create]` `tests/Feature/Ai/TaskEngine/WorkflowTaskStateTest.php`**
    *   *Rationale*: Uses `UsesAiMysql` trait. Verifies task creation, optimistic revision locking (HTTP 409 on stale revision), 24-hour expiration (HTTP 410 Gone), and cross-tenant isolation (HTTP 403/404 when Company B accesses Company A's task).
25. **`[Create]` `tests/Feature/Ai/TaskEngine/BatchRecordResolutionTest.php`**
    *   *Rationale*: Uses `UsesAiMysql` trait. Verifies single-pass bulk resolution for suppliers, customers, multi-line products, UOMs, and warehouses. Asserts candidate lists are bounded to 5 items and cross-company records are rejected.
26. **`[Create]` `tests/Feature/Ai/TaskEngine/InputCompletenessEvaluatorTest.php`**
    *   *Rationale*: Uses `UsesAiMysql` trait. Verifies that the evaluator checks all fields concurrently, reports multiple line-item errors in a single pass, distinguishes optional from mandatory fields, and gates proposal promotion strictly until zero blocking issues remain.
27. **`[Create]` `tests/Feature/Ai/Commercial/MultiSlotPurchaseOrderWorkflowTest.php`**
    *   *Rationale*: Uses `UsesAiMysql` trait. End-to-end integration test verifying purchase order intake, ambiguous supplier and product candidate selection in a single card, atomic proposal promotion, and authoritative execution.
28. **`[Create]` `tests/Feature/Ai/Commercial/MultiSlotSalesOrderWorkflowTest.php`**
    *   *Rationale*: Uses `UsesAiMysql` trait. Verifies sales order intake, multi-line product disambiguation, customer resolution, and promotion to `sales_order.create.v1`.

---

### Slice 6: Repository Master Architecture Documentation Updates

29. **`[Modify]` `AGENTS.md`**
    *   *Rationale*: Updates root master index: splices Phase 2.1 into Phased AI Roadmap diagram (`PH2 -> PH2.1 -> PH3`) and adds stories `AI-025` through `AI-029` to the delivery catalog.
30. **`[Modify]` `SESSION_MEMORY.md`**
    *   *Rationale*: Records Phase 2 completion (109/109 tests green) and establishes Phase 2.1 on `feature/ai-unified-input-clarification` as the active operational development track.
31. **`[Modify]` `docs/zoloerp-ai-agent-implementation/docs/ai/03_ARCHITECTURE.md`**
    *   *Rationale*: Updates architecture blueprint to document the Pre-Proposal Task Orchestration Layer between the Model/Chat Gateway and the Action Foundation.
32. **`[Modify]` `docs/zoloerp-ai-agent-implementation/docs/ai/06_DATABASE_SCHEMA.md`**
    *   *Rationale*: Adds DDL and indexing specifications for `ai_workflow_tasks` with non-null `company_id`.
33. **`[Modify]` `docs/zoloerp-ai-agent-implementation/docs/ai/20_DELIVERY_BACKLOG.md`**
    *   *Rationale*: Inserts Phase 2.1 section with deliverables `UC-001` through `UC-007` and updated dependency gates.
34. **`[Modify]` `docs/zoloerp-ai-agent-implementation/docs/ai/27_INTENT_AND_RECORD_RESOLUTION.md`**
    *   *Rationale*: Expands record resolution documentation to specify `BatchRecordResolutionService` set-based lookups, candidate bounding, and customer resolution.
35. **`[Modify]` `docs/zoloerp-ai-agent-implementation/docs/ai/29_WORKFLOW_ORCHESTRATION.md`**
    *   *Rationale*: Documents task state machine (`collecting` -> `validating` -> `ready` -> `proposal_created`) and human change request loop-back paths.
36. **`[Modify]` `docs/zoloerp-ai-agent-implementation/docs/ai/30_PURCHASE_WORKFLOWS.md`**
    *   *Rationale*: Updates purchase order workflow from early-return stop to consolidated batch clarification.
37. **`[Create]` `docs/zoloerp-ai-agent-implementation/docs/ai/41_UNIFIED_INPUT_COLLECTION_AND_CLARIFICATION.md`**
    *   *Rationale*: Permanent specification document incorporating the complete Phase 2.1 architecture and contract suite into the repository documentation library.
