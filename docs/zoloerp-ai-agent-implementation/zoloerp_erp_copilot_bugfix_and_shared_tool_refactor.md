# zoloERP Pro — ERP Copilot Bug-Fix and Shared Tool Refactoring Plan

- **Source repository:** `vigneshsinna/zolo-erp-pro`
- **Source branch:** `feature/ai-agent-foundation`
- **Reviewed source commit:** `a1477a679dc0f9ea98ce8cc2d531a1c997e20864`
- **Prepared:** 10 November 2026
- **Status:** Revised after the [Goldfish review](goldfish_review_report.md). Record implementation and validation evidence separately from requirements.
- **Primary objective:** Fix remaining ERP Copilot bugs **first**, then reduce repetitive Tool → Handler → Service code by introducing a secure, reusable Tool Engine. Preserve the working Copilot, Laravel AI SDK integration and existing ERP business calculations.

> **Scope boundary:** This is **ERP Copilot work only**. Do **not** build or redesign the Action Engine, purchase bills/orders, sales posting, journal posting, payment dispatch, autonomous execution, multi-agent orchestration, MCP, RAG or Sales Intelligence in this branch. Existing unfinished proposal/write paths must be safely disabled or isolated. Their full implementation belongs in a separate future branch.

---

## 0. MANDATORY FIRST STEP — Create a NEW branch from the current AI branch

Do **not** start the refactor on `main` or directly on the working `feature/ai-agent-foundation` branch. Preserve the current branch as a reproducible baseline. The correct **base** is the current AI branch, **not** `main`.

Suggested new branch: `refactor/erp-copilot-stabilization`.

```bash
# In an authorised local checkout of zolo-erp-pro
# Confirm worktree is clean. Stop and preserve any unrelated changes if not.
git status --short
git fetch origin --prune

# Create the new branch FROM THE CURRENT AI BRANCH
# Use the remote branch to avoid taking unrelated local work.
git switch -c refactor/erp-copilot-stabilization origin/feature/ai-agent-foundation

git branch --show-current
git rev-parse HEAD
git status --short

# Expected initial SHA at the time this document was authored:
# a1477a679dc0f9ea98ce8cc2d531a1c997e20864
# If the remote branch advanced, record the newer source SHA and re-audit changed files.

git push -u origin refactor/erp-copilot-stabilization
```

**Branch rules:**

- Freeze/record the exact source SHA, Composer lock state, PHP runtime, MySQL version, working model/provider configuration and test results before editing.
- One reviewed commit/PR group for **bug fixes**, a separate one for **Tool Engine refactoring**. Do not mix the two.
- Never include `.env`, provider API keys, raw customer/employee data, production prompts, logs containing PII, or database dumps.
- Use isolated test databases and stub/fake model responses for routine CI. Do not run destructive tests against development or production data.
- Do not modify or deploy `main` as part of this plan; raise a PR only after the acceptance gates below pass.

## 1. Current implementation: what exists and what stays

At the reviewed source commit, the Copilot has approximately **15 registered capabilities**. It currently uses:

```text
app/Ai/Agents/ErpCopilot.php
app/Ai/Tools/{ToolName}.php                  -> Laravel AI SDK Tool wrapper
app/Ai/Handlers/{ToolName}Handler.php       -> metadata, permissions, handler routing
app/Ai/Services/Read{Domain}.php            -> ERP-specific read calculations
app/Ai/Services/AiToolManifest.php          -> tool registry
app/Ai/Services/ErpToolExecutor.php         -> auth, validation, feature gate, audit
app/Ai/Services/AiPrincipalFactory.php      -> permission-based tool selection
app/Ai/Gateway/ApprovedModelGateway.php    -> provider, quotas, RPM, usage
app/Http/Controllers/Api/V1/AiChatController.php
app/Providers/AiServiceProvider.php
config/erp_ai.php
resources/views/backend/ai/workspace.blade.php
```

**Retain:** `ApprovedModelGateway`, `AiPrincipal`, authorizer and company context checks, `AiFeatureGate`, `AiAuditRecorder`, authoritative finance/inventory/sales services, `ErpToolResult`, Laravel AI SDK, stable conversation storage and existing tool keys. Keep a single `ErpToolExecutor` entry point for **every** model-originated tool invocation.

**Refactor later:** repeated registrations in `AiServiceProvider`, `AiPrincipalFactory` and `ErpCopilot::tools()`, and the repetitive SDK Tool / Handler wrappers. Do **not** eliminate real business rules just to reduce file count.

**Capability inventory to preserve (confirm against runtime manifest):**

- `receivables.outstanding.v1`, `receivables.invoices.v1`, `receivables.invoice_allocations.v1`
- `inventory.stock_summary.v1`
- `sales.summary.v1`
- `payables.summary.v1`, `accounting.statements.v1`, `tax.gst_summary.v1`
- `manufacturing.boms.v1`, `jobwork.status.v1`, `orders.open.v1`
- `documents.preview.v1`, `knowledge.query.v1`, `copilot.help.v1`
- `proposals.draft.v1` — **temporarily quarantined**, not part of read-only Copilot

---

# PART A — BUG FIXES FIRST (NO TOOL ENGINE REFACTORING YET)

## 2. P0 critical: Quarantine unfinished action/write paths

**Verified source:** `app/Ai/Services/ActionProposalService.php`, `app/Ai/Handlers/DraftProposalHandler.php`, `app/Ai/Agents/ErpCopilot.php`, `app/Http/Controllers/Api/V1/AiChatController.php` and proposal routes.

The current `ActionProposalService` has several **source-confirmed** problems:

- It estimates tax at a fixed **5%** instead of using authoritative ERP tax/pricing services.
- Approval uses `Sale::forceCreate()` directly instead of approved commercial application commands, and the reviewed path does not demonstrate creation of complete validated sale line items.
- `draft_sale_order` and quotation proposals share an unsuitable creation path.
- Payment reminder approval can report **`dispatched`** without evidence of actual delivery.
- Unsupported action types can receive a generic success-style execution result.
- Approval does not demonstrate the complete business-operation-specific permission, branch/FY, source version, posting-rule and current-state checks required for financial actions.

**Required in this Copilot-only phase:**

1. Disable exposure of `proposals.draft.v1` to the model regardless of principal or company toggles. Remove it from the Copilot prompt, advertised tools, and manifest. Enforce the read-only boundary in the executor; a UI-only hide is insufficient.
2. Disable listing, approval, and rejection on both web and REST routes. Suppress legacy proposal cards and their text on replay. Return an explicit controlled disabled/not-available response (403 or 409 as appropriate); keep authentication, ownership and audit checks intact.
3. Set/verify global and company-specific AI read-only defaults; fail closed if the feature gate cannot load settings.
4. Preserve existing proposals and audit records for investigation; **do not drop historical tables or execute migrations to destroy proposals**. Identify any active pending records and report them for manual review.
5. Remove misleading success/dispatch claims while the workflow is disabled; do not attempt to implement tax, posting, approvals or payment sending here.
6. Add feature tests for direct HTTP endpoints, replayed approvals, disabled feature flags, forged tool calls and company/branch cross-access; all mutation attempts must fail with **zero new Sale, stock, ledger or outbound-message records**.

**Acceptance:** An authenticated user or model cannot generate a commercial transaction, approve/execute a pending action, post accounting entries or dispatch reminders through the AI subsystem in this phase. Read-only Copilot remains functional.

## 3. P0/P1 data confidentiality: Tenant, branch, FY and resource scope

**Inspect:** `AiPrincipalFactory`, `AiPrincipalAuthorizer`, `ErpToolExecutor`, all `Read*` facades, model `visibleIn()` scopes, `CompanyContextResolver`.

- Resolve user, company, branch and financial year on the Laravel server. Never accept those IDs as model-selected ownership parameters.
- Revalidate active user/membership, role, company/branch/FY scope **on every tool call**; keep fail-closed behavior if permission checks cannot complete.
- Re-check resource-level ownership for product, supplier, customer, warehouse, invoice, BOM and report objects; a valid numeric ID must not bypass company/branch permissions.
- Preserve the branch filter for all summary and count queries, not only the displayed detail rows.
- Do not give branch-only users company-wide summaries, including warehouse-null/shared warehouse cases, without an explicit business rule authorizing the aggregation.
- `ReadInventoryStockSummary` **already rejects invalid / cross-branch warehouse IDs** at this source commit; retain and regression-test this. Do not reintroduce earlier fallback-to-all-warehouses behavior.
- Test restricted users, admins with intended expanded scope, cancelled memberships, changed selected FY mid-conversation, and stale conversation IDs.

**Acceptance:** Zero cross-company/branch/FY leaks on read tools, summary totals, conversation history, document preview, company knowledge, sources and links. No silent scope widening.

## 4. P1 correctness: Financial-year dates and reporting periods

**Source-confirmed:** `ReadCustomerOutstanding` currently **clamps** out-of-FY `as_of` dates. `ReadSalesAnalytics` similarly adjusts out-of-range dates, and swaps `from`/`to` if reversed.

- Replace silent adjustment/swapping of **explicitly requested** business dates with a validation error that includes the authorized FY range. Do not return a different period's figures as if they answered the user's question.
- Omitted dates may use **documented, deterministic defaults** (company timezone, current authorized FY, intended reporting period), clearly returned as `effective_from`, `effective_to` or `as_of`.
- Require a valid FY; do not fall back to arbitrary current dates if the selected FY cannot be resolved.
- Reject `from > to`; use actual calendar validation, not only regex matching (`YYYY-MM-DD` shape alone allows impossible dates).
- Preserve historical FY rules and fiscal-year access controls for authorized users.
- Include effective period, timezone, source and explicit warnings in the result and UI.

**Acceptance:** Out-of-range questions cannot silently yield adjusted values; defaults are predictable and every displayed amount has an exact effective period.

## 5. P1 correctness: Sales analytics must reconcile to actual ERP reports

**Source-confirmed review targets:** `ReadSalesAnalytics` filters `sales.created_at`, loads all matching sales, sets `netSales = grossSales`, sums paid amounts, and clamps negative aggregate dues to zero. It builds a top-products query using the selected sale IDs. These may conflict with business report definitions and do not scale well to large histories.

**Required:**

- Identify authoritative **sales business date** and invoice posting/status fields used in zoloERP reports. Distinguish record creation date from sales transaction date; don't blindly use `created_at`.
- Define and verify **gross sales, net sales, returns, discounts, GST/tax, amount collected, outstanding and invoice count** against existing business reports. Do not invent a universal formula; confirm whether `grand_total` includes discounts/tax and how returns and credit notes are represented.
- Do not set `net_sales` equal to `gross_sales` unless the verified business definition requires it. Do not suppress negative credits/overpayments by clamping to zero without a signed reconciliation policy.
- Exclude draft/void/reversed records according to actual business semantics; confirm sale statuses and document variants.
- Scope top-product and total queries consistently to authorized branch, FY, date, product and warehouse. Confirm return quantities/revenue and invoice currency conversions where relevant.
- Replace unbounded `->get()` of all sales and very large `whereIn` lists with bounded SQL/database aggregations, backed by appropriate indexes, **without losing full-population totals**.
- Use decimal-safe representations for financial results; document exact scale/rounding.
- Compare outputs against a fixed fixture set and the existing dashboard/sales report for several date periods and return/credit scenarios.

**Acceptance:** Sales facts reconcile to the established ERP reporting system (or expose a clearly explained control variance); no invented net sales, hidden credits or cross-branch totals.

## 6. P1 correctness and performance: Inventory summaries

**Source-confirmed:** `ReadInventoryStockSummary` loads all active products, then calls `InventoryAvailabilityService::forProduct()` for every product and potentially for every branch warehouse. It casts quantities to floats and compares on-hand and ledger values as floats.

- Retain the corrected explicit invalid-warehouse rejection and branch-safe target warehouse list.
- Replace product × warehouse service calls with batched or aggregated queries where reliable; profile query count, memory and response time using large **synthetic** catalogs.
- Do not calculate an all-products grand total by truncating inputs to the top-N rows; aggregate over the complete authorized dataset, display only bounded details.
- Avoid float equality for inventory reconciliation. Use decimal/UOM-aware quantities and explicit tolerances/precision defined by the domain rules.
- Prevent summation of incompatible units or currencies. Reuse the ERP's unit conversion and inventory valuation rules, rather than simple `on_hand × product.cost` where the authoritative valuation differs.
- Distinguish no matching products, zero-stock products, missing warehouse permission, missing stock ledgers, and **unreconciled stock**. Missing data must not masquerade as zero or success.
- Test common/shared warehouses, inactive warehouses, overlapping branch access, product-batch/serial variants and current branch filters.

**Acceptance:** Stock counts/values match authorized ERP stock reports; large catalogs do not cause unbounded model response, database load or request timeouts.

## 7. P1 tool input validation: One strict, reliable schema

**Source-confirmed:** `ErpToolExecutor::validateInputSchema()` handles some simple types but does not comprehensively enforce nested objects/arrays, all schema constraints or strict date semantics. Its default for `additionalProperties` is permissive (`true`). Some SDK Tool wrappers also validate and cast inputs separately.

- Use a single authoritative schema/rules definition for each capability; ensure the model-visible schema and Laravel server validation are derived from the **same** source.
- Reject unknown parameters by default; validate nested arrays/objects, string length, limits, enumerations, quantities, IDs, date ranges and nullability. Avoid implicit casts that accept malformed model inputs.
- Validate before passing values to the business facade. Keep authorization and resource checks independent of the LLM schema, and retain safe failure without disclosure of underlying records.
- Do not allow model-provided `user_id`, `company_id`, `branch_id`, `financial_year_id`, arbitrary SQL, PHP class, method, URL or execution flags.
- Limit returned rows, source reference count, execution time and model tool calls; ensure `AI_MAX_TOOLS_PER_RUN` is actually enforced, not merely configured.

**Acceptance:** Wrong types, extra/nested fields, invalid dates, huge arrays and unsupported enum values are rejected consistently regardless of SDK or direct tool execution path.

## 8. P1 truthfulness and Copilot answer formatting

**Source-confirmed:** `AiChatController` extracts `facts`, `sources`, `warnings`; if facts exist it may return the raw model text as the answer. The current UI displays verified cards separately. A model narrative can still contradict a numeric fact.

- Treat typed ERP tool results as the only authoritative source for figures. Verify important figures shown in prose against tools, or render facts deterministically and constrain the model narrative to nonnumeric interpretation.
- Distinguish: `verified`, `partial`, `unreconciled`, `tool_error`, `unsupported`, `provider_error`. An unsupported business question should not be labelled as an ordinary successful verified answer.
- Preserve tool warnings (control variances, missing records and limited scope) even if the LLM omits them. Don't claim invoice/payment/reminder work completed when only a suggestion was generated.
- Preserve safe Markdown rendering with escaping and DOMPurify, including history reload; prevent stored/reflected XSS from model, source URLs and retrieved business text.
- Keep the Copilot's conversational format readable: short direct answer, verified facts/cards, business interpretation, source link, warnings. Test mobile layout, long history, tables and error states.
- Correct UI or controller issues where the same chat turn can show a failed provider result next to stale tool facts from another request.

**Acceptance:** Financial figures and explanatory text agree; source links resolve only to authorized ERP pages; every unanswered/unsupported query has a clear honest message.

## 9. P1 provider reliability, quota handling and diagnostics

**Previous user-reported themes:** NVIDIA model configuration, unexpected/unreadable responses, 40 RPM capacity, tool-call vs model-call counts, old conversation errors. The branch already includes provider counters and model-invocation telemetry; this work should **verify**, not blindly rewrite, those fixes.

- Record one user message, **number of actual model invocations/steps**, tool calls, retry attempts, input/output tokens, provider response duration and final run status separately. One user message is not always one API call.
- Confirm NVIDIA NIM provider/model configuration, request payload compatibility, timeouts and supported SDK tool-calling features using controlled integration tests. Never hardcode or commit API keys.
- Current default NVIDIA cap is **35 RPM** (`config/erp_ai.php`), deliberately below the user-reported 40 RPM. Confirm limits from the user's configured provider/account, not from a global assumption. Enforce the shared limiter atomically across workers; do not count tool calls as model RPM.
- Validate step-token reservations and settlement on errors/timeouts: no runaway usage, incorrect token totals or permanently blocked requests unless conservative reservation is explicitly documented.
- Separate model/provider failure from **local PHP exceptions, DB/report errors, authorization failure and schema validation**. The controller currently catches general `Throwable` and can classify it as `AI_PROVIDER_UNAVAILABLE` (502); fix the internal error taxonomy and secure logging.
- Ensure thread ownership checks and model history reconstruction preserve correct prior turns without returning stale/failed/wrong-scope content.
- Maintain safe bounded retries and no duplicate expensive model requests from frontend submission or browser refresh.

**Acceptance:** Monitoring explains exactly why a request used 1, 2 or more model calls; the right status is shown for each failure; no regression in chat history or quota accounting.

## 10. P1 additional domain/tool regression sweep

For **every existing registered read capability** (receivables, invoice allocations, purchase/payables summary, financial statements, GST, BOM/jobwork, open orders, document preview, company knowledge and help):

- Confirm actual schema and source report calculations, including business statuses, reversals, payments, units and period rules.
- Confirm scoped queries and links cannot disclose a record through a numerical ID or cached conversation entity.
- Ensure reference/knowledge tool outputs are treated as untrusted business data, never as new instructions that override user or system access policy.
- Review lack-of-data behavior: `null` / missing / incomplete / unreconciled must not become a fabricated zero or false success.
- Ensure all tools are read-only; no direct `create`, `update`, `delete`, `forceCreate`, `dispatch`, `send`, journal/stock posting or arbitrary code execution in exposed read paths.
- Capture a golden expected-output fixture per tool and verify tool results against authoritative ERP workflows.

## 11. Carry-over regression checks from the earlier Laravel/PHP upgrade review

These were **previously reported upgrade risks** and should be treated as **regression gates**, not automatically assumed unresolved in the current AI branch:

- Textile `catalogOnly` migration must not overwrite existing nonzero stock or create unbalanced opening quantities.
- Required semantic account/GST setup and transaction/failure recovery must stay intact.
- `InventoryMovementService` expiry-nullability inspection must fail safely and surface a diagnosable error without assuming a schema constraint.
- Sales quick-create, multi-item and row-detail modals must remain available; do not break the upgraded sales UI.
- Carbon 3 signed date differences in payroll, due management and reports must use verified absolute/signed semantics.
- API auth, existing PHP 8.5 production compatibility, queue workers, background jobs and test safety must remain passing.
- Investigate old PHPUnit risky tests and notices if they remain; don't mistake exit code zero for fully clean test evidence.

**Acceptance:** Existing ERP workflows are not degraded by the AI-related refactor. If the prior bug is already fixed, add/preserve its regression test rather than rewriting it.

### Part A exit gate: required BEFORE refactor starts

- [ ] All P0 tool/write paths disabled and verified no AI-controlled mutations
- [ ] Critical data isolation and permission tests pass
- [ ] Date, sales and stock accuracy regression tests pass
- [ ] Input validation and model error taxonomy tests pass
- [ ] NVIDIA quota/telemetry and history tests pass
- [ ] Existing Copilot UI reads and renders correctly
- [ ] Existing ERP accounting/sales/inventory workflows remain passing
- [ ] Source commit, passing test outputs and remaining known issues documented

**Do not proceed to Part B if any P0 or data-accuracy bug remains.**

---

# PART B — SHARED TOOL ENGINE REFACTOR

## 12. One read registry and SDK adapter

Extend `AiToolManifest` and retain `ErpToolHandlerInterface`, the existing read facades, and `ErpToolExecutor`. Register each reviewed read handler once in `AiServiceProvider` with its provider alias and permission policy. Reject duplicate ERP keys, duplicate aliases, non-read risk, missing permissions, and unsupported schema types at registration. Resolve only the application-owned allowlist.

Use one generic Laravel SDK adapter. The handler's closed scalar schema is canonical for provider advertisement and executor validation. It describes types, optional fields, bounds, date formats, and enums. Pass the complete SDK arguments unchanged to the executor. Resource authorization runs after input validation. Admitted reads run through the same feature gate, live principal refresh, validation, quota, and audit checks regardless of caller.

Keep ERP keys unchanged. Keep the existing provider aliases when they meet the provider contract. Rename the five dotted provider aliases to `sales_summary`, `payables_summary`, `accounting_statements`, `tax_gst_summary`, and `copilot_help`. Use the existing result envelope with additive flat reconciliation and row count metadata. Retain the nested reconciliation field for receivables outstanding and stock, which had that legacy field.

## 13. Cutover and rollback

Freeze the verified Part A baseline before adapter work. The manifest selects the adapter and execution handler for the same key. Prove the receivables adapter by dispatching `CustomerOutstanding` through the installed SDK and preserving the authoritative facts and nested reconciliation. Migrate sales and inventory next, then the remaining reads. Update DeepSeek's existing receivables entry point to use the same adapter. Roll back any adapter migration to the Part A checkpoint, which retains proposal quarantine.

## 14. File and policy boundaries

- Add `app/Ai/DTOs/AiToolRegistration.php` and `app/Ai/Tools/GenericSdkTool.php`.
- Update `app/Ai/Services/AiToolManifest.php`, `app/Ai/Services/AiPrincipalFactory.php`, `app/Providers/AiServiceProvider.php`, `app/Ai/Agents/ErpCopilot.php`, and `app/Ai/Agents/DeepSeekAgent.php`.
- Migrate every existing read wrapper under `app/Ai/Tools/` to the shared adapter. Retain the 14 read handlers and domain facades.
- Update existing tests in `tests/Unit/Ai/AiFoundationTest.php` and `tests/Feature/Ai/` to exercise the adapter and real SDK HTTP fakes. Keep existing ERP regression tests.
- Require `ai.use` plus at least one domain permission for each business capability. Grant knowledge and help only after a business capability is authorized. Order and preview permissions remain operation-specific. Preserve live revocation and the original run allowlist.

## 15. Acceptance

For each migrated read, verify provider alias, schema and domain permission policy against the registry. Preserve the internal key, authoritative values, warnings, error codes, and source links. Verify global and company disablement, rejection of invalid input and out-of-scope resources, and the same tool audit envelope. A read-only setting permits authorized reads. Proposal routes and tools remain disabled even with legacy write flags enabled.

Use the installed SDK against an HTTP fake. Verify actual function declarations and dispatch with the receivables round trip, and a NVIDIA-compatible retry fixture for provider-attempt accounting. Use the actual Copilot workspace Blade in Playwright with fixture data. Cover desktop history, mobile submission, source links, reconciliation warnings, provider failure, and inert legacy proposal cards. For stock, assert full totals and nine stock aggregation queries for 600 products across three batches while returning at most the requested rows.

---

# PART C — EXECUTION PLAN, DELIVERABLES AND RELEASE GATES

## 16. Exact execution order

| Step | Stage | Work | Completion requirement |
|---:|---|---|---|
| 0 | Branch | Create `refactor/erp-copilot-stabilization` from `origin/feature/ai-agent-foundation` | Recorded source SHA and clean new branch |
| 1 | P0 bugfix | Quarantine unfinished proposal/write paths | All AI mutations denied, read tools unaffected |
| 2 | P0/P1 bugfix | Tenant/branch/FY and source-link validation | Cross-tenant and stale-scope tests pass |
| 3 | P1 bugfix | Date validation, sales reconciliation, inventory correctness/performance | Golden ERP report comparisons pass |
| 4 | P1 bugfix | Strict input validation, provider errors/quotas, narrative/UI integrity | Fuzz, timeout, rate-limit and history tests pass |
| 5 | Regression | Verify earlier Laravel/PHP bugs and current ERP workflows | No new sales/stock/accounting regressions |
| 6 | Refactor | Introduce allowlisted registry, schema/permission metadata, generic SDK adapter | No behavior change for original tools |
| 7 | Refactor | Migrate read tools one by one, delete verified redundant wrappers | Contract parity passes per tool |
| 8 | QA | Full AI tests, PHP 8.5 / MySQL suites, browser UI, security and performance | Passing recorded CI plus manual UAT |
| 9 | Handoff | Document architecture, supported capabilities, deferred work | PR reviewed and signed off |

## 17. Recommended commit/PR grouping

```text
fix(ai): disable unfinished Copilot action execution
fix(ai): enforce ERP scope and strict input/date validation
fix(ai): reconcile sales and inventory summaries to authoritative reports
fix(ai): improve provider error diagnostics, history and output accuracy
test(ai): add Copilot golden dataset and tenant/security regressions
refactor(ai): introduce explicit approved capability registry
refactor(ai): add generic Laravel SDK tool adapter
refactor(ai): migrate receivables, sales and inventory read capabilities
refactor(ai): migrate remaining read capabilities and remove wrappers
docs(ai): record benchmarks, runbook and release evidence
```

Keep the stabilization changes reviewable separately from the architectural migration even if delivered within one feature branch.

## 18. Test commands and release evidence

Run only from an authorized checkout configured with **dedicated test database credentials**, never the production database:

```bash
php -v
php artisan --version
composer validate --strict
composer check-platform-reqs
composer audit
php artisan route:list

# On a SAFE disposable test DB only:
# Set ERP_TEST_MYSQL=1 and ERP_TEST_MYSQL_DATABASE to the named zolo_test_* database.
# Point DB_CONNECTION/DB_DATABASE and DB credentials to that same fixture.
php artisan migrate --force
php vendor/bin/phpunit -c phpunit.ai.xml

# Run the repository's existing broader company, commercial, accounting,
# inventory, migration and browser regression suites according to its CI workflows.
# Do not run destructive migrations/seeders against shared or production databases.
```

**Evidence to attach to PR:** exact SHAs, file changes, old-versus-new tool registry map, baseline and final test logs, security/tenant test results, report reconciliation snapshots, Copilot UI screenshots, provider simulated-failure results, representative tool/run usage counters, and large-dataset query/performance measurements.

## 19. Final definition of done

- [ ] New branch created from the **current AI branch** before any code changes.
- [ ] P0 unsafe proposal execution is disabled; no AI write or dispatch possible.
- [ ] Every Copilot tool uses correct company/branch/FY and resource-level authorization.
- [ ] Sales, receivables, inventory and other tool results reconcile with existing ERP sources.
- [ ] Dates, quantities and monetary values are accurate; no silent out-of-range period changes.
- [ ] Invalid/unsupported/user-unpermitted tool calls fail safely with correct error codes.
- [ ] NVIDIA/provider call counts, RPM, token usage, timeouts, retries and failures are observable.
- [ ] Chat history and presentation are stable; no misleading numerical narration or stale facts.
- [ ] Shared registry and SDK adapter replace only proven boilerplate; tests show contract parity.
- [ ] No runtime class/method/SQL execution is chosen dynamically by the LLM.
- [ ] Existing Laravel 13 / PHP 8.5 and ERP core workflows pass regressions.
- [ ] Final branch has no secrets, no generated caches, no unintended changes to domain write services.
- [ ] Documentation explains which capabilities are read-only and which future features are deferred.

## 20. Explicitly deferred (not part of this branch)

**Do not implement** any of the following in this work:

- Action Engine redesign or live purchase order/bill/sales invoice/journal posting from chat
- Approval orchestration, posting callbacks, outbound payment reminders or autonomous writes
- Sales Intelligence, Inventory Agent or Accounting Agent as separate agent experiences
- Dedicated multi-agent router, Neuron AI, MCP, document RAG/vector DB or Langfuse deployment
- Broad ERP domain-service rewrites, unrelated UI redesign, or Laravel/PHP upgrades

A later action agent may reuse authorized reads, schema conventions, and audit correlation. It can produce typed proposals through a separate allowlist. Human approval, live authorization, idempotency, and transactional ERP commands require their own entry point and separate design review.

---

## 21. Source files reviewed for this plan

Actual source on `feature/ai-agent-foundation` at `a1477a679dc0f9ea98ce8cc2d531a1c997e20864`:

- [`app/Ai/Agents/ErpCopilot.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Agents/ErpCopilot.php)
- [`app/Ai/Services/ErpToolExecutor.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Services/ErpToolExecutor.php)
- [`app/Ai/Services/AiToolManifest.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Services/AiToolManifest.php)
- [`app/Ai/Services/AiPrincipalFactory.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Services/AiPrincipalFactory.php)
- [`app/Ai/Services/ReadCustomerOutstanding.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Services/ReadCustomerOutstanding.php)
- [`app/Ai/Services/ReadInventoryStockSummary.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Services/ReadInventoryStockSummary.php)
- [`app/Ai/Services/ReadSalesAnalytics.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Services/ReadSalesAnalytics.php)
- [`app/Ai/Services/ActionProposalService.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Services/ActionProposalService.php)
- [`app/Ai/Gateway/ApprovedModelGateway.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Ai/Gateway/ApprovedModelGateway.php)
- [`app/Http/Controllers/Api/V1/AiChatController.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Http/Controllers/Api/V1/AiChatController.php)
- [`app/Providers/AiServiceProvider.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/app/Providers/AiServiceProvider.php)
- [`config/erp_ai.php`](https://github.com/vigneshsinna/zolo-erp-pro/blob/feature/ai-agent-foundation/config/erp_ai.php)
- Current AI feature/unit tests under `tests/Feature/Ai` and `tests/Unit/Ai`.

**Evidence convention:** "source-confirmed" means the reviewed code visibly contains the pattern. It does not mean a runtime exploit or functional failure was reproduced. "Regression check" means a previous issue that must be verified but may already have been fixed. Changes should be validated against actual current business semantics, not guessed.
