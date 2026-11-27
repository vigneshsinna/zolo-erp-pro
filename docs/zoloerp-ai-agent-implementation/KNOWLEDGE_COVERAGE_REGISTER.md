# AI Business Knowledge Coverage & Review Register

> **Scope:** Release Package R06 — Knowledge Governance & Runtime Execution Boundaries  
> **Source Packs:** `resources/ai/knowledge/`  
> **Governance Manifest:** `resources/ai/knowledge/governance/review-manifest.json`  
> **Status Policy:** Draft definitions are strictly blocked at runtime for `planner::start()` and execution gating until explicitly promoted to `reviewed` by an authorized business owner. Zero owner signoffs have been forged or mutated by code edits.

---

## 1. Inventory Summary (95 Definitions Across 10 Active Packs + 1 Empty Actions Pack)

| Knowledge File | Pack Scope | Total Definitions | `status = draft` | `status = reviewed` | Notes |
|:---|:---|---:|---:|---:|:---|
| `resources/ai/knowledge/definitions.json` | Core ERP vocabulary & metrics | 43 | 43 | 0 | Pending owner signoff |
| `resources/ai/knowledge/core/actions.json` | Core ERP commercial & inventory actions | 10 | 10 | 0 | Pending owner signoff |
| `resources/ai/knowledge/core/entities.json` | Core master & transaction entities | 11 | 11 | 0 | Pending owner signoff |
| `resources/ai/knowledge/core/policies.json` | Core financial, approval & tenancy policies | 3 | 3 | 0 | Pending owner signoff |
| `resources/ai/knowledge/core/processes.json` | Core multi-step ERP processes | 4 | 4 | 0 | Pending owner signoff |
| `resources/ai/knowledge/core/relationships.json` | Core foreign-key & document lineage | 8 | 8 | 0 | Pending owner signoff |
| `resources/ai/knowledge/industries/opticals/entities.json` | Optical Rx, lenses, frames & lab jobs | 5 | 5 | 0 | Optical profile (`retail`, `wholesale`, `rx_lab`) |
| `resources/ai/knowledge/industries/opticals/policies.json` | Optical power safety, tolerances & remake rules | 5 | 5 | 0 | Optical profile (`retail`, `wholesale`, `rx_lab`) |
| `resources/ai/knowledge/industries/opticals/processes.json` | Optical retail, wholesale & Rx lab workflows | 4 | 4 | 0 | Optical profile (`retail`, `wholesale`, `rx_lab`) |
| `resources/ai/knowledge/industries/opticals/relationships.json` | Optical Rx-to-job & SO-to-DC relationships | 2 | 2 | 0 | Optical profile (`retail`, `wholesale`, `rx_lab`) |
| `resources/ai/knowledge/industries/opticals/actions.json` | Optical action overlays | 0 | 0 | 0 | Intentionally empty (capabilities registered in `config/ai_capabilities.php`) |
| **Total** | | **95** | **95** | **0** | **Signoff status: `pending_owner_signoff`** |

---

## 2. Runtime Governance & Drift Guards Implemented

1. **Draft Isolation & Signoff Gate (`BusinessWorkflowPlanner::preview` / `start`)**
   - Unreviewed (`draft`, `deprecated`, `withdrawn`) or expired process definitions return `can_start = false` in `preview()` and throw `ValidationException` in `start()`.
   - Owner signoff ledger (`resources/ai/knowledge/governance/review-manifest.json`) currently records `pending_owner_signoff` and was left untouched.

2. **Industry Profile & Subtype Scoping (`BusinessKnowledgeRegistry::forAction`, `BusinessWorkflowPlanner`)**
   - `BusinessKnowledgeRegistry::forAction()` filters action knowledge by `$reviewedOnly`, `$profile`, `$subtype`, `$asOfDate`, and `isWithdrawnOrDeprecated()`.
   - `BusinessWorkflowPlanner::preview()` evaluates `requiresCapabilities()` against `ActionSecurity::definition()` and filters step `reviewed_action` definitions by the active company's resolved industry profile and subtype (`IndustryContextResolver`).
   - `assertWorkflowDefinitionCurrent()` fails closed (`AuthorizationException`) if a general-trading company attempts to advance an optical process or if an optical store without lab capability attempts `opticals.*rx_lab*` workflows.

3. **Live `knowledge_hash` Drift Enforcement (`BusinessWorkflowPlanner`, `BusinessWorkflowProgress`)**
   - `BusinessWorkflowPlanner::inspect()` exposes `current_knowledge_hash` and `knowledge_drifted` boolean.
   - All progression methods (`BusinessWorkflowProgress::reconcile()`, `change()`, `linkProposal()`, `completeClarification()`, `completeValidation()`, `verifyExternalEvent()`, `verifyPhysicalReturn()`, `skipNotApplicableStep()`) call `assertWorkflowDefinitionCurrent()`, throwing `ValidationException` whenever a planner-started workflow's stored `knowledge_hash` diverges from the live `BusinessKnowledgeRegistry::versionHash()` or its definition leaves `reviewed` state.

4. **Strict Optional vs. Required Step Skipping (`BusinessWorkflowProgress::skipNotApplicableStep`)**
   - Only steps explicitly marked `optional: true` in the reviewed process definition can transition to `skipped_not_applicable`, subject to upstream dependency satisfaction (`assertDependencies()`), non-empty route justification (`reason`), and SHA-256 skip evidence hashing.
   - Required steps reject skip attempts with `ValidationException`.
   - Dependency unblocking and `workflow_completed` reconciliation accept `completed` or `skipped_not_applicable` only.

---

## 3. Production Cutover Blocker (External Owner Action Required)

- **Blocker:** Production workflow planning requires an authorized owner signoff reviewing the 95 JSON definitions in `resources/ai/knowledge/**`, updating `status` from `"draft"` to `"reviewed"`, and recording approver metadata in `resources/ai/knowledge/governance/review-manifest.json`.
- **Verification Coverage:** Automated tests verify draft blocking by default and exercise planner progression/drift-gating using temporary reviewed fixture packs inside isolated test directories.
