# PR4 — Declarative durable workflow planner (dry-run)

Based on PR3. Adds a versioned persistent plan, step, and sanitized-event model. All user operations are guarded by the authenticated actor/company/branch/FY scope and revision CAS. Draft knowledge can only be previewed. Starting requires owner-reviewed fully supported processes, so none of the baseline draft flows can execute.

## Implemented
- `BusinessWorkflowPlanner`: preview, start, inspect, correct, pause, resume, cancel.
- 3 normalized tables: workflows, workflow steps, events. No ERP domain writes.
- Explicit unsupported-action and future-physical-event statuses; no tool/model execution, no implicit approval.
- No UI endpoint in PR4; future PR7 will display the timeline.
- `AI_BUSINESS_WORKFLOW_PLANNER_ENABLED` defaults false.

## Tests
- Added disabled-by-default test and existing AI suites run in CI.
- Release blockers: business owner signoff for draft processes, reference invariants, authenticated thread/task contract integration, full concurrency/security fixtures.

## Rollback
- Disable flag; leave durable plans/events for audit. Never delete posted ERP records as part of rollback.
