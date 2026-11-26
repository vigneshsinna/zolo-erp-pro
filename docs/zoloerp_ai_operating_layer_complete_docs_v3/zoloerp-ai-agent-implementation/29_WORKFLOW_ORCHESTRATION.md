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
    READINESS --> PO_FLOW["Purchase Order AI Workflow"]
    PO_FLOW --> BILLS_SALES["Purchase Bills + Sales"]
    BILLS_SALES --> ERP_MODULES["Inventory + Accounting + Other ERP Modules"]
```

---

## Governed Intent-Proposal-Approval-Execution Lifecycle

Every non-read workflow follows this strict lifecycle:

```mermaid
flowchart TD
    INPUT["Image / Voice / Message"] --> NORM["Normalize + Authenticate"]
    NORM --> PLANNER["AI Intent and Workflow Planner"]
    PLANNER --> RESOLVE["Resolve Records + Validate with ERP"]
    RESOLVE --> PROPOSAL["Create Versioned Proposal"]
    PROPOSAL --> PREVIEW["Editable Preview + Human Review"]
    PREVIEW --> DECISION{"Human Decision"}

    DECISION -->|Change Request| EDIT_PROP["Edit Proposal + Increment Version"]
    EDIT_PROP --> RESOLVE

    DECISION -->|Cancel| CANCEL["Cancel without ERP Write"]

    DECISION -->|Approve| BIND["Bind Approval to Exact Payload Hash"]
    BIND --> REVALIDATE["Fresh Authorization, State + Lifecycle Checks"]
    REVALIDATE --> STILL_VALID{"Still Valid?"}

    STILL_VALID -->|No| REBUILD["Rebuild Preview + Require New Approval"]
    REBUILD --> RESOLVE

    STILL_VALID -->|Yes| EXECUTE["Idempotent Existing ERP Domain Command"]
    EXECUTE --> RECEIPT["Committed Receipt + Audit Trail"]
```

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
