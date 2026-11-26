# Drift implementation work log

Scope: implement the gaps listed in `2026-11-12_DRIFT_REPORT.md`, preserving the existing working tree and human approval for every business mutation.

## Work packages

- [x] Purchasing: verify and harden the existing goods-receipt and purchase-bill additions against real domain contracts, including partial receipts, duplicate invoices, tax, and stock effects.
- [x] Sales: quotation, dispatch, posted invoice, payment receipt, and reviewed reminder preparation through the authoritative domain services. Explicit outbound email uses a separate approval-bound capability and encrypted outbox.
- [x] Inventory, finance, manufacturing, and setup: typed, permission-scoped action definitions, fresh previews, sealed approval, idempotent domain execution, and negative tests.
- [x] Infrastructure: scoped read-only MCP server and configured outbound client, approved action queue jobs, isolated worker configuration, and redacted optional external telemetry.
- [x] Documents: private quarantine, scanner boundary, extraction including scanned-PDF OCR, scoped retrieval, optional Qdrant, and reviewed product/opening import validation.
- [x] Workspace: streaming transport, supported-browser voice capture, and explicit line deletion with server revision checks.
- [x] Scheduling: opt-in, actor-owned read alerts and draft preparation; no autonomous posting or external sending.
- [x] Integrate registrations, routes, permissions, and model tools; run the AI regression suite and relevant browser smoke checks.
- [x] Update the drift report with source evidence and infrastructure that requires operator configuration.

## Acceptance and verification

Each action must reject foreign tenant/branch records, require current permissions, preserve approval/version bindings, and return the actual domain receipt. Posting calculations must use the ERP domain services. Tests run only with `ERP_TEST_MYSQL=1` against separate `zolo_test_*` schemas. External integrations remain disabled until configured and must fail closed or degrade without disrupting local audit records.

The implementation uses the current `feature/ai-unified-input-clarification` checkout. Existing changes, including GRN and purchase-bill files, are preserved. Work is divided by independent file ownership; shared registrations and the workspace are integrated by the main agent.

## Completion audit — 2026-11-17

The [report resolution audit](../2026-11-12_DRIFT_REPORT.md) maps every reported gap to its implementation. The original November 12 findings are retained as historical evidence. Important integration fixes in the completion pass include real-container registration of extended and import capabilities, mounted schedule routes, configured external read discovery, and opening-import model-tool exposure. Tests that registered adapters directly had previously hidden the missing production wiring.

Streaming tests cover real SDK parsing of mocked provider SSE, deferred HTTP admission and reported usage, provider failure after dispatch, early validation denial and company kill switches. The workspace renders safe draft deltas and replaces them with the verified persisted response. Generic action review displays full preview fields, so journals and opening imports show the exact approved details.

Worker tests start without a logged-in user and verify fresh actor installation plus guard/request/context restoration. Reviewed reminder tests use a mocked mail transport; no message was sent. The document browser test found and fixed a Bootstrap wrapper-class collision that lost an opening mapping's record type. Browser checks also verify hidden controls, invalid-row exclusion, exact selected product rows, explicit opening mappings/totals, citations and revocation.

### Browser checks

Run the fixture-export tests with `ERP_EXPORT_AI_WORKSPACE_BROWSER=1` before the following scripts. The scripts use the repository's plain Node/Playwright harness and an installed browser; set `PLAYWRIGHT_CORE` to the local module path when it is not installed in the checkout, and `BROWSER_CHANNEL=msedge` for Edge.

```text
node tests/Browser/Ai/workspace-merge.spec.mjs
node tests/Browser/Ai/drift-workspace.spec.mjs
node tests/Browser/Ai/documents-drift.spec.mjs
```

All three checks passed against rendered fixtures with intercepted network requests. Coverage includes chat/attachments/approval receipt handoff, split UTF-8 and CRLF SSE, interrupted/error streams with no retry, XSS-safe rendering, CSRF and scope headers, dictation without automatic submission, microphone denial, remove/undo/revision conflicts, upload/scan/import review, and 320/768/1440-pixel layouts. Screenshots were inspected locally under ignored `output/playwright/`.

### Final automated results

- Complete AI suite: **352 tests, 3,684 assertions, zero failures, errors or skipped tests**, in 5m 05.806s. This includes the existing 302-test suite and the new drift regressions.
- Database: `zolo_test_ai_drift_finish` on the local test server, guarded by `ERP_TEST_MYSQL=1`. The ignored custom PHPUnit configuration overrides both forced database names in the repository configuration; the active development database was not migrated or written by tests.
- Command: `php vendor/bin/phpunit -c scratch/ai-drift-finish.phpunit.xml --log-junit scratch/ai-drift-final-junit.xml`. Dataset events were redirected with `AI_SCENARIO_EVENTS_PATH` to ignored `scratch/ai-drift-final-events.jsonl`; tracked historical evaluation artifacts were preserved.
- Local evidence: `scratch/ai-drift-final-tests.log` and `scratch/ai-drift-final-junit.xml`; browser logs `scratch/ai-drift-browser-merge.log`, `scratch/ai-drift-browser-tests.log`, and `scratch/ai-drift-documents-browser.log`.
- Syntax validation: **64 changed/new PHP files** excluding Blade, and **7 JavaScript files**, passed their relevant syntax checks. Render tests exercised the workspace, document workspace and non-product action review. `git diff --check` and documentation link validation passed.

The initial full run exposed an outdated multimodal expectation that duplicate supplier invoices could remain proposals. The corrected regression requires HTTP 422 and unchanged proposal, purchase, stock-movement and journal counts. Its entire workflow class passed 11 tests / 98 assertions before the final full run. The optional Horizon dashboard now requires an explicit installation-operator allowlist as well as current company administration, since it can expose jobs from multiple companies.

### Operator configuration

- [Infrastructure operations](ai-infrastructure-operations.md): reviewed MCP credentials/allowlists, asynchronous workers, scheduling, optional Horizon and Langfuse.
- [Document operations](ai-documents-operations.md): private storage, scanner and OCR paths, optional embeddings/Qdrant, reviewed opening template and source mappings.
- [Payment reminders](ai-payment-reminders.md): default-off outbound switch, company sender channel, mail transport, encrypted outbox and uncertain-delivery reconciliation.

These are deployment prerequisites, not claims that external services were enabled or tested live. No production approval, commit, push or deployment is included. The separate release evaluation still requires genuine concurrency, commercial execution evidence and live-model gates.
