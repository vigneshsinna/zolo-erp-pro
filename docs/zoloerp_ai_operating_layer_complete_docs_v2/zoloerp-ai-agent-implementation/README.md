# zoloERP — AI Operating Layer: Master Documentation Index

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Vision and how to use this pack
This is the **full replacement** for the existing `docs/zoloerp-ai-agent-implementation/docs/ai` folder. The user goal is **one natural-language ERP operating experience** where users can ask business questions, enter data, prepare transactions, review drafts, approve controlled actions and run bounded, auditable workflows. A chat window alone is not the product: it is complemented by editable forms, document upload, source-linked answers, progress tracking and ERP navigation.

**Business rule:** Model output is untrusted. The AI plans and proposes; established Laravel domain services calculate, validate and post. Company, branch, FY and permissions come from the authenticated server-side context. Consequential effects use explicit approval and idempotency. No generic SQL, arbitrary PHP methods, shell execution or unrestricted browser tools.

## Source-of-truth hierarchy
1. **Current code at a pinned Git SHA** for implemented behavior and actual service signatures.
2. **ERP business rules, posting controls and reviewed finance/product decisions** for what actions mean.
3. **This design pack** for proposed future architecture and acceptance criteria.
4. The installed Laravel SDK's **version-specific** contract; sketches in docs are not drop-in code.

**State on baseline:** The refactor branch includes a read-only `ErpCopilot`, 14 read handlers, a single `GenericSdkTool`, `AiToolManifest`, `ErpToolExecutor`, scoped chat history, AI telemetry, and business read facades. Proposed action execution is quarantined; no purchase/sales/journal AI action engine is shipped. The latest reviewed GitHub acceptance run `42846498957` has a green AI suite (59 tests / 408 assertions) but commercial and operations jobs fail; see `01_REPOSITORY_BASELINE.md` and `36_REPLACEMENT_MIGRATION_AND_RELEASE_GATES.md`.

## Read order and delivery map
| First read | Then read | Objective |
|---|---|---|
| `01`–`04`, `07`–`09`, `18`–`19` | `35`–`36` | Complete and ship read-only Copilot stabilization safely |
| `25`–`29`, `12`, `06` | `30`–`33` | Build common action capabilities and multi-step workflow platform |
| `26`, `34`, `13` | `18`, `35` | Deliver useful customer-facing data entry UX and document ingestion |
| `11`, `14`–`17`, `21` | `20`, `22` | Optional platform integrations only when needed |

## Original filenames retained (updated)
- `01_REPOSITORY_BASELINE.md` — actual source, completed vs pending, CI blockers.
- `02_OPEN_SOURCE_STACK.md` — package ownership and install gates.
- `03_ARCHITECTURE.md` — current and target operating-layer architecture.
- `04_SECURITY_AND_TENANCY.md` — independent authorization, data sensitivity, autonomy limits.
- `05_INSTALLATION.md` — compatibility/deployment runbook, no blind reinstalls.
- `06_DATABASE_SCHEMA.md` — canonical AI metadata and future workflow schema.
- `07_LARAVEL_AI_SDK.md` — SDK integration and tool adaptation.
- `08_ERP_TOOL_REGISTRY.md` — read tools plus separate action-capability boundary.
- `09_FIRST_VERTICAL_SLICE.md` — current receivables Copilot baseline/contract test.
- `10_AGENT_CATALOG.md` — optional specialist agent experiences, shared capabilities.
- `11_MCP_INTEGRATION.md` — optional external interface, same permission engine.
- `12_APPROVAL_AND_ACTIONS.md` — controlled proposal/approval/execution design.
- `13_API_AND_FRONTEND.md` — API, chat, task workspace, preview and safety.
- `14_HORIZON_AND_QUEUES.md` — bounded jobs, scheduling and recovery.
- `15_RAG_AND_QDRANT.md` — approved knowledge only, not live accounting truth.
- `16_LANGFUSE_OBSERVABILITY.md` — costs, audit, evaluation with redaction.
- `17_OPTIONAL_NEURON_OLLAMA.md` — later workflow/model evaluation.
- `18_TESTING_AND_EVALUATIONS.md` — verification and adversarial testing matrix.
- `19_CI_CD_AND_PRODUCTION.md` — merge, release, VPS and rollback gates.
- `20_DELIVERY_BACKLOG.md` — phase-based stories and dependencies.
- `21_ROLLOUT_AND_OPERATIONS.md` — rollout, operational limits and incident runbook.
- `22_DECISIONS_AND_ASSUMPTIONS.md` — ADR log, open business questions.
- `23_CODING_AGENT_HANDOFF.md` — current agent-ready implementation brief.
- `24_SOURCE_REFERENCES.md` — exact repo touchpoints and upstream sources.

## New documents for the expanded goal
- `25_PRODUCT_VISION_AND_AUTONOMY.md` — product outcomes and safe autonomy levels.
- `26_COMMAND_CENTER_UX.md` — conversational forms, previews, uploads and task continuity.
- `27_INTENT_AND_RECORD_RESOLUTION.md` — intent, master matching, missing-field clarification.
- `28_ACTION_CAPABILITY_CATALOG.md` — versioned read/draft/write capability definitions.
- `29_WORKFLOW_ORCHESTRATION.md` — durable state machine, retries, resume and saga rules.
- `30_PURCHASE_WORKFLOWS.md` — purchase order → receipt → bill lifecycle.
- `31_SALES_AND_COLLECTION_WORKFLOWS.md` — quotation → order → invoice → collections.
- `32_INVENTORY_FINANCE_MANUFACTURING.md` — stock, journals and production safeguards.
- `33_MASTERS_OTHER_MODULES.md` — master data and other ERP module onboarding.
- `34_DOCUMENT_AND_BULK_ENTRY.md` — invoice capture, imports and human review.
- `35_SCENARIO_EVALUATION_CATALOG.md` — verifiable end-to-end product scenarios.
- `36_REPLACEMENT_MIGRATION_AND_RELEASE_GATES.md` — how to replace docs and evolve code without breaking the current Copilot.

## Mandatory development sequence
**Do not start live action execution before the existing read-only Copilot release gate is green.** Phase 0 closes remaining bugs. Phase 1 implements action foundation behind disabled flags. Phase 2 delivers read/draft/approval for one complete transaction vertical slice. Subsequent phases cover sales, inventory/finance, documents/bulk, and only then higher autonomy. See `20_DELIVERY_BACKLOG.md` for dependency and exit gates.

## Not claimed
This pack does **not** include implemented Laravel classes, a merged PR, production keys, live provider validation, production-posted purchase orders, or permission to bypass approvals. It is a detailed implementation specification intended to replace stale original docs while preserving their valuable architectural/security decisions.
