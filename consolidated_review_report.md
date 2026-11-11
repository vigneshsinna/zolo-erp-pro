# EGM Consolidated Goldfish Review Report: Phase 2.1 Multi-Slot Clarification Engine

**Document Audited:** `design_draft.md` (Lines 1–578)  
**Date:** 2026-11-11  
**Review Standard:** Multi-Perspective EGM Swarm (`zoom-out-standard.md`, `feature-spec-standard.md`, `re-implementation-standard.md`)  
**Target Repository:** `vigneshsinna/zolo-erp-pro` (`feature/ai-unified-input-clarification`)  

---

## Reviewer Status

| Reviewer Lens | Persona | Status | Primary Focus |
|---|---|:---:|---|
| `egm-zoom-out` | Skeptical Architect | ✅ Completed | Simplicity, YAGNI, codebase reuse, abstraction sanity |
| `egm-comprehension` | Naive Reader Persona | ✅ Completed | Self-containment, terminology, lifecycle clarity |
| `egm-design` | Hostile Design Critic | ✅ Completed | Multi-tenant isolation, concurrency, immutability, data leaks |
| `egm-codebase-details` | Technical Quality Auditor | ✅ Completed | Laravel 13 parity, PHP 8.5 DTOs, domain service contracts |
| `egm-implementation-readiness` | Pragmatic Tech Lead | ✅ Completed | Testability, API contracts, line item addressing, exit gates |
| `egm-inventive-catalyst` | Inventive Problem Solver | ✅ Completed | TRIZ trade-off resolution, query storm elimination |
| `egm-style` | Technical Copy Editor | ✅ Completed | Plain-English standards, concurrency terminology, tone |

---

## Prioritized Findings Breakdown

### 🔴 Blockers (Must Remediate Before Finalization)

#### B-01: Sales Order Domain Service Delegation Contradicts Codebase
*   **Lenses:** `egm-codebase-details` (Finding 1.1), `egm-zoom-out` (Finding 1)
*   **Affected Scope:** `design_draft.md` Lines 54, 204, 551
*   **Discrepancy:** The design states that commercial execution delegates to `CommercialApplicationService`. In `zolo-erp-pro`, `CommercialApplicationService::create(kind: 'sale')` creates a posted **Sales Invoice** (`Sale`), which immediately triggers stock deductions and ledger postings. Sales Orders (`SalesOrder`) are unposted commercial promises created exclusively through `OrderLifecycleService::createSale(array $data, string $key, CompanyContext $context, int $actor): SalesOrder`.
*   **Remediation:** Explicitly route Sales Order execution through `OrderLifecycleService::createSale()` and reserve `CommercialApplicationService` for Sales Invoices and Purchase Bills.

#### B-02: Missing Action Proposal Pipeline for Sales Order & Quotation
*   **Lenses:** `egm-codebase-details` (Finding 1.2), `egm-implementation-readiness` (Finding 2.1)
*   **Affected Scope:** `design_draft.md` Lines 489–497, 525, 551
*   **Discrepancy:** Promoting a task to a proposal via `ActionProposalService::create()` mandates an `ActionDefinition` registered in `ActionCapabilityRegistry` with an input schema, preview adapter, and command adapter. The plan only lists requirements providers for the task layer, omitting `CreateSalesOrderInputSchema`, `SalesOrderPreviewAdapter`, `SalesOrderCommandAdapter`, and registry bindings for `sales_order.create.v1`.
*   **Remediation:** Enumerate and implement the complete Action Foundation adapter pipeline (`CreateSalesOrderInputSchema`, `SalesOrderPreviewAdapter`, `SalesOrderCommandAdapter`) and register them in `ActionCapabilityRegistry::registerCommercialCapabilities()`.

#### B-03: Multi-Tenancy Security & Nullable `company_id` Defect
*   **Lenses:** `egm-design` (Finding F-02), `egm-zoom-out` (Finding 6), `egm-comprehension` (Finding 1)
*   **Affected Scope:** `design_draft.md` Lines 286, 300–302, 337–345
*   **Discrepancy:** `ai_workflow_tasks.company_id` is defined as nullable, violating Tenancy Law 1 (*Zero Unscoped Reads or Writes*). An actor-only index `(actor_id, status)` allows cross-company leakage if an operator switches companies in their web session.
*   **Remediation:** Alter `company_id` to `BIGINT UNSIGNED NOT NULL` for company workflows. Separate bootstrap tasks from company tasks or enforce strict non-null compound checks `WHERE id = :id AND company_id = :company_id AND actor_id = :actor_id` on all task queries.

#### B-04: Dynamic Line Item Addressing in Requirements Contract
*   **Lenses:** `egm-implementation-readiness` (Finding 1.1), `egm-comprehension` (Finding 5)
*   **Affected Scope:** `design_draft.md` Lines 225, 245, 470–473
*   **Discrepancy:** `ActionRequirementsProviderInterface::requirements(WorkflowTaskContext $context)` accepts only context without input slots. Commercial orders contain dynamic line items (`items[client_line_id]`), making it impossible for the provider to evaluate dynamic line rules without passing current slots.
*   **Remediation:** Update interface signature to `requirements(WorkflowTaskContext $context, array $currentSlots = []): array` or define a wildcard expansion rule (`items[*].product_id`) evaluated dynamically against current draft slots.

#### B-05: Unbounded Query Storms during Batch Record Resolution
*   **Lenses:** `egm-inventive-catalyst` (Finding 1), `egm-design` (Finding F-07)
*   **Affected Scope:** `design_draft.md` Lines 49, 164–171, 254–260
*   **Discrepancy:** Resolving 50 line items line-by-line across multiple strategies (SKU, barcode, exact name, fuzzy candidate queries) creates connection starvation on MySQL. Re-evaluating clean lines on incremental patches compounds the bottleneck.
*   **Remediation:**
    1. Vectorize queries into set-based bulk lookups (`WHERE code IN (...) OR barcode IN (...)`).
    2. Segment resolution into Dirty Slots vs. Clean Slots; reuse memoized resolutions in `resolution_json`.
    3. Enforce candidate caps (maximum 5) and execution timeout limits.

#### B-06: Concurrency & Atomicity on Proposal Promotion
*   **Lenses:** `egm-design` (Findings F-03, F-04), `egm-inventive-catalyst` (Finding 3)
*   **Affected Scope:** `design_draft.md` Lines 320–330, 343
*   **Discrepancy:** `POST /prepare-proposal` omits `expected_revision` and lacks database row locks, allowing race conditions and concurrent duplicate proposal creation. Furthermore, task mutation endpoints lack status guards, allowing clients to patch a task after it has been promoted to a proposal.
*   **Remediation:**
    1. Require `expected_revision` in `/prepare-proposal`.
    2. Wrap proposal promotion in `DB::transaction()` with `lockForUpdate()`.
    3. Enforce atomic status transition to `sealed` / `proposal_created`. Reject patches on sealed tasks with HTTP 423 Locked.

#### B-07: Customer Resolution & Sales UOM Omission
*   **Lenses:** `egm-codebase-details` (Finding 1.3), `egm-zoom-out` (Finding 1)
*   **Affected Scope:** `design_draft.md` Lines 254–260, 508–509
*   **Discrepancy:** `RecordResolutionService` and `BatchRecordResolutionService` currently implement only supplier and warehouse resolution with hardcoded `purchase_unit_id`. They completely lack `resolveCustomer()` and `sale_unit_id` resolution required for Sales Orders (CUJ 1).
*   **Remediation:** Expand `RecordResolutionService` and `BatchRecordResolutionService` to implement customer resolution (`resolveCustomer()`) and sales unit of measure resolution (`sale_unit_id`).

---

### 🟡 Majors (Actionable Architectural Improvements)

#### M-01: Scope Calibration — Text-First Commercial Focus vs. Speculative Multi-Modal Scope
*   **Lenses:** `egm-zoom-out` (Finding 3), `egm-comprehension` (Finding 6), `egm-style` (Finding 1.3)
*   **Discrepancy:** The draft dedicates significant architectural weight to OCR invoice scans and audio voice notes, despite the repository having zero OCR or speech libraries installed.
*   **Remediation:** Calibrate Phase 2.1 scope to text-based multi-slot commercial entry (chat, pasted text, structured import). Clearly mark multi-modal OCR/voice as future extensions (Phase 3).

#### M-02: Explicit Line Item Mutation Semantics (Add, Delete, Reorder)
*   **Lenses:** `egm-implementation-readiness` (Finding 1.2)
*   **Discrepancy:** The patch protocol for line items does not define how lines are deleted, added, or reordered during clarification.
*   **Remediation:** Formally specify line mutation payload: new lines supply client UUIDs; deletions supply `_destroy: true`; modifications update specific field paths without clobbering siblings.

#### M-03: HTTP 409 Conflict Contract & Client Reconciliation
*   **Lenses:** `egm-implementation-readiness` (Finding 1.3)
*   **Discrepancy:** The plan specifies HTTP 409 on revision mismatch but omits the response payload schema and client-side reconciliation protocol.
*   **Remediation:** Specify the 409 payload (`{"error": "revision_conflict", "server_revision": int, "task": {...}}`) and client behavior (prompt user to refresh with latest server state).

#### M-04: Explicit File Operation Markers (`[Create]`, `[Modify]`, `[Delete]`)
*   **Lenses:** `egm-codebase-details` (Finding 2.1)
*   **Discrepancy:** All 43 enumerated files in the Detailed Implementation Plan lack operation markers, obscuring which files already exist, which are modified, and which are created.
*   **Remediation:** Prepend explicit `[Create]`, `[Modify]`, or `[Delete]` tags to all file entries in Slices 1 through 6.

#### M-05: Missing Mandatory "Risks & Mitigations" Section
*   **Lenses:** `egm-design` (Finding F-01)
*   **Discrepancy:** The design document lacks a formal "Risks & Mitigations" section mandated by `feature-spec-standard.md`.
*   **Remediation:** Insert `## Risks & Mitigations` covering concurrency races, query storms, payload bloat, and mitigations.

#### M-06: Discarding Ephemeral Event Sourcing (`ai_workflow_task_events`)
*   **Lenses:** `egm-zoom-out` (Finding 2)
*   **Discrepancy:** An append-only event-sourcing table (`ai_workflow_task_events`) for transient 24-hour drafts adds unneeded database writes and schema weight without regulatory audit value.
*   **Remediation:** Drop `ai_workflow_task_events` from Phase 2.1. Persist audit history exclusively at the proposal and execution stages (`ai_action_proposals`, `ai_action_executions`).

#### M-07: Invalidation Cascades & Slot Sourcing
*   **Lenses:** `egm-design` (Finding F-05)
*   **Discrepancy:** Changing a root entity (customer/supplier) leaves previously derived prices or tax allocations in `slots_json`, risking customer pricing leakage.
*   **Remediation:** Tag slot sources (`user_explicit` vs. `derived_default`). Purge all `derived_default` slots downstream of modified root fields upon patch application.

#### M-08: Terminology Calibration (Concurrency vs. Batched Lookups)
*   **Lenses:** `egm-style` (Finding 1.1), `egm-implementation-readiness` (Finding 2.3)
*   **Discrepancy:** Wording like "parallel lookups" and "executes concurrently" is misleading in single-threaded PHP-FPM.
*   **Remediation:** Replace "concurrently/parallel" with "batched lookups / single-pass `whereIn` queries".

#### M-09: Unimplemented `validate` Endpoint & Request Schemas
*   **Lenses:** `egm-codebase-details` (Finding 2.2), `egm-comprehension` (Finding 3)
*   **Discrepancy:** `POST /api/v1/ai/workflow-tasks/{id}/validate` is listed in the REST table but absent from routes and controller.
*   **Remediation:** Add `validate` action to `AiWorkflowTaskController` and `routes/api.php`, or document that validation is performed via `GET {id}` and `PATCH {id}/inputs`.

#### M-10: Inactivity Expiration Lifecycle & HTTP 410 Semantics
*   **Lenses:** `egm-codebase-details` (Finding 2.4), `egm-design` (Finding F-09), `egm-implementation-readiness` (Finding 2.5)
*   **Discrepancy:** Expiration currently falls back to proposal TTL (60 mins) rather than 24 hours, and error response on expired tasks is unspecified.
*   **Remediation:** Introduce `config('ai_workflow.task_ttl_hours', 24)` and return HTTP 410 Gone for operations on expired tasks.

---

### 🟢 Nits (Clarity, Layout & Style Polish)

1.  **N-01:** Remove all instances of `*Rejected by author*` in the Alternatives section; frame rejections purely as technical trade-offs. (`egm-design` F-06, `egm-style` 2.3)
2.  **N-02:** Remove anthropomorphic and pejorative phrasing (*"blindness"*, *"naive systems"*, *"hallucinate"*). (`egm-style` 1.5)
3.  **N-03:** Add terminology definitions for regional and domain acronyms (`GSTIN`, `HSN`, `UOM`, `SKU`). (`egm-comprehension` 8)
4.  **N-04:** Align DTO parameter names with codebase conventions (`$inputControl`, `$isReady()`). (`egm-codebase-details` 3.1)
5.  **N-05:** Clarify that secondary performance indexes are used instead of relational foreign key constraints. (`egm-implementation-readiness` 3.3, `egm-codebase-details` 3.2)
6.  **N-06:** Enforce `UsesAiMysql` trait and disposable test database isolation across all planned test suites. (`egm-codebase-details` 3.3)

---

## Traceability Matrix

| Finding ID | Title | Source Reviewer | Target Document Section |
|:---:|---|---|---|
| **B-01** | Sales Order Domain Service Delegation | `egm-codebase-details`, `egm-zoom-out` | Section 1, Slice 5 (Item 32) |
| **B-02** | Missing Sales Order Action Proposal Artifacts | `egm-codebase-details`, `egm-readiness` | Slice 2, Slice 4, Slice 5 |
| **B-03** | Multi-Tenancy Security & Nullable `company_id` | `egm-design`, `egm-zoom-out`, `egm-comprehension` | Section 2.5, Section 3, Slice 1 |
| **B-04** | Dynamic Line Item Requirements Signature | `egm-readiness`, `egm-comprehension` | Section 2.1, Slice 1 (Item 2) |
| **B-05** | Batch Resolution Query Storms & Vectorization | `egm-inventive-catalyst`, `egm-design` | Section 2.3, Slice 3 (Item 16) |
| **B-06** | Atomicity & Lock on Proposal Promotion | `egm-design`, `egm-inventive-catalyst` | Section 2.6, Section 3, Slice 4 |
| **B-07** | Customer & Sales UOM Resolution | `egm-codebase-details`, `egm-zoom-out` | Section 2.3, Slice 3 (Item 16) |
| **M-01** | Scope Calibration (Text-First vs OCR) | `egm-zoom-out`, `egm-comprehension`, `egm-style` | Overview, CUJ 3, Goals |
| **M-02** | Line Item Mutation Semantics | `egm-readiness` | Section 2.6, Section 3 |
| **M-03** | HTTP 409 Conflict Contract | `egm-readiness` | Section 2.6, Section 3 |
| **M-04** | File Operation Markers | `egm-codebase-details` | Slices 1–6 (Items 1–43) |
| **M-05** | Mandatory Risks & Mitigations Section | `egm-design` | New Section 6 |
| **M-06** | Discarding `ai_workflow_task_events` | `egm-zoom-out` | Section 2.5, Slice 1 |
| **M-07** | Invalidation Cascades & Slot Sourcing | `egm-design` | Section 2.6 |
| **M-08** | Concurrency Terminology Calibration | `egm-style`, `egm-readiness` | Section 1, Section 2.3 |
| **M-09** | Validation Endpoint & Schemas | `egm-codebase-details`, `egm-comprehension` | Section 3, Slice 4 |
| **M-10** | Expiration Lifecycle & 410 Semantics | `egm-codebase-details`, `egm-design` | Section 2.5, Section 3 |
