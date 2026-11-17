# Stage 7 Report: Critic Review & Structural Audit

**Target Document:** [`docs/ZOLOERP_AI_INTELLIGENT_ENTITY_RESOLUTION_UI_IMPLEMENTATION.md`](file:///v:/pers/Freelance/zolo-erp-pro/docs/ZOLOERP_AI_INTELLIGENT_ENTITY_RESOLUTION_UI_IMPLEMENTATION.md)  
**Evaluated Scope:** `FeatureRange` (Lines 1–376)  
**Reviewers:** `egm-design` (Hostile Design Critic), `egm-structural` (Document Architect), `egm-style` (Technical Copy Editor)  
**Standard:** Feature Specification Standard (`feature-spec-standard.md`)  
**Verdict:** **Failed Standard** (3 Blockers, 9 Majors, 14 Nits)

---

## 1. Prioritized Findings

### Finding 1: Total Absence of "Alternatives Considered" (The Alternatives Rule Violation)
* **Reviewers:** `egm-design`, `egm-structural`
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Lines 1–376 (Entire Feature Specification)
* **Critique:** The Feature Specification Standard mandates that all specifications include an evaluation of alternative architectures, failing any proposal that outlines only a single path without proving viable designs were considered and rejected. Three critical decisions lack alternative analysis:
  1. *Entity Lookup API:* Single polymorphic endpoint `GET /ai/entity-options?type=...` vs. hardening existing `/api/v1/*` endpoints vs. pre-populating candidates in task clarification payloads vs. domain-specific routes (`/ai/warehouses/options`).
  2. *Master Creation Approval Model:* Independent two-gate approval vs. atomic composite transactions (composite approval where master and bill commit/rollback together).
  3. *UI Component Architecture:* 11 separate Vanilla JS / jQuery classes vs. server-rendered Blade partials with Alpine.js/jQuery desk integrations.
* **Remediation:** Add a formal `## Alternatives Considered` section evaluating these three vectors with concrete technical trade-offs (latency, rollback complexity, caching, state coordination, and security blast radius).

---

### Finding 2: Missing Mandatory Section: "Risks & Mitigations"
* **Reviewer:** `egm-structural`
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Lines 1–376
* **Critique:** Under the Feature Specification Standard, potential failure modes, security implications, concurrency hazards, and scalability limits must be formally analyzed in a dedicated `Risks & Mitigations` section within the feature definition. While Section 11 presents a failure recovery matrix later in the document (line 515), the feature specification itself lacks this mandatory architectural section.
* **Remediation:** Introduce a structured `## Risks & Mitigations` section in the feature specification detailing database contention, privilege escalation, task desynchronization, and client dropouts.

---

### Finding 3: Missing Formal "Problem Statement" and "Goals & Non-Goals"
* **Reviewers:** `egm-design`, `egm-structural`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 1 (Lines 14–31)
* **Critique:** Section 1 lists operational behavior rules, but lacks formal `Problem Statement`, `Goals`, and `Non-Goals` boundaries. Key scope boundaries remain undefined:
  - Is batch master creation (e.g., creating 5 missing products in one bill) in scope or an explicit non-goal?
  - Is creating new branches, GST tax regimes, or financial chart of accounts a non-goal?
  - Are dynamic volumetric UOM conversions non-goals?
* **Remediation:** Restructure Section 1 into `1.1 Problem Statement`, `1.2 Goals`, and `1.3 Non-Goals`, explicitly bounding the milestone to single-master guided resolution and voucher-bound purchase bill workflows.

---

### Finding 4: Database Scalability & Full-Table Scans on Substring Searches
* **Reviewer:** `egm-design`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 5 (Lines 224–261), Section 6 (Lines 313–345)
* **Critique:** The proposed entity query endpoint executes unanchored `LIKE "%{$trimmed}%"` searches across master tables (`RecordResolutionService.php` lines 65–71, 140–145). Leading wildcards defeat B-Tree composite indexes (`company_id, name`), triggering full table scans on large product or party catalogs (50k+ records) with every debounced keystroke, creating severe database CPU and buffer pool contention.
* **Remediation:**
  1. Mandate a tiered search strategy: primary query uses prefix match (`name LIKE 'query%'` or `code LIKE 'query%'`) to leverage B-Tree indexes. Fall back to substring match only if prefix yields `< 5` candidates and `strlen(query) >= 3`.
  2. Reject queries where `strlen(query) < 2`.
  3. Include explicit additive composite index specifications in migrations: `(company_id, is_active, name)`.

---

### Finding 5: Entity Options Authorization & Privilege Escalation Vulnerability
* **Reviewer:** `egm-design`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 5 (Lines 220–261)
* **Critique:** `GET /ai/entity-options` accepts loose `type` and `field` query parameters without validating them against the identified task's unresolved slots. An actor with `purchases-add` permission could query `type=customer` or `type=settlement_account` to enumerate private parties, balances, or bank ledgers.
* **Remediation:** Enforce the following authorization sequence:
  1. Verify task ownership and tenancy via `WorkflowTaskStateService`.
  2. Cross-reference `field` against the action's registered requirements provider. If `field` is not an unresolved slot in that task, fail closed with `403 Forbidden`.
  3. Validate that `type` strictly matches the registered entity type in `DependentMasterOrchestrator::resolveFieldEntity($field)`.
  4. Assert granular Spatie domain permissions (`warehouses-index`, `suppliers-index`, `products-index`).

---

### Finding 6: Concurrency Desynchronization & Proposal Signature Invalidation on Resumption
* **Reviewer:** `egm-design`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 4.2 (Lines 194–205), Section 5.1 (Lines 278–289)
* **Critique:**
  1. `approveAndExecuteDependentMaster` increments the parent task revision (`$task->revision + 1`). If the client does not immediately adopt this new revision, subsequent corrections fail with `409 Conflict`.
  2. If a purchase bill proposal was previously generated (`linked_proposal_id`), binding a newly created master ID mutates underlying slots. The specification does not define how linked proposals in `pending` or `approved` status are invalidated and transitioned back to draft preview.
* **Remediation:**
  1. Mandate that the client adopts the returned `revision` directly from the execution response without local optimistic increments.
  2. Explicitly specify proposal invalidation: when a dependent master is bound, any linked proposal must revert to `pending` draft status, invalidating prior approval signatures and forcing re-preview.

---

### Finding 7: Indecisive Route Specification & Speculative Frontend Hedging
* **Reviewer:** `egm-style`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Lines 144, 220, 226
* **Critique:**
  - Line 144 hedges on frontend architecture ("unless the entire page is already being migrated"), contradicting repository rules (Blade + Bootstrap 4 + jQuery; no React).
  - Line 226 hedges on route definitions ("Optionally mirror under /api/v1... Use whichever route group matches...").
* **Remediation:**
  - `DROP` speculative React migration hedging. State definitively: Blade + Bootstrap 4 + jQuery.
  - `CALIBRATE` route registration: register `GET /ai/entity-options` strictly in `routes/web.php` under authenticated session middleware for Copilot Desk UI.

---

### Finding 8: Fragmented Architectural Hierarchy
* **Reviewer:** `egm-structural`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Sections 3, 4, 5, 6, 7 (Lines 90–375)
* **Critique:** Architectural specifications are fragmented across five separate top-level H2 sections (`## 3. Target UI`, `## 4. UI components`, `## 5. Proposed read API`, `## 6. Deterministic record-resolution policy`, `## 7. Source data, status and security model`) rather than structured under a cohesive architectural blueprint hierarchy.
* **Remediation:** Consolidate under a unified `## Proposed Architecture` section with clear logical subsections (Component Layout, API Contracts, Resolution Engine, Security Model).

---

### Finding 9: Sermonizing Critique and Vague Resolver References
* **Reviewer:** `egm-style`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Lines 68, 70, 76, 82
* **Critique:** Several sections employ moralizing language ("inappropriate for a financially material stock destination", "misleading") and vague references ("Some resolver methods scope to company but do not enforce...").
* **Remediation:** Replace with objective technical descriptions: "Can assign an incorrect stock destination when multiple branch warehouses exist" and name exact methods (`RecordResolutionService::resolveWarehouse`, `BatchRecordResolutionService::resolveWarehouseField`).

---

## 2. Summary & Checkpoint Verdict
The Feature Specification portion of the document fails compliance with the Feature Specification Standard due to the absence of `Alternatives Considered` and `Risks & Mitigations`. In addition, critical risks in database query indexing (unanchored LIKE searches) and endpoint privilege escalation must be mitigated before implementation begins.
