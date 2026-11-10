# Goldfish Annotated Review Report

Target [ERP Copilot stabilization plan](zoloerp_erp_copilot_bugfix_and_shared_tool_refactor.md), original lines 1–513. Source commit `a1477a679dc0f9ea98ce8cc2d531a1c997e20864`.

Three fresh-context reviewers completed one round. The Zoom-Out reviewer assessed the architecture and future action boundary. Codebase Details checked the claims against current ERP code. Implementation Readiness checked migration, SDK compatibility, and release gates. The Elephant accepted the consolidated fixes below.

## Decision

Proceed with stabilization, then migrate the read tools after the Part A checks pass. Extend the existing manifest and executor. Keep model-generated proposals and human-approved ERP commands in separate future entry points. Future agents can reuse Copilot's authorized read tools for grounding, plus schema and audit conventions for their own proposal interface.

## Accepted findings

| Severity | Original plan | Evidence | Resolution |
|---|---|---|---|
| Blocker | §2 and §20 | Both route families exposed proposal listing and rejection. Listing selected company-wide previews. Rejection wrote a receipt. The SDK also advertised the proposal tool. | Remove the write implementation and model tool. Return a controlled denial from all six routes. Suppress proposal cards and historical results. Preserve persisted rows. |
| Blocker | §3 and §10 | Order and document permissions were checked at tool level. A sales grant could reach purchases. Receivables accepted supplier open items. Inventory could return cost under a catalog permission. | Require per-operation commercial permissions and active context. Restrict invoice allocations to customer invoices and branch-owned counterparties. Gate valuation separately. |
| Blocker | §3, §8 and §9 | Saved assistant text, facts, and cached entities could be replayed after same-context permission revocation. The response could also pair a model claim with contradictory ERP facts. | Recheck access before showing or replaying facts. Bind cached entities to branch and FY. Render deterministic messages and typed facts. Reject unsafe links. |
| Major | §4–§6 and §10 | Seven reporting tools adjusted dates. Sales sums included nonfinal rows, skipped credits and materialized all sale IDs. Inventory issued calls per product and warehouse. Order totals counted the limited result. | Use explicit FY validation and company-timezone defaults. Aggregate completed sales with signed credit and debit notes. Batch stock queries and compare exact quantities by stock identity. Read open orders from the ERP lifecycle. |
| Major | §7–§9 | SDK wrappers cast or discarded arguments. Tool-call limits were unused. Rate limit admission used separate check and increment calls. Raw provider exceptions reached logs. | Validate untouched SDK input against a closed schema. Cap executed tools per run. Count actual provider dispatches and retries with a shared locked bucket. Store redacted diagnostics. |
| Blocker | §12–§15 | A parallel registry would duplicate the existing allowlist. The SDK uses provider function names independent of ERP keys. Several wrappers had distinct output fields. DeepSeek directly used the receivables wrapper. | Extend `AiToolManifest` and retain `ErpToolHandlerInterface`. Give each tool an explicit provider name and use one adapter. Preserve valid aliases and legacy reconciliation fields. Migrate DeepSeek's existing receivables consumer. |
| Major | §15 and §18 | The read-only and kill switch cases were conflated. Standalone PHPUnit commands could skip MySQL feature tests. | Verify authorized reads in read-only mode, all AI operations denied when disabled, and proposal quarantine independent of flags. Use `phpunit.ai.xml` with explicit disposable MySQL settings. |

The installed Laravel AI SDK resolves tools by their declared provider names. The five names containing dots change to underscore aliases because the OpenAI function-name contract permits letters, digits, hyphens, and underscores. ERP keys remain stable. [OpenAI SDK function schema](https://github.com/openai/openai-python/blob/main/src/openai/types/shared_params/function_definition.py)
