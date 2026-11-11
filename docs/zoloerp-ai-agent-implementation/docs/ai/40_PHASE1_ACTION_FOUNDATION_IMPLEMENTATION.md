# Phase 1 — AI Action Foundation implementation

Branch: feature/ai-action-foundation, based on refactor/erp-copilot-stabilization at commit 14195d8.

## Delivered scope

- Additive AI proposal, approval and execution schema migration; historical proposal rows and original columns are preserved.
- ActionDefinition, ActionCapabilityRegistry and typed input, preview and command adapter contracts.
- Canonical JSON payload integrity using SHA-256 and server-only approval HMAC bound to exact version, source state, scope and approver.
- ActionProposalService with versioned edits and optimistic compare-and-swap.
- ActionApprovalService requiring independently authenticated and permissioned human approval.
- ApprovedActionExecutor with a short proposal-lock/claim transaction and actual ERP domain transaction ownership.
- Read-only Action Review Center route at /ai/action-review.
- Unit tests for payload hashing, HMAC integrity, empty capability registry and kill switches.

## Safety defaults

- AI_ACTION_FOUNDATION_ENABLED=false and AI_ACTION_EXECUTION_ENABLED=false by default.
- ZERO action capabilities registered in this branch; no automatic purchase/sales/stock/accounting execution.
- Existing AiToolManifest and GenericSdkTool remain strictly read-only.
- Existing Copilot approval API paths remain quarantined.
- Mode A company bootstrap routes, record-creation adapters and full conversational action UI are DEFERRED to a later gated phase.
- Any later adapter MUST check live ERP state and expected source hash under its domain-owned transaction, enforce idempotency and return an authoritative receipt.
- An uncertain execution must never be blindly retried. Reconcile against the authoritative ERP transaction first.

## Verify before merge

1. PHP 8.5 and Laravel 13 dependency/platform validation, composer audit.
2. PHP syntax lint all added classes, route:list, Blade render on actual checkout.
3. Run migrations on disposable MySQL 8.0/8.4, including upgrade with legacy records preserved.
4. Run full AI, commercial and operations CI suites; confirm no read-only regression.
5. Test approval tampering, forbidden user/branch/FY, expired proposal, changed version, double click and concurrent claims.
6. Assert the AI feature disabled flags cause zero ERP writes; check UI ownership isolation.
7. Benchmark preview, human review and execution lock duration; no DB transaction across model inference or human review.
8. Run transaction-level tests with UsesAiMysql outer fixture transaction taken into account.

## Evidence and limitations

This is foundational code only. It does not mean that purchase/sales command adapters, Mode A company setup, voice/image intake, or live transaction execution were implemented or tested. Review and enable any future action only in a separate security-reviewed branch.
