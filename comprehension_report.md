# Stage 6 Report: Comprehension & Self-Containment Test

**Target Document:** [`docs/ZOLOERP_AI_INTELLIGENT_ENTITY_RESOLUTION_UI_IMPLEMENTATION.md`](file:///v:/pers/Freelance/zolo-erp-pro/docs/ZOLOERP_AI_INTELLIGENT_ENTITY_RESOLUTION_UI_IMPLEMENTATION.md)  
**Evaluated Scope:** `FeatureRange` (Lines 1–376) & `DetailedRange` (Lines 377–659)  
**Reviewer:** `egm-comprehension` (Naive Reader)  
**Standard:** Comprehension Protocol (Zero Assumed Knowledge)  
**Verdict:** **Needs Revision** (2 Blockers, 5 Majors, 3 Nits)

---

## 1. Architectural Paraphrase

### What this proposes
The document proposes an intelligent entity resolution interface and workflow within the ZoloERP AI Copilot to handle missing or ambiguous master entities (such as warehouses, suppliers, products, and units) during purchase bill creation. When an entity is ambiguous or missing, the user is presented with a searchable, server-scoped directory picker beside the bill preview or offered a governed AI-assisted master creation panel if they have creation permissions. Once a missing master is created and separately approved by a human, the workflow automatically binds the new ERP entity ID and resumes the original purchase bill review without re-uploading documents or restarting the conversation. The entire flow consolidates into a single evolving review card where all ERP validations are strictly server-side, culminating in a mandatory, version-bound "Approve & Create" step before any financial ledger or stock write occurs.

### How the existing system works, per this document
Per this document, the current implementation on the `feature/ai-unified-input-clarification` branch contains a critical contract bug where the frontend sends a `slots_patch` payload that is rejected by the controller expecting a `corrections` array. Furthermore, warehouse resolution unsafely defaults to the first matching branch or company record (`LIKE '%query%' -> first()`), while the UI hard-codes "Main Warehouse" fallbacks in previews and booked views. The system already possesses backend services for entity resolution, task correction, master spawning, and proposal previews, but UI ownership is fractured between an older clarification interface in `workspace.blade.php` and an incomplete unified card in `ai-media-input.js`. Additionally, the preparation workflow currently lacks a dedicated, tenant-scoped entity lookup endpoint, relying instead on rudimentary text inputs or unvalidated dropdowns.

---

## 2. Comprehension & Self-Containment Findings

### Finding 1: Undefined Contracts for Dependent Master Lifecycle Endpoints
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Section 4.2 (Lines 193–205), Section 5.2 (Lines 290–296), Section 8 Phase 3 (Lines 410–418)
* **Description:** The document mandates calling three existing endpoints:
  - `POST /ai/workflow-tasks/{id}/dependent-masters/spawn`
  - `POST /ai/workflow-tasks/{id}/dependent-masters/preview`
  - `POST /ai/workflow-tasks/{id}/dependent-masters/approve-and-execute`
  However, while the document provides concrete schemas for `GET /ai/entity-options` and `PATCH /ai/workflow-tasks/{id}/inputs`, it provides zero payload schemas, parameter definitions, or response structures for these three master endpoints. An engineer building `AiMasterCreatePanel` has no specification for what fields must be submitted to spawn, preview, or execute a master creation, forcing them to guess or reverse-engineer undocumented backend controllers.

---

### Finding 2: Contradictory Execution Boundary for Parent Task Resumption
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Section 1 (Lines 23, 26), Section 2.1 (Line 44), Section 4.2 (Line 201), Section 8 Phase 3 (Line 414), Section 11 (Line 525)
* **Description:** The document provides conflicting statements regarding whether task resumption is handled by the server or orchestrated by the client. Lines 23 and 44 state that the system and `DependentMasterOrchestrator` automatically "bind new ID back into same UI... resumes parent task... without a manual next step." Line 525 even states as a failure recovery rule: *"Master creation completes but parent task update fails: Recover from linked execution receipt"*, which implies the backend handles this atomically. However, Line 201 instructs: *"On successful execution, capture `newly_created.resource_id`, refresh task/revision and the picker; re-resolve the parent bill automatically"*, which implies the client is responsible for capturing the ID and triggering re-resolution. An implementer cannot tell whether the backend `approve-and-execute` endpoint mutates the parent `ai_workflow_tasks` row directly or if the frontend must issue a subsequent `PATCH /ai/workflow-tasks/{id}/inputs` correction.

---

### Finding 3: Hand-Waving & Unspecified Syntax for Nested Line Item Field Paths
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 4.1 (Lines 178–180), Section 5.1 (Lines 274–277)
* **Description:** The document states that `fieldPath` can be `items[line-0].product_id` and instructs the developer: *"For product line edits use stable `items[line-0].product_id`, `items[line-0].qty`, etc., according to the service's supported field syntax; inspect/test each nested operation before wiring it."* Handing off contract discovery to "inspect/test before wiring it" fails self-containment. It never defines how client line keys (`line-0`) are generated, whether `TaskCorrectionService` accepts bracket syntax, dot syntax, or JSON pointers, or how line additions and deletions are expressed in a `corrections` payload.

---

### Finding 4: Inconsistent Lifecycle States & Evaluation Status Strings
* **Severity:** 🟡 **Major**
* **Affected Scope:** Lines 24, 164, 236, 281–287, 349–367
* **Description:** Multiple incompatible status enums are introduced across different sections:
  - Line 24: `preparing → needs details → ready for approval → executing → booked / failed`
  - Line 164: `Preparing → Needs details → Preview ready → Awaiting approval → Executing → Complete / Failed`
  - Line 236: `"status": "selection_needed"`
  - Lines 281–287: `response.evaluation?.is_ready` with `status: needs_input`
  - Lines 351–367: Mermaid flowchart introduces separate conceptual states.
  An engineer implementing `AiTaskStatusIndicator` or `AiWorkflowReviewCard` cannot determine the canonical state machine, what string values exist on `task.status` versus `evaluation.status`, or what condition gates `is_ready`.

---

### Finding 5: Undefined Criteria for Entity "Eligibility" & Action-Aware Branch Scoping
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 2.2 (Line 82), Section 6 (Lines 319, 326), Section 8 Phase 0 (Line 384), Section 8 Phase 2 (Line 402)
* **Description:** The document repeatedly stresses: *"Some resolver methods scope to company but do not enforce every relevant branch/warehouse eligibility rule. Introduce explicit, action-aware eligibility checks rather than relying on a UI-side filter."* However, it never defines what schema columns, relationships, or tables determine branch eligibility (e.g., whether `warehouses` has a direct `branch_id`, a `branch_warehouse` pivot, an active ledger link, or specific warehouse type flags). An engineer building `AiEntityOptionsService` does not know what SQL constraints constitute an "eligible" warehouse for purchase bills.

---

### Finding 6: Vague Conflict Reconciliation UX on HTTP 409 Optimistic Locking Failures
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 2.2 (Line 68), Section 5.1 (Line 288), Section 11 (Line 523)
* **Description:** Line 68 states: *"For a 409, fetch the latest task and let the user reconcile changes; do not blindly replay a stale mutation."* Line 288 adds: *"On a 409, reload owned task state and show a conflict/review message."* "Let the user reconcile changes" is an unstated workflow. The document does not define whether the UI displays a visual diff, prompts the user with an overwrite/discard modal, or simply resets the form to server state.

---

### Finding 7: Assumed Frontend Architecture & Shared View Lifecycle
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 2.1 (Lines 50–51), Section 2.2 (Lines 74–75), Section 8 Phase 2 (Lines 404–405), Section 13 (Lines 614–615, 633)
* **Description:** The document states that `workspace.blade.php` has an older clarification renderer and `ai-media-input.js` has a newer card renderer, commanding the reader to *"Refactor toward one shared entity picker and master creation panel used by both."* However, it does not describe how these components are packaged or loaded (e.g., whether they are global jQuery/vanilla JS objects, ES modules, or Blade partials). An unfamiliar developer cannot tell how `ai-entity-picker.js` should attach to both views without colliding or duplicating state.

---

### Finding 8: Abstract Permission Names Instead of Concrete Spatie Capabilities
* **Severity:** 🟢 **Nit**
* **Affected Scope:** Section 1 (Line 22), Section 3.3 (Line 138), Section 4.1 (Line 190), Section 5 (Line 253), Section 11 (Line 522)
* **Description:** Mentions "required master-creation permissions", `can_create`, and "User lacks `warehouse` / approval permission". While Line 373 mentions `ai.action.approve`, the actual permission strings for creating warehouses, products, and suppliers (e.g., `warehouses-add`, `products-add`) are omitted.

---

### Finding 9: Unintroduced Project Jargon & Acronyms
* **Severity:** 🟢 **Nit**
* **Affected Scope:** Section 2.1 (Line 51), Section 5 (Lines 219, 226), Section 7 (Lines 369, 373)
* **Description:** "FY" is referenced in security boundaries ("company/branch/FY isolation") without defining it inline as Fiscal Year or explaining how it is represented in the session. Similarly, `buildTriadOptions()` and the "triad UX" are referenced without explaining what a "triad" represents in this codebase.

---

### Finding 10: Unspecified Resolution for Audit Timestamp Discrepancy
* **Severity:** 🟢 **Nit**
* **Affected Scope:** Section 7 (Line 371)
* **Description:** Line 371 notes: *"the inspected workflow bridge sets `confirmed_by`/`confirmed_at` when marking trusted preparation as `prepared`; review this semantic and ensure audit fields accurately distinguish machine processing from human confirmation."* Pointing out a flaw and telling the reader to "review this semantic" without stating what the fields should actually be set to leaves the implementer uncertain of the intended contract.
