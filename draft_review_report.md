# Goldfish Swarm Document Review Report

**Target:** `design_draft.md` (Lines 1–443)  
**Mode:** `DESIGN_DRAFT`  
**Synthesizer:** `egm-synthesizer`  
**Review Pass:** Multi-Lens Deep Audit (7 Lenses Completed)

---

## Reviewer Status

| Reviewer | Lens Focus | Status |
|---|---|---|
| `egm-design` | Architectural Coherence, State Machines & Security Boundaries | **completed** |
| `egm-codebase-details` | Repository Alignment, Real File Paths, Signatures & Tenancy Laws | **completed** |
| `egm-zoom-out` | Strategic Alignment, Ecosystem Coherence & Operational Complexity | **completed** |
| `egm-comprehension` | Clarity, Ambiguities, Implicit Assumptions & Cognitive Load | **completed** |
| `egm-implementation-readiness` | Migration Feasibility, Concrete Interfaces & Testability | **completed** |
| `egm-structural` | RFC/TDD Structure, Mermaid Diagram Precision & Section Flow | **completed** |
| `egm-style` | Conventions, Terminology, Naming Consistency & Tone | **completed** |

---

## Severity Summary

| Severity | Count | Primary Areas |
|---|:---:|---|
| 🔴 **Blocker** | 3 | Existing Migration Collision (Tenancy Law 4), Mode A Bootstrap Proposal Scoping, Non-Existent `CompanyDirectoryService::createCompany` Preview Method |
| 🟡 **Major** | 6 | HMAC Null Context Normalization, Undefined `source_state_hash` Contract, Missing Adapter Interfaces, Context Switch / Route Parity, `UsesAiMysql` Transaction Level Testing, Change Request Entrypoint Routing |
| 🟢 **Nit** | 4 | Column Naming Consistency (`payload` vs `payload_json`), Diagram "Zero DB Mutation" Label, Canonical Date Formatting, Open Question Q-2 Resolution |
| **Total Findings** | **13** | **3 Blockers, 6 Majors, 4 Nits** |

---

## Prioritized Findings (Blockers -> Major -> Nits)

### 1. Database Migration Collision with Pre-Existing Tables (Tenancy Law 4) — Severity: Blocker
* **Reviewer Lens:** `egm-codebase-details`, `egm-implementation-readiness`
* **Affected Scope:** Section 4: Database Schema
* **Discrepancy Description:**  
  The draft provides raw `CREATE TABLE` DDL for `ai_action_proposals`, `ai_action_approvals`, and `ai_action_executions`. However, migration `database/migrations/2026_11_10_000005_create_ai_action_proposals_tables.php` has **already created** these three tables on both the development database (`zoloerp`) and disposable test databases. Furthermore, the existing tables have different column schemas:
  - In existing tables: `company_id`, `branch_id`, and `financial_year_id` are `unsignedBigInteger` and strictly `NOT NULL`.
  - Columns are `action_type`, `action_title`, `payload_json`, and `preview_json` rather than `action_key`, `version`, `payload_hash`, and `source_state_hash`.
  - In `ai_action_approvals`, there is no `version` or `approval_signature`.
  - In `ai_action_executions`, the column is `executor_id` rather than `executed_by_user_id`.
  
  Executing `CREATE TABLE` will fail immediately with `Table 'ai_action_proposals' already exists`. Modifying the historical 2026-11-10 migration directly violates **Tenancy Law 4** ("Additive Migrations Only: Never modify or delete historical database migrations").
* **Rationale & Recommendation:**  
  Replace the raw `CREATE TABLE` DDL with an explicit additive migration specification: `database/migrations/2026_11_11_000001_upgrade_ai_action_proposals_schema.php`. Use `Schema::table(...)` to:
  1. Alter `company_id`, `branch_id`, and `financial_year_id` to be nullable (`->nullable()->change()`).
  2. Add `version`, `payload_hash`, `source_state_hash`, and `action_key` (or standardize on `action_type`).
  3. Add `approval_signature` and `version` to `ai_action_approvals`.
  4. Expand `ai_action_executions` with `domain_service`, `domain_method`, `erp_resource_type`, `erp_resource_id`, and `duration_ms`.

---

### 2. Mode A Bootstrap Proposal Scoping & Cross-Admin Boundary Vulnerability — Severity: Blocker
* **Reviewer Lens:** `egm-design`, `egm-zoom-out`
* **Affected Scope:** Section 3.A & Section 4
* **Discrepancy Description:**  
  In Mode A (Pre-Company Bootstrap), proposals will have `company_id = NULL`. Standard proposal retrieval queries in `ActionProposalService` and `AiChatController` scope by `company_id`. With `company_id = NULL`, queries cannot use tenant scoping. The draft does not specify how Mode A proposals are retrieved, authorized, or protected from cross-admin inspection. If multiple installation admins run bootstrap tasks concurrently, proposals could collide or be visible across administrative actors.
* **Rationale & Recommendation:**  
  Explicitly specify the Mode A proposal ownership contract in Section 3.A and Section 4:
  1. Add `actor_id` (`BIGINT UNSIGNED NOT NULL`) and `task_token` (`CHAR(64) NULL`) to the proposal table.
  2. For Mode A bootstrap tasks, proposals MUST be queried strictly via `where('actor_id', $bootstrapPrincipal->actorId)->whereNull('company_id')->where('task_token', $taskToken)`.
  3. Add a dedicated compound index: `INDEX idx_proposals_bootstrap (actor_id, action_key, status)`.

---

### 3. Non-Existent Preview Method in `CompanyDirectoryService` — Severity: Blocker
* **Reviewer Lens:** `egm-codebase-details`, `egm-implementation-readiness`
* **Affected Scope:** Section 5: API Contracts
* **Discrepancy Description:**  
  Line 291 states that `POST /api/v1/ai/setup/bootstrap/tasks/{id}/preview` "calls `CompanyDirectoryService::createCompany` preview; returns calculated defaults." In `app/Services/Platform/CompanyDirectoryService.php`, `createCompany()` has no preview mode or simulation dry-run flag. Invoking `CompanyDirectoryService::createCompany` immediately opens a database transaction and performs writes across `companies`, `branches`, `fiscal_years`, `warehouses`, `chart_of_accounts`, and `company_industry_settings`.
* **Rationale & Recommendation:**  
  Update Section 3 and Section 5: Specify that the preview adapter (`CompanyBootstrapPreviewAdapter`) computes the simulated defaults (e.g. generating canonical uppercase company code via `CompanyDirectoryService::code()`, financial year naming via `yearName()`, default warehouse name, and tax registration eligibility) directly using `IndustryCatalog` and string utilities without executing `createCompany()`.

---

### 4. Non-Deterministic HMAC Approval Signature for Mode A (Nullable Context) — Severity: Major
* **Reviewer Lens:** `egm-design`, `egm-implementation-readiness`
* **Affected Scope:** Section 3.C
* **Discrepancy Description:**  
  In Mode A, `$companyId`, `$branchId`, and `$fyId` are `null`. In PHP string concatenation, `null` becomes `""` (empty string). If the client, server, or different runtime layers concatenate nulls differently (e.g. `"0"`, `"null"`, or `""`), signature verification will fail non-deterministically.
* **Rationale & Recommendation:**  
  Explicitly define canonical string casting rules for nullable context parameters:
  ```php
  (string) ($companyId ?? '0') . ':' . (string) ($branchId ?? '0') . ':' . (string) ($fyId ?? '0')
  ```
  Use colon separators between fields in the concatenated payload to prevent delimiter collision vulnerabilities.

---

### 5. Undefined `source_state_hash` Computation Contract — Severity: Major
* **Reviewer Lens:** `egm-comprehension`, `egm-implementation-readiness`
* **Affected Scope:** Section 3.C & Section 4
* **Discrepancy Description:**  
  `source_state_hash` is introduced as a required component of both the HMAC signature and the proposal table (`VARCHAR(64) NULL`), but the specification never defines how it is calculated for different action categories:
  - For new record creations (Mode A company creation, Phase 2 Purchase Order creation), there is no pre-existing database record.
  - For amending existing draft vouchers (CUJ 3), what attributes constitute the state?
* **Rationale & Recommendation:**  
  Add an explicit definition in Section 3.C:
  - For creation actions: `source_state_hash = hash('sha256', 'NEW_RECORD')`.
  - For record modification actions: `source_state_hash = hash('sha256', $record->getTable() . ':' . $record->id . ':' . $record->updated_at->timestamp . ':' . $record->status)`.
  - During execution revalidation, `ApprovedActionExecutor` re-computes this hash and strictly aborts with `STATE_STALE_INVALIDATED` if the hash has changed.

---

### 6. Missing Interface Contracts for Action Adapters (`ActionDefinition`) — Severity: Major
* **Reviewer Lens:** `egm-implementation-readiness`, `egm-structural`
* **Affected Scope:** Section 3.B
* **Discrepancy Description:**  
  `ActionDefinition` specifies `public string $previewAdapterClass` and `public string $commandAdapterClass`. However, no interface definitions (`ActionPreviewAdapterInterface`, `ActionCommandAdapterInterface`) or return type contracts are documented. Without clear contracts, adapter implementations across Mode A, Mode B, and subsequent phases will diverge.
* **Rationale & Recommendation:**  
  Define explicit PHP interfaces in Section 3.B:
  ```php
  interface ActionPreviewAdapterInterface
  {
      public function preview(ActionDefinition $definition, array $slots, CompanyContext|BootstrapSetupPrincipal $context): ActionPreviewResult;
  }

  interface ActionCommandAdapterInterface
  {
      public function execute(ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context, int $actorId): ActionExecutionReceipt;
  }
  ```

---

### 7. Transition URL & Session Context Re-Anchoring Route Mismatch — Severity: Major
* **Reviewer Lens:** `egm-codebase-details`, `egm-zoom-out`
* **Affected Scope:** Section 5
* **Discrepancy Description:**  
  The response payload in Mode A approval returns `"transition_url": "/backend/ai/workspace?company_id=12"`.
  In `routes/web.php`, the AI workspace route is `/ai` (`route('ai.workspace')`), and context switching in zoloERP is performed via a POST request to `route('company.choose')` (`/select-company`), not by passing a query parameter `?company_id=12` to a web view. If a browser is redirected to `/backend/ai/workspace?company_id=12`, it will receive an HTTP 404, and the session context will not be switched.
* **Rationale & Recommendation:**  
  Update Section 5: Specify that the response provides structured transition metadata:
  ```json
  {
      "status": "completed",
      "company_id": 12,
      "company_code": "ABCTX",
      "transition_action": "switch_context",
      "context_switch_url": "/select-company",
      "workspace_url": "/ai"
  }
  ```
  The client invokes the context switch endpoint (setting the active session context) before redirecting to `route('ai.workspace')`.

---

### 8. `UsesAiMysql` Outer Transaction Conflict with `DB::transactionLevel() === 0` Assertion — Severity: Major
* **Reviewer Lens:** `egm-codebase-details`, `egm-implementation-readiness`
* **Affected Scope:** Section "Verification & Testing Plan"
* **Discrepancy Description:**  
  Section 7 states: "Query listener asserts `DB::transactionLevel() === 0` during all non-commit phases."
  However, all AI feature tests use `Tests\Support\UsesAiMysql`, which leverages Laravel's `DatabaseTransactions` trait. `DatabaseTransactions` wraps the entire test method inside an outer database transaction, meaning `DB::transactionLevel()` is at least `1` throughout test execution. An assertion of `DB::transactionLevel() === 0` will fail immediately.
* **Rationale & Recommendation:**  
  Clarify the assertion criteria in Section 7:
  - For `ShortTransactionLifetimeTest.php`, tests must either run without `DatabaseTransactions` (performing manual tear-down on the disposable test schema `zolo_test_*`), OR assert that `DB::transactionLevel() === $baseFixtureLevel` (where base level is 1).

---

### 9. Ambiguity in "Change Request" Entrypoint (Chat vs Structured POST) — Severity: Major
* **Reviewer Lens:** `egm-comprehension`, `egm-design`
* **Affected Scope:** CUJ 2 & Section 5
* **Discrepancy Description:**  
  CUJ 2 describes a conversational revision ("operator asks the AI to prepare a PO... then decides to change the quantity to 150"), but Section 5 defines `POST /api/v1/ai/proposals/{id}/change-request: Submits slot adjustments`. It is unclear whether conversational messages route to `/api/v1/ai/chat` (which inspects thread state and updates the proposal) or if the UI sends structured slot adjustments directly to the change-request endpoint.
* **Rationale & Recommendation:**  
  Document the dual-entrypoint workflow clearly in Section 2 and Section 5:
  1. **Conversational Intake:** User submits natural language ("change quantity to 150") to `POST /api/v1/ai/chat`. The planner detects an active proposal in the thread and delegates to `ActionProposalService::applyChangeRequest($proposalId, $extractedSlots)`.
  2. **Direct Card Edit:** User edits inputs on the Fact Card / Review Center and clicks "Update Preview", dispatching structured JSON directly to `POST /api/v1/ai/proposals/{id}/change-request`. Both pathways converge on `ActionProposalService`, incrementing the version (`vN -> vN+1`).

---

### 10. Database Schema Column Naming Inconsistency (`payload` vs `payload_json`) — Severity: Nit
* **Reviewer Lens:** `egm-style`
* **Affected Scope:** Section 4
* **Discrepancy Description:**  
  In `ai_action_proposals`, the column is named `payload`, whereas in `ai_action_executions` it is `receipt_payload`, and in existing migrations it is `payload_json`.
* **Rationale & Recommendation:**  
  Standardize on consistent JSON column suffixes across all AI tables: `payload_json`, `preview_json`, and `receipt_json`.

---

### 11. Diagram 2 Label Clarification ("Zero DB Mutation") — Severity: Nit
* **Reviewer Lens:** `egm-structural`
* **Affected Scope:** Mermaid Diagram
* **Discrepancy Description:**  
  The diagram node states `Cancel (Zero DB Mutation)`. Cancelling a proposal updates `status = 'cancelled'` on `ai_action_proposals`, which is a database write.
* **Rationale & Recommendation:**  
  Update label to `Cancel (Zero ERP Ledger Mutation)` to accurately describe the accounting invariant.

---

### 12. Canonical Date Formatting in Critical User Journey 1 — Severity: Nit
* **Reviewer Lens:** `egm-style`
* **Affected Scope:** CUJ 1
* **Discrepancy Description:**  
  "FY starting April 1, 2026."
* **Rationale & Recommendation:**  
  Express canonical dates as `fy_start: "2026-04-01"` and `fy_end: "2027-03-31"` to mirror `CompanyDirectoryService` validation rules (`date_format:Y-m-d`).

---

### 13. Resolution Framework for Open Question Q-2 (Bulk Proposal Gating) — Severity: Nit
* **Reviewer Lens:** `egm-zoom-out`, `egm-comprehension`
* **Affected Scope:** Open Questions
* **Discrepancy Description:**  
  Leaving bulk approval gating as an open question risks ad-hoc implementations when bulk imports arrive in Phase 6.
* **Rationale & Recommendation:**  
  Provide an explicit decision rule: single-click batch approval is permitted only for low-risk tier (`r1_draft`) master data up to a maximum of 25 records. Any commercial, stock-moving, or financial transactions strictly require individual transaction previews.

