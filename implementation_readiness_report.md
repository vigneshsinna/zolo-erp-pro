# Stage 9 Report: Implementation Readiness & Codebase Verification

**Target Document:** [`docs/ZOLOERP_AI_INTELLIGENT_ENTITY_RESOLUTION_UI_IMPLEMENTATION.md`](file:///v:/pers/Freelance/zolo-erp-pro/docs/ZOLOERP_AI_INTELLIGENT_ENTITY_RESOLUTION_UI_IMPLEMENTATION.md)  
**Evaluated Scope:** `DetailedRange` (Lines 377–659)  
**Reviewers:** `egm-implementation-readiness` (Pragmatic Tech Lead), `egm-codebase-details` (Pedantic Code Auditor), `egm-style` (Technical Copy Editor)  
**Standard:** Implementation Readiness Standard (`implementation-readiness-standard.md`)  
**Verdict:** **Failed Standard** (4 Blockers, 13 Majors, 7 Nits)

---

## 1. Prioritized Findings

### Finding 1: Proposed `resolveWarehouse` Return Contract Breaks Existing Callers
* **Reviewers:** `egm-codebase-details`, `egm-implementation-readiness`
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Section 8 Phase 1 (Line 394), Section 9.1 (Lines 444–460)
* **Code Reference:** [`app/Ai/Services/BatchRecordResolutionService.php#L162-L170`](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Services/BatchRecordResolutionService.php#L162-L170), [`app/Ai/Services/RecordResolutionService.php#L199-L232`](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Services/RecordResolutionService.php#L199-L232)
* **Discrepancy Description:**
  Section 9.1 proposes an abstract result contract returning `'selected_id'` (integer) and `'candidates'` (array of arrays). However, `BatchRecordResolutionService::resolveWarehouseField()` strictly expects:
  ```php
  if ($res['status'] === 'exact' && $res['warehouse']) {
      $result->setResolved('warehouse_id', (int) $res['warehouse']->id, $res['warehouse']);
  } elseif ($res['status'] === 'candidates') {
      $candidates = $res['candidates']->map(fn(Warehouse $w): array => [
          'id' => (int) $w->id,
          'label' => $w->name,
          'branch_id' => (int) $w->branch_id,
      ])->all();
      $result->setCandidates('warehouse_id', $candidates, $query);
  }
  ```
  If `resolveWarehouse()` returns a primitive array instead of `Collection<int, Warehouse>`, `$res['candidates']->map(...)` throws a Fatal Error (`Call to a member function map() on array`), and `$batchResult->getEntity('warehouse_id')` becomes `null`, breaking label rendering in `InputCompletenessEvaluator`.
* **Remediation:** Retain the standard internal domain return shape:
  ```php
  return [
      'status' => 'exact' | 'candidates' | 'none',
      'warehouse' => ?Warehouse,
      'candidates' => Collection<int, Warehouse>,
      'recommendation_reason' => ?string,
      'match_kind' => ?string,
  ];
  ```
  This preserves complete backwards-compatibility with `BatchRecordResolutionService` while delivering candidates and recommendation reasons.

---

### Finding 2: Non-Existent API Reference — "Existing Async Execution Polling Endpoint"
* **Reviewer:** `egm-codebase-details`
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Section 9.3 (Lines 483–485)
* **Code Reference:** [`routes/api.php#L251-L258`](file:///v:/pers/Freelance/zolo-erp-pro/routes/api.php#L251-L258), [`app/Http/Controllers/Api/V1/AiChatController.php#L301-L345`](file:///v:/pers/Freelance/zolo-erp-pro/app/Http/Controllers/Api/V1/AiChatController.php#L301-L345)
* **Discrepancy Description:**
  Line 484 instructs: *"wait for an authoritative completed execution receipt, polling the existing async execution endpoint if configured."*  
  **No async execution polling endpoint exists in the codebase.** Proposal routes only support `POST approve`, `POST change-request`, `POST reject`, and `GET pdf`. If `ai_infrastructure.async_execution_enabled` is true, approval returns HTTP 202 (`queued`), but there is no endpoint to poll for execution completion. Citing a non-existent API breaks the Zero-Decision Rule.
* **Remediation:** Remove references to polling a non-existent endpoint. State clearly: for synchronous proposals (default), the approve response returns the completed execution receipt directly (`status: 'completed'`). If async queueing is active, display the queued receipt with proposal ID and version without speculative polling until an async query route is formally designed.

---

### Finding 3: Undecided API Routing, Namespace, and Controller Action
* **Reviewers:** `egm-implementation-readiness`, `egm-codebase-details`
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Section 8 Phase 2 (Line 402), Section 13 (Lines 617–625), Section 5 (Lines 224–226)
* **Discrepancy Description:**
  The plan leaves routing, middleware, and controller namespaces undecided:
  - Line 402: *"Implement `AiEntityOptionsController` (or an appropriately named read-only action)..."*
  - Lines 617–618: `routes/web.php # add scoped session route`, `routes/api.php # optional v1 equivalent`.
  - Line 624 proposes `app/Http/Controllers/Ai/AiEntityOptionsController.php`, whereas all existing AI API controllers reside in `app/Http/Controllers/Api/V1/`.
* **Remediation:** Lock in exact architecture:
  1. Controller: `app/Http/Controllers/Api/V1/AiEntityOptionsController.php`
  2. Route: `GET /api/v1/ai/entity-options` registered in `routes/api.php` under `auth:sanctum` and `company.context` middleware, with a web session route in `routes/web.php` under web auth middleware.
  3. Action: `public function __invoke(Request $request): JsonResponse`

---

### Finding 4: Warehouse Branch Isolation vs Resolution Fallback
* **Reviewer:** `egm-codebase-details`
* **Severity:** 🔴 **Blocker**
* **Affected Scope:** Section 8 Phase 1 (Lines 394–395)
* **Code Reference:** [`app/Ai/Services/RecordResolutionService.php#L199-L232`](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Services/RecordResolutionService.php#L199-L232), [`app/Ai/Adapters/PurchaseBillPreviewAdapter.php#L67-L74`](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Adapters/PurchaseBillPreviewAdapter.php#L67-L74)
* **Discrepancy Description:**
  `RecordResolutionService::resolveWarehouse` currently queries `Warehouse::where('company_id', $context->companyId)` without scoping by `branch_id`. However, `PurchaseBillPreviewAdapter` strictly enforces:
  ```php
  $warehouse = Warehouse::where('company_id', $context->companyId)
      ->where('branch_id', $context->branchId)
      ->whereKey($warehouseId)
      ->first();
  ```
  If candidate queries return company-wide warehouses from other branches, a user selecting one will immediately fail bill preview with a 422 error (`"Warehouse not found under current company."`).
* **Remediation:** In `resolveWarehouse()` and the entity options service, enforce `$query->where('branch_id', $context->branchId)` as the primary active candidate scope. Only include warehouses matching the active actor's authorized branch.

---

### Finding 5: `DependentMasterOrchestrator` Warehouse Prefill Strips `branch_id`
* **Reviewer:** `egm-codebase-details`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 8 Phase 3 (Lines 411–417)
* **Code Reference:** [`app/Ai/Services/DependentMasterOrchestrator.php#L137-L142`](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Services/DependentMasterOrchestrator.php#L137-L142)
* **Discrepancy Description:**
  In `DependentMasterOrchestrator::previewMasterProposal()`:
  ```php
  if ($entityMeta['entity_type'] === 'warehouse') {
      unset($masterData['branch_id']);
  }
  ```
  The orchestrator unsets `branch_id`. If `setup.warehouse.create.v1` defaults to a different branch than the parent task's `$context->branchId`, the newly created warehouse cannot be used by `PurchaseBillPreviewAdapter`.
* **Remediation:** Update `DependentMasterOrchestrator::previewMasterProposal` to preserve and explicitly bind `$context->branchId` for warehouse masters so the new warehouse belongs to the transaction's active branch.

---

### Finding 6: Omission of `InputCompletenessEvaluator` Search Endpoint Update
* **Reviewer:** `egm-codebase-details`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 8 Phase 2 (Lines 400–408)
* **Code Reference:** [`app/Ai/Services/InputCompletenessEvaluator.php#L418`](file:///v:/pers/Freelance/zolo-erp-pro/app/Ai/Services/InputCompletenessEvaluator.php#L418)
* **Discrepancy Description:**
  `InputCompletenessEvaluator::buildTriadOptions()` hardcodes:
  ```php
  'warehouse' => ['warehouse', 'setup.warehouse.create.v1', '/api/v1/warehouses'],
  ```
  When the new entity options endpoint is implemented, this hardcoded path must be updated to `/api/v1/ai/entity-options?type=warehouse`.
* **Remediation:** Add `app/Ai/Services/InputCompletenessEvaluator.php` to Phase 2 file modifications and update the search endpoint emission.

---

### Finding 7: Missing Domain Adapters in File Boundary Manifest
* **Reviewer:** `egm-codebase-details`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 13 (Lines 603–633)
* **Code Reference:** `app/Ai/Adapters/PurchaseBillPreviewAdapter.php`, `app/Ai/Adapters/PurchaseBillCommandAdapter.php`
* **Discrepancy Description:**
  The adapters are referenced across acceptance criteria and recovery rules, but omitted from Section 13's file change list, violating the File Boundary requirement of the standard.
* **Remediation:** Add both adapters to the Section 13 manifest or clarify that their existing validation logic is reused as-is without code edits.

---

### Finding 8: Speculative Scope and Ambiguous File Boundary Manifest
* **Reviewer:** `egm-implementation-readiness`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 13 (Lines 612, 621, 627, 632)
* **Discrepancy Description:**
  Contains subjective, conditional language ("only if needed", "New files if justified", "ai-master-create.js or module in picker"), failing the write allowlist requirement.
* **Remediation:** Eliminate all conditional language. Specify exact files to create, modify, or retain.

---

### Finding 9: Pseudocode & Undefined Implementation for `patchWorkflowTask`
* **Reviewer:** `egm-implementation-readiness`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 9.2 (Lines 463–477)
* **Discrepancy Description:**
  Leaves client-side HTTP dispatch, CSRF headers, and error handling as developer pseudocode ("create it in the shared JS module or adapt your existing jQuery $.ajax").
* **Remediation:** Provide the concrete JavaScript implementation of `patchWorkflowTask(taskId, expectedRevision, corrections)` directly inside `public/js/ai-media-input.js`.

---

### Finding 10: Abstract Specification for Phase 4 Multi-Entity Expansion
* **Reviewer:** `egm-implementation-readiness`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 8 Phase 4 (Lines 420–429)
* **Discrepancy Description:**
  Phase 4 lists broad goals for suppliers, customers, products, units, and PO/GRN matching without schemas, query parameters, or concrete contracts.
* **Remediation:** Bound the active specification strictly to Phases 0–3 (Warehouse resolution, contract bug fix, master creation resumption). Mark Phase 4 as an explicit future non-goal.

---

### Finding 11: Test Plan Lacks Target Methods, Payloads, and Assertions
* **Reviewer:** `egm-implementation-readiness`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Section 8 Phase 0 (Line 385), Section 12.1 (Lines 536–559)
* **Discrepancy Description:**
  Section 12.1 lists 12 plain-English test descriptions without specifying target test class names, method signatures, JSON request payloads, or assertions.
* **Remediation:** Map each test case to a specific method name in `tests/Feature/Ai/Clarification/UnifiedInputClarificationTest.php` and `tests/Feature/Ai/Clarification/DependentMasterResolutionTest.php` with concrete HTTP and database assertions.

---

### Finding 12: Vendor-Specific Jargon and Persona Leakage in Section Headings
* **Reviewer:** `egm-style`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Line 442 (`## 9. Minimum code-level requirements for Codex`) and Line 637 (`## 14. Implementation instructions for Codex`)
* **Discrepancy Description:** Introduces external LLM model names into permanent engineering specifications.
* **Remediation:** Rename to `## 9. Code-level contract requirements` and `## 14. Implementation guidelines`.

---

### Finding 13: Prompt-Leaked Defensive Admonition & Codebase Critique
* **Reviewer:** `egm-style`
* **Severity:** 🟡 **Major**
* **Affected Scope:** Lines 597, 633, 639–641
* **Discrepancy Description:** Contains conversational directives ("Do not claim tests passed without running them", "wholesale rewrite of the 1,700-line media JS", "Do not hide failures behind fake success UI").
* **Remediation:** Remove conversational directives and state technical requirements directly in factual engineering prose.

---

## 2. Summary & Readiness Verdict
The plan directly identifies the critical P0 `slots_patch` contract bug and unsafe warehouse `first()` fallback. However, it fails the Implementation Readiness Standard due to:
1. Conflating internal domain return shapes with API contracts in `RecordResolutionService` (which would break `BatchRecordResolutionService`).
2. Referencing a non-existent async execution polling route.
3. Undecided routing paths and middleware.
4. Omitting branch scoping in warehouse resolution and prefill.
Applying these concrete remediations will make the implementation plan 100% executable without developer guesswork.
