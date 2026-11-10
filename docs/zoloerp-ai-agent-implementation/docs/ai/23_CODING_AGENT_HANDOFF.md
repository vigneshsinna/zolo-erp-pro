# 23 — AI coding agent implementation handoff (Phase 0 + first vertical slice)

> Copy this complete brief into Codex or another coding agent with access to the zoloERP repo. It describes **work to do**, not work already performed. Source of truth is current repository code, not this static plan if the repository has changed.

## Mission

Implement a **production-safe, read-only zoloERP AI Copilot foundation** on the **current** `main` branch, built primarily on the open-source [Laravel AI SDK](https://github.com/laravel/ai). Integrate existing ERP services through a single authorised tool executor. Deliver a working **customer outstanding** question/answer flow, secure API and basic Blade workspace. Write tests, run them and document evidence. Keep all AI flags disabled by default.

## Mandatory preflight

1. Read `docs/ai/README.md`, documents 01–09, 18–20 and 22; verify `git status` and current main SHA. Create `feature/ai-agent-foundation`; do not use stale upgrade branches.
2. Inspect `composer.json`, `composer.lock`, `routes/api.php`, bootstrap routes, existing auth/CSRF, `CompanyContextResolver`, `CompanyWriteGuard`, finance service `ageing`, policies, queue config, company/branch/FY schema, and current CI/deploy workflows.
3. Confirm PHP >=8.5.12 <8.6 and Laravel 13; run Composer validation/audit/platform checks. **Do not modify production or another running VPS app.**
4. Record a concise implementation checklist and discovered schema/permission differences. Use real source instead of guessing APIs or route names.

## Stage A — SDK and safe data model

- Add `laravel/ai` through Composer, publish config/migrations and use SDK agent/tool generators, after package audit. Keep package versions locked.
- Implement `AiPrincipal`, `AiPrincipalFactory`, `AiPrincipalAuthorizer`, `AiToolManifest`, `ErpToolExecutor`, `ApprovedModelGateway`, safe `AiAuditRecorder` and quota checker.
- Add AI-owned migrations for `ai_thread_scopes`, `ai_runs`, `ai_tool_invocations`, `ai_usage_counters` and `ai_feature_settings` with correct actual column types and indexes. Do not clone SDK conversation tables.
- Make `AI_ENABLED=false` and `AI_READ_ONLY=true` global defaults. API rejects disabled requests.
- No freeform SQL, shell, file system or web tools. Model keys only server-side.

## Stage B — authoritative receivables tool

- Adapt existing `FinancialReportService::ageing()` to safely support explicit authenticated actor without using spoofed request auth in jobs; preserve report accuracy, branch/date rules and control reconciliation. Prefer a dedicated actor-explicit read adapter.
- Implement read tool `receivables.outstanding.v1` with validated `as_of` and `limit`; compute totals on all authorised rows, not top-N alone. Expose source/report links and reconciliation status.
- Implement `CustomerOutstanding` via Laravel AI SDK's `Tool` interface; pass `AiPrincipal` via constructor, not model input.
- Implement read-only `ErpCopilot` with only authorised tools, short versioned instructions, redacted tool results and provider timeout/cost limits. Return verified fact cards and safe error states.

## Stage C — API and Blade UI

- Authenticated endpoints and a minimal AI workspace; preserve existing Blade/Bootstrap 4 layout and route conventions. Use CSRF/session or Sanctum properly, not unauthenticated public endpoints.
- Persist/check conversation owner and selected company/branch/FY every time; invalid foreign thread IDs must be denied.
- Display deterministic source-linked financial facts; do not trust LLM-written amounts as financial source of truth.
- Add a feature-flagged navigation item and rate limit. Keep any MCP endpoint **disabled/uninstalled** for the first user-visible MVP unless separately instructed.

## Stage D — Tests, verification and delivery

- Unit tests for input constraints, budgets, prompt redaction and tool registry permissions.
- Integration tests on disposable MySQL for AR ageing accuracy, company A/B isolation, branch, FY, permission revocation, reconciliation failures and no ERP writes.
- SDK fake tests for tool selection and provider outage. Browser test for workspace, source link and XSS escaping.
- Run all existing ERP acceptance suites, PHP 8.5 platform checks and `composer audit`. Do not suppress test failures/risky notices to claim a pass.
- Document exact commands and outputs in PR, list changed files, database migrations, dependency versions, open issues and safe rollback.

## Do not do

- Do not run migrations on production/development databases other than a dedicated disposable AI test database.
- Do not rewrite Laravel core, bootstrap, ERP ledger/stock posting or existing release workflow to add AI.
- Do not introduce arbitrary SQL, autonomous financial writes, external MCP tools, Qdrant or self-hosted LLM services before PH1 passes.
- Do not expose provider secrets or raw PII in logs, UI or GitHub.
- Do not use `--ignore-platform-reqs`, force push or merge/deploy without acceptance evidence.

## Stop conditions

If current repository conflicts with this plan, if domain permissions cannot be determined, or if package integration fails, **stop that unsafe step** and report the exact blocker, file and safe alternatives. Do not silently bypass auth, data integrity or package/platform constraints to make tests pass.

## Deliverables

A reviewed feature branch with Composer lock, safe additive migrations, principal/tool gateway, one verified receivables SDK tool/agent, read-only endpoints, Blade UI, security tests, operator setup notes, a real test report and a PR against `main`. Anything not executed must be labelled pending, not passed.
