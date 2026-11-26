# 29 — Durable Natural-Language Workflow Orchestration and Recovery

> **Specification version:** 3.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `14195d8b5b195f65ca1283c96794c613de88eded` (2026-11-11). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Orchestrator Responsibility
A multi-step natural language request is an **authoritative business workflow graph**, not an unrestricted autonomous agent loop. The orchestration engine tracks intent, typed slot extraction, entity disambiguation, live domain validation, versioned proposal state, cryptographic approval binding, and idempotent execution receipts.

---

## Phased Platform Architecture

Workflows operate within a phased progression:

```mermaid
flowchart TD
    COPILOT["Existing Read-Only ERP Copilot"] --> ACTION_FOUNDATION["Shared Action Foundation"]
    ACTION_FOUNDATION --> MODE_A["Mode A: Installation Admin Bootstrap"]
    MODE_A --> CREATE_COMP["Create Group + Company"]
    CREATE_COMP --> PROVISION_DEFAULTS["Company, Branch, FY + Industry Defaults"]
    PROVISION_DEFAULTS --> MODE_B["Mode B: Company-Scoped Setup"]
    MODE_B --> CONFIGURE_MASTERS["Units, Warehouses, Products, Parties + Tax/Accounts"]
    CONFIGURE_MASTERS --> READINESS["Setup Readiness Validation"]
    READINESS --> PO_FLOW["Phase 2: Purchase Order AI Workflow"]
    PO_FLOW --> UNIFIED_ENGINE["Phase 2.1: Unified Input Collection & Clarification\n(Generalized Pre-Proposal Task Engine)"]
    UNIFIED_ENGINE --> SALES_FLOW["Phase 2.1+: Sales Orders, Quotes & Invoices"]
    SALES_FLOW --> BILLS_FLOW["Phase 3: Purchase Bills & 3-Way Matching"]
    BILLS_FLOW --> ERP_MODULES["Phase 4: Inventory, Finance & Manufacturing"]
```

---

## Governed Intent-Proposal-Approval-Execution Lifecycle

Every non-read workflow follows this strict lifecycle, incorporating the Phase 2.1 Pre-Proposal Task Collection Engine:

```mermaid
flowchart TD
    INPUT["Image / Voice / Message"] --> NORM["Normalize + Authenticate Context"]
    NORM --> PLANNER["AI Intent & Slot Extraction"]
    PLANNER --> REQUIREMENTS["ActionRequirementsRegistry\n(PO, SO, Quote, Setup, etc.)"]
    REQUIREMENTS --> TASK_DRAFT["ai_workflow_tasks (Draft State)\nOptimistic Revision Lock"]
    TASK_DRAFT --> BATCH_RESOLVE["BatchRecordResolutionService\n(Single-Pass Vectorized Resolution)"]
    BATCH_RESOLVE --> EVAL{"InputCompletenessEvaluator\nAll Slots Ready?"}
    
    EVAL -- "No (Missing / Ambiguous)" --> CLARIFY["Consolidated Clarification Card\n(Details Required Fact Card)"]
    CLARIFY --> USER_PATCH["User Supplies Details / Batch Correction"]
    USER_PATCH --> MERGE["WorkflowTaskStateService\nMerge & Update Revision"]
    MERGE --> BATCH_RESOLVE
    
    EVAL -- "Yes (100% Ready)" --> PROPOSAL["Create Versioned Proposal\n(ai_action_proposals)"]
    PROPOSAL --> PREVIEW["Authoritative Commercial Preview + Human Review"]
    PREVIEW --> DECISION{"Human Decision"}

    DECISION -->|Change Request| EDIT_DRAFT["Re-open Task Draft in ai_workflow_tasks"]
    EDIT_DRAFT --> BATCH_RESOLVE

    DECISION -->|Cancel| CANCEL["Cancel Draft / Proposal without ERP Write"]

    DECISION -->|Approve| BIND["Bind Approval to Exact Payload Hash"]
    BIND --> REVALIDATE["Fresh Authorization, State + Lifecycle Checks"]
    REVALIDATE --> STILL_VALID{"Still Valid?"}

    STILL_VALID -->|No| REBUILD["Rebuild Preview + Require New Approval"]
    REBUILD --> BATCH_RESOLVE

    STILL_VALID -->|Yes| EXECUTE["Idempotent Existing ERP Domain Command\n(PO: CommercialApplicationService, SO: OrderLifecycleService)"]
    EXECUTE --> RECEIPT["Committed ERP Receipt + Audit Trail"]
```

### Pre-Proposal Task Draft vs. Sealed Proposal Invariant
1. **Mutable Draft Phase (`ai_workflow_tasks`):** Incomplete inputs, missing line quantities, ambiguous products, or unresolved parties remain in the mutable `ai_workflow_tasks` table under optimistic concurrency (`expected_revision`). No cryptographic approval proposal is generated while any blocking issues remain.
2. **Immutable Proposal Phase (`ai_action_proposals`):** Once `InputCompletenessEvaluator` returns zero blocking issues, the draft is promoted under database transaction lock (`SELECT ... FOR UPDATE`) into a sealed proposal with HMAC cryptographic seal, authoritative `CommercialPricing` calculation, and live SHA-256 source-state hash. Post-promotion mutations on the task draft are strictly rejected (HTTP 423 Locked).
3. **Enterprise-Wide Generalization:** This lifecycle governs all transactional inputs across Sales Orders, Quotations, Sales Invoices, Purchase Orders, Goods Receipts, Supplier Bills, Inventory Transfers, and In-Flight Master Setup.

---

## Short Transaction Lifetime Guarantee
The orchestrator strictly enforces short database transaction lifetimes:
1. **Zero Open Transactions During LLM Inference or Review:**
   - Under no circumstances is `DB::transaction()` held open while an LLM generates text, extracts slots, calls external provider APIs, or waits for human review.
2. **Atomic Execution Window:**
   - Database transactions occur solely at execution time inside the approved domain command adapter.
3. **Preserved Domain Service Ownership:**
   - When the underlying ERP service (such as `CompanyDirectoryService::createCompany` or `CommercialApplicationService`) already manages a transaction, the orchestrator delegates directly to that service's boundary without creating conflicting nested transactions.

---

## Mode A to Mode B Workflow Context Transition

1. **Mode A Execution (Bootstrap Context):**
   - Initial onboarding begins under `BootstrapSetupPrincipal` via dedicated bootstrap endpoints.
   - The user selects or creates a company group and reviews the company creation preview.
   - Upon explicit approval, `CompanyDirectoryService::createCompany` executes atomically, provisioning the company, MAIN branch, first FY, main warehouse, chart of accounts, industry settings, and starter masters.
2. **Context Transition Gate:**
   - The orchestrator validates the returned company receipt.
   - The session switches from `BootstrapSetupPrincipal` to a standard, verified `CompanyContext` via `CompanyContextResolver::forActor()`.
3. **Mode B Execution (Company Context):**
   - Subsequent configuration steps (extra branches/locations, custom UOM, products, parties, tax registrations) run as standard company-scoped action tasks, each requiring individual domain permissions and human sign-off.

---

## Change Requests & Proposal Versioning

When a user reviews a proposal in the AI Workspace or Action Review Center:
- **Change Request Path:** If the user alters quantities, items, or parameters, the orchestrator transitions the proposal to `ChangeRequest`, increments the version (`vN → vN+1`), and routes back to `Resolve Records + Validate with ERP`.
- **Revalidation Failure Path:** If live revalidation fails at execution time (e.g., stale row version, stock depleted, period closed), the orchestrator marks the proposal `Invalidated`, rebuilds the preview with current ERP data, and requires a fresh human approval.

---

## Dual UI Delivery: Workspace & Action Review Center
- **AI Workspace (`workspace.blade.php`):** Interactive conversational turn handling, slot disambiguation, live fact cards, and in-place change request editing.
- **Action Review Center (Dedicated Operational Screen):** Cross-module pending approval queue, multi-item approval, audit log inspection, and execution receipts.
