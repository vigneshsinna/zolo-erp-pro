# 04 — Intelligent ERP Entity Lookup and Unified Copilot UI

**Primary user story:** “I uploaded a purchase bill but don't know which warehouse to choose. Show me the available warehouses next to the bill. If there isn't a suitable one, help me create it without restarting.”

**This file is the new architecture-level delivery spec.** `11_ENTITY_UI_DETAILED_REFERENCE.md` is the earlier full implementation guide and UI acceptance reference. Reconcile its older Git baseline before editing.

## 1. Verified frontend/backend gaps in the inspected branch

- `public/js/ai-media-input.js` has `renderUnifiedWorkflowCard`, including `renderProposalView`, `renderClarificationView`, `renderEditView` and `renderBookedView`. **Reuse that card.**
- Unified clarification currently renders a simple `<select>` from pre-supplied `candidates`, otherwise a text input; no browse-all searchable directory.
- `renderEditView()` uses plain text for Supplier/Warehouse, and the save payload omits their IDs.
- **P0 contract mismatch:** `renderClarificationView()` and `renderEditView()` send `slots_patch` to `PATCH /ai/workflow-tasks/{id}/inputs`; `AiWorkflowTaskController::updateInputs()` requires a `corrections` array and `expected_revision`.
- Current proposal and booked views include hard-coded warehouse fallback strings such as `Main Warehouse` even if no authoritative warehouse match exists.
- `resources/views/backend/ai/workspace.blade.php` has an older three-way `select_existing/create_new/correct_name` UI and dependent-master actions. The unified card should use **the same shared UI components**, not duplicate the implementation.
- `RecordResolutionService::resolveWarehouse()` can return the **first** partial name match. This must be corrected on the backend before UI improvements go live.

## 2. Target UX: same persistent bill review card

```text
┌────────────────────────────── Purchase Bill Review ───────────────────────────────┐
│ [Invoice preview]                          Status: Details required (1 issue)     │
├───────────────────────────────────────┬──────────────────────────────────────────┤
│ Supplier: ABC Traders                 │ Destination Warehouse                    │
│ Invoice: INV-1001                     │ [Search existing warehouses...]          │
│ Invoice date: ...                     │ ( ) lenz - Main       Branch 61           │
│ Products / HSN / Qty / Rate           │ ( ) lenz - Storage    Branch 61           │
│ GST / discount / roundoff             │ [View more] [Create new warehouse]       │
│ Bill total                            │                                          │
│ [View original document]              │ [Use selected]                           │
├───────────────────────────────────────┴──────────────────────────────────────────┤
│ Final authoritative preview → [Edit] [Approve & Create] (only when ready)        │
└──────────────────────────────────────────────────────────────────────────────────┘
```

**Illustrative names only**: the UI must query actual current-company records.

Mobile: a single-column review and a bottom sheet/drawer for entity search; the submit action is sticky only when keyboard and viewport allow it, with accessible focus management.

## 3. Required reusable components

Build as small, composable Blade partials + JS modules consistent with the existing Blade/jQuery frontend. Do not introduce a second UI framework without an explicit migration decision.

| Component | Purpose | States |
|---|---|---|
| `AiWorkflowReviewCard` | One card for all stages | processing, clarification, review, executing, booked, blocked |
| `AiEntityPicker` | Search/select authorized ERP record | loading, results, recommended, no matches, error, stale, selected |
| `AiEntityOption` | Row with name, code, branch and optional metadata | selectable, disabled, current, recommended |
| `AiSelectionSummary` | Displays bound record and selection reason | verified, configured_default, user_selected |
| `AiMasterCreatePanel` | Governed creation of warehouse/supplier/product/customer/unit | form, duplicate_check, proposal_preview, awaiting_approval, created, failed |
| `AiClarificationIssue` | Renders schema-driven unresolved field | missing, ambiguous, invalid, permission_denied |
| `AiInvoiceComparison` | Original attachment alongside resolved ERP fields | collapsed/expanded, field-highlight |
| `AiValidationSummary` | Blocking errors, differences, warnings | blocking, warning, verified |
| `AiApprovalFooter` | Edit, cancel, final approved action | disabled, ready, submitting, completed |
| `AiTaskResumeStatus` | Tracks resumed parent task after master creation | in_progress, resumed, needs_more_input |

### Proposed JS/Blade organization (new files, subject to repository convention review)

```text
resources/views/backend/ai/partials/
  entity-picker.blade.php
  entity-option.blade.php
  master-create-panel.blade.php
  validation-summary.blade.php
public/js/
  ai-entity-picker.js
  ai-master-create.js
  ai-workflow-card.js   # optional extraction from ai-media-input.js
public/css/pages/
  ai-workspace.css      # enhance existing CSS rather than creating disconnected themes
```

Wire these into `public/js/ai-media-input.js` and `resources/views/backend/ai/workspace.blade.php`. Consolidate duplicate renderers when possible.

## 4. EntityPicker contract

```ts
// Design contract; implement in plain JavaScript/jQuery if that is the project's frontend stack.
type EntityPickerProps = {
  entityType: 'warehouse' | 'supplier' | 'product' | 'customer' | 'unit';
  taskId: string;
  taskRevision: number;
  fieldPath: string; // e.g., warehouse_id or items[line-0].product_id
  selectedId: number | null;
  query: string;
  onSelect: (id: number, source: 'user_selected') => void;
  canCreate: boolean;
  onCreateRequested: () => void;
};
```

Accessibility:

- Visible label, keyboard-search, arrow keys, Enter selection, Escape close, proper `aria-expanded`/`aria-controls`/`role=listbox` or equivalent accessible radio group.
- Results indicate branch and availability; never use red/green alone to indicate eligibility.
- “No matching warehouses” must distinguish **empty database** from **network error** or **permission denied**.
- Long names truncate visually only, not in the accessible label.
- Disable final approval whenever any required field is invalid or stale.
- Only persist the **numeric ERP ID returned by the server**, never arbitrary label text as a resolved foreign key.

## 5. Read-only authorized entity-search API

Prefer adding a dedicated consistent AI endpoint instead of attempting to reuse unrelated public search UIs. Verify route auth and policy before implementation.

**Proposal:**

```http
GET /api/v1/ai/entities/warehouses?q=lenz&task_id={taskUuid}&limit=20&cursor=...
Accept: application/json
```

**Illustrative response contract:**

```json
{
  "entity_type": "warehouse",
  "items": [
    {"id": 51, "display": "lenz - Main", "code": "WH-01", "branch_id": 61,
     "branch_label": "Branch 61", "eligible": true, "match_type": "exact_name"},
    {"id": 52, "display": "lenz - Storage", "code": "WH-02", "branch_id": 61,
     "branch_label": "Branch 61", "eligible": true, "match_type": "partial_name"}
  ],
  "recommended_id": null,
  "reason": "multiple_eligible_matches",
  "can_create": true,
  "next_cursor": null
}
```

**Mandatory server rules:**

- Construct principal/context from authenticated session/token and task. Ignore client-supplied company/actor values as authorization.
- Verify task belongs to the actor/current tenant and resolve the target action's actual warehouse eligibility.
- Restrict fields to safe directory metadata; never return other tenants' data, secrets, confidential pricing or unrestricted supplier contact details.
- Use safe parameterized filtering, normalized pagination, fixed maximum results and a per-actor rate limit.
- Return existing candidates **even when the normalized extraction had no warehouse name**; otherwise users must guess.
- Create permission is an independent policy, not authorization to create from the GET request.

## 6. Corrections: fix the existing contract first

**Actual inspected controller contract:**

```http
PATCH /api/v1/ai/workflow-tasks/{taskId}/inputs
Content-Type: application/json
```

```json
{
  "expected_revision": 3,
  "corrections": [
    {"field": "warehouse_id", "value": 51, "confirmation": "user_selected"}
  ]
}
```

Implementation instructions:

1. Replace `slots_patch` in both current `ai-media-input.js` code paths with `corrections`.
2. Preserve line-specific paths (e.g., `items[line-0].product_id`) and exact type expectation.
3. Use server returned `evaluation.is_ready`; call `prepare-proposal` **only when true**. When false, rerender outstanding issues in the same card.
4. On `409 CONCURRENT_TASK_MODIFICATION`, GET latest task, display differences and let user intentionally retry after reviewing; avoid blind replay.
5. If the user changes warehouse or supplier in Edit Bill, actually send `warehouse_id` and `supplier_id` corrections and force a new proposal preview/version.
6. If `proposal_created`, adapt the response to one canonical view model; ensure task/revision is preserved after renderer refresh.

## 7. Matching and selection rules

| Case | Behaviour |
|---|---|
| One **exact unique eligible** match | Select automatically, explain reason, show Change |
| Explicit configured and eligible default | May select automatically; highlight source and allow Change |
| One partial match | Present as suggestion, not an automatic confirmed selection |
| Multiple matches | Show selectable cards, do not approve until chosen |
| No match | Display browse-all / clear search / Create New (if permitted) |
| Inactive or wrong-branch result | Never select; omit or explain disabled state without private details |
| Rejected/removed master | Mark stale, invalidate proposal preview, ask for replacement |

## 8. Missing-master UI and workflow

Use the existing `DependentMasterOrchestrator` APIs, not arbitrary direct `POST /warehouses` from the model:

```text
needs_input(warehouse_id)
 -> click Create Warehouse
 -> POST /workflow-tasks/{id}/dependent-masters/spawn
 -> prefilled requirements + authorized defaults
 -> user fills required fields + duplicate review
 -> POST /workflow-tasks/{id}/dependent-masters/preview
 -> user reviews exact master proposal
 -> POST /workflow-tasks/{id}/dependent-masters/approve-and-execute
 -> server creates master, binds ERP ID to parent task
 -> re-evaluate parent
 -> if ready, auto prepare bill preview; otherwise show remaining issues
 -> final separate bill approval & execution
```

**Safety:** Master creation requires separate permission and signed approval, and does not count as permission to post the bill. Don't claim a single composite approval exists yet.

## 9. Invoice review component requirements

Display real ERP values: supplier ID/name/GSTIN, invoice number and date, company, warehouse, line descriptions, matched product code, HSN/UOM, quantities, rates, discounts, CGST/SGST/IGST, totals/round-off, PO/GRN relationship, tax reconciliation warning and original document preview. User may edit if permitted, but editing requires re-evaluation and a fresh proposal version. Tax and stock consequences come from preview adapters, not from the model.

**Remove all hardcoded placeholders** such as `Main Warehouse` in proposal, edit and booked states. Show `Not selected` when unresolved; show actual warehouse from authoritative receipt after booking.

## 10. Example browser scenarios

```text
GIVEN user uploads a bill with no warehouse
WHEN current branch has exactly one eligible configured warehouse
THEN the review displays it selected with a reason and Change option

GIVEN a company has three eligible warehouses
WHEN user opens Destination Warehouse
THEN the right-hand picker shows all three, can search and select by ID
AND bill approval stays disabled until a required selection is resolved

GIVEN no warehouse matches
WHEN user has master creation permission
THEN Create New opens a previewable creation form with duplicate warning
AND no database row is inserted before master approval

GIVEN a master is created by the authorized flow
WHEN the creation receipt succeeds
THEN the parent task binds the new warehouse ID and automatically resumes
AND final purchase bill still needs its own approval
```

## 11. Release sequence

1. Fix corrections API mismatch + correct warehouse selection + remove hardcoded warehouse labels.
2. Add scoped entity-directory endpoint and backend candidate ranking.
3. Implement shared picker and a responsive side panel/bottom sheet.
4. Integrate authorized dependent-master form and automatic parent-task resumption.
5. Extend same components to products, suppliers, customers and units; validate GST/HSN/UOM matching.
6. Full PHP/browser/security tests, screenshots, keyboard testing and staged rollout behind a new UI feature flag.

See `11_ENTITY_UI_DETAILED_REFERENCE.md` for the original full UI specification, including more detailed test and file-by-file notes.
