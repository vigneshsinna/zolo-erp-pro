# Drift implementation work log

Scope: implement the gaps listed in `2026-11-12_DRIFT_REPORT.md`, preserving the existing working tree and human approval for every business mutation.

## Work packages

- [ ] Purchasing: verify and harden the existing goods-receipt and purchase-bill additions against real domain contracts, including partial receipts, duplicate invoices, tax, and stock effects.
- [ ] Sales: quotation, dispatch, posted invoice, payment receipt, and reviewed reminder preparation through the authoritative domain services.
- [ ] Inventory, finance, manufacturing, and setup: typed, permission-scoped action definitions, fresh previews, sealed approval, idempotent domain execution, and negative tests.
- [ ] Infrastructure: scoped read-only MCP, approved action queue jobs, isolated worker configuration, and redacted optional external telemetry.
- [ ] Documents: private quarantine, scanner boundary, extraction, scoped retrieval, optional Qdrant, and reviewed bulk import validation.
- [ ] Workspace: streaming transport, supported-browser voice capture, and explicit line deletion with server revision checks.
- [ ] Scheduling: opt-in, actor-owned read alerts and draft preparation; no autonomous posting or external sending.
- [ ] Integrate registrations, routes, permissions, and model tools; run the AI regression suite and relevant browser smoke checks.
- [ ] Update the drift report with evidence and any infrastructure that requires operator configuration.

## Acceptance and verification

Each action must reject foreign tenant/branch records, require current permissions, preserve approval/version bindings, and return the actual domain receipt. Posting calculations must use the ERP domain services. Tests run only with `ERP_TEST_MYSQL=1` against separate `zolo_test_*` schemas. External integrations remain disabled until configured and must fail closed or degrade without disrupting local audit records.

The implementation uses the current `feature/ai-unified-input-clarification` checkout. Existing changes, including GRN and purchase-bill files, are preserved. Work is divided by independent file ownership; shared registrations and the workspace are integrated by the main agent.
