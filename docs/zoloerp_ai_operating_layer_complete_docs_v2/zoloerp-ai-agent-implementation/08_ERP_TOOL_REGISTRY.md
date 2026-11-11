# 08 — Shared ERP Read Tool Registry and Capability Conventions

> **Specification version:** 2.0 · **Reviewed code baseline:** `vigneshsinna/zolo-erp-pro` / `refactor/erp-copilot-stabilization` @ `6ded3715275e7ad8bd60eb7410428af03e4fc304` (2026-11-10). **Target design, not proof of implementation.** Verify the current branch HEAD, installed packages, business-service contracts and CI evidence before executing.

**Status:** Design specification

## Current read-tool design
One explicit `AiToolManifest` registers `AiToolRegistration(handler, providerName, requiredAny, operationsCapability?, legacyReconciliation?)`. The current manifest restricts registered handlers to `risk() === 'read_only'`, requires `ai.use`, and verifies scalar closed-object schemas. `GenericSdkTool` converts the handler's schema to Laravel AI SDK and dispatches through `ErpToolExecutor`. **Keep this read-only contract intact.**

## Inspected read capability families (14 current handlers)
| Family | Handler(s) | Source principle |
|---|---|---|
| AR | CustomerOutstanding, CustomerInvoices, InvoiceAllocations | Open-item/ledger controls and authorised customer records |
| Sales | SalesSummary | Posted business documents, returns/credits, approved currency semantics |
| Inventory | InventoryStockSummary | Scoped warehouse, reservations, unit conversion and reconciliation |
| Payables | PayablesSummary | Supplier open items, branch/FY |
| Finance & GST | FinancialStatements, GstSummary | Authoritative financial/tax reports; no compliance filing claim |
| Operations | ManufacturingBoms, JobWorkStatus, OpenOrders | Industry capability and source lifecycle state |
| Navigation/knowledge | DocumentPreview, CompanyKnowledge, CopilotHelp | Source links and verified settings, not invented policies |

## Shared tool metadata requirements
Each capability has: internal versioned key; SDK provider alias; description; closed JSON schema; required-all and required-any effective permissions; industry/company feature; risk; data classification; max rows; max bytes; timeout; expected result schema; source refs; audit. Registration fails on duplicates, unrecognized type/format/enum, missing `ai.use`, non-read risk or ownership field in model input.

## Read executor sequence
1. Lookup explicit registration and reject unregistered key.
2. Validate original arguments, required/optional fields, types, bounds and resource ownership.
3. Refresh `AiPrincipal` against user membership, company/branch/FY, feature and permissions.
4. Apply domain-specific authorization (sales report vs purchase report vs valuation).
5. Enforce tool count, time, row and output size budgets.
6. Call authoritative read facade with explicit context and actor ID.
7. Build typed result `{tool,status,scope,data,as_of,row_count,source_refs,warnings,reconciled,error_code}`.
8. Redact audit input, log correlation and return allowed facts to agent/UI.

## Accuracy conventions
- Totals computed over **all authorised source rows**, never only top N.
- Monetary arithmetic exact-decimal; no implicit FX default presented as reconciled.
- Dates use ERP's real posting/document semantics and selected FY timezone.
- Stock distinguishes on-hand, reserved, available and stock-ledger divergence; do not add incomparable units.
- Links created by server to real authorised routes; no external model-suggested URL.

## Future action registry is distinct
Do **not** allow `risk='controlled_write'` in this existing manifest merely to avoid creating another registry. Introduce `ActionCapabilityRegistry` with reviewed command adapters and independent plan/preview/approve/execute API. Read capabilities can be shared by both planner and Copilot. Action execution is PHP-only after authenticated approval, never a model-facing generic execute tool. See `28_ACTION_CAPABILITY_CATALOG.md`.

## Migration acceptance
The old-to-new wrapper migration is already implemented at reviewed SHA; preserve 14 read results, provider aliases, nested reconciliation compatibility where applicable, source links, warnings, deny/error semantics, and actual SDK function-call tests. New capabilities should require substantially less repetitive wiring without reducing domain logic/tests.
