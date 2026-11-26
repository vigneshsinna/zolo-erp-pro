# PR7 — Copilot timeline, metrics, rollout and release evaluation

Stacked after PR6, which depends on PR5, PR4, PR3, PR2 and PR1.

## Implemented
- Default-off authenticated `/api/v1/ai/business-workflows` endpoints: list, preview, start, inspect, correct planning notes, pause/resume/cancel, link approved proposal, verify actual job-work receipt and reconcile verified steps.
- Authentication scoped through server principal/CompanyContext, with optional X-AI-Scope consistency header.
- Accessible, text-safe, versioned-asset Copilot timeline: reviewed-process preview, plan states, step dependencies, human proposal-link controls, verified physical receipt input and editable planning notes. Actual ERP approval remains in existing approval controls.
- Read-only BusinessWorkflowMetrics counts plan/step statuses and actual execution receipts by actor/company/branch/FY. Model credits remain explicitly unknown; the existing credit-metering service is the authoritative source.
- Synthetic 120-utterance corpus and gated 5/10/20-user read-only k6 harness.
- Existing `AI_BUSINESS_KNOWLEDGE_ENABLED`, `AI_BUSINESS_SEMANTIC_ENABLED`, `AI_BUSINESS_WORKFLOW_PLANNER_ENABLED`, `AI_BUSINESS_SERVICE_BILL_ENABLED`, `AI_BUSINESS_JOB_WORK_ENABLED` all default OFF.

## Tests executed / release blockers
- GitHub Actions CI covers MySQL AI and ERP suites when runs complete (capture actual outcomes in PR description).
- 120 synthetic phrases assess deterministic classifier only; they do NOT prove 95% natural language accuracy or multilingual Nemotron accuracy.
- **NOT EXECUTED**: 100 actual Indian bill images, 100 Copilot prompts, 50 difficult scenarios, 5/10/20 live staging concurrency, human staging UX, tenant privacy red-team and live NVIDIA RPM/cost benchmark. These require a dedicated authorized test tenant and data set.
- Knowledge definitions remain `draft`, so no full business workflow is startable until domain owners sign off.
- Customer-owned job-work order/receipt/return conversational capabilities remain unsupported; standalone principal service invoice has a guarded action adapter.
- Workflow UI is functional for reviewed processes, but must be visually tested on desktop/mobile before feature enablement.

## Recommended release decision
- Keep all new flags OFF in production until finance, tax, operations and security signoff, migrations, live ERP finance fixtures, permission review and load tests are complete.
- Do not merge or expose customer-facing autonomy based solely on passing unit suites.

## Rollback
- Set the five flags OFF; no business domain history is deleted. Retain audit tables and posted domain records. Rollback DB migrations only when no plans or new principal-job rows rely on them.
