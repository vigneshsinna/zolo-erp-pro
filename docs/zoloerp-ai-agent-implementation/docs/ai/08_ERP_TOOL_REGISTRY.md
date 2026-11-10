# 08 — ERP tool registry and authoritative business adapters

## Architecture

Create an application-owned **`ErpToolExecutor`** invoked by both Laravel AI tools and future MCP tool adapters. Its order must be: identify tool → validate schema → resolve actor/scope → authorise role and feature → verify resource ownership → apply rate/cost/row limits → execute deterministic service → attach evidence → audit. MCP must **never bypass** this executor.

## Tool manifest v1 (read-only)

| Tool key | Model input (validated) | Backend data source | Permission | Evidence |
|---|---|---|---|---|
| `receivables.outstanding.v1` | `as_of?: date`, `limit:1..20` | Authorised read facade around AR ageing / open-item control | `ai.use` + customer dues report read | open-item total, control difference, source report URL, actor scope |
| `sales.summary.v1` | `from`, `to`, `group_by?: day/month` | Sales reporting service with returns and branch scope | sales report read | gross/net definition, count, source links |
| `sales.top_products.v1` | `from`, `to`, `limit` | Actual sales line/return query | sales report read | qty and currency basis, product IDs |
| `inventory.availability.v1` | `product_id`, `warehouse_id?` | Refactored authorised stock facade | stock report read | on-hand, reserved, available, ledger state |
| `accounting.trial_balance.v1` | `from`, `to` | `FinancialReportService::getTrialBalance` via actor-explicit facade | accounting report read | debit, credit, difference, period |
| `accounting.pnl.v1` | `from`, `to` | `FinancialReportService::getProfitAndLoss` | accounting report read | calculation source, reporting currency |
| `jobwork.pending.v1` | `as_of`, `limit` | Existing authorised job work service | industry capability + operations read | documents and dates |

**Never** make a `run_sql`, `call_route`, `execute_php`, `run_artisan`, `fetch_any_url`, `write_file` or generic `invoke_any_service` tool.

## Type contract proposal

```php
interface ErpToolHandler
{
    public function key(): string;
    public function risk(): string; // read, draft, controlled_write
    public function authorize(AiPrincipal $principal, array $input): void;
    public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult;
}

final readonly class ErpToolResult
{
    public function __construct(
        public string $toolKey,
        public array $scope,
        public array $data,
        public array $sourceRefs,
        public array $warnings,
        public ?string $asOf,
        public bool $reconciled,
    ) {}
}
```

These types are **ours to implement**, not SDK-provided. Laravel AI's `Tool` contract is the thin adapter that validates its input and invokes `ErpToolExecutor`. All financial amounts remain strings/decimal-safe from existing `LedgerAmount` logic, not floating point recomputed by the LLM.

## Read tool design rules

- `limit` max 20 for rows shown to model; aggregated totals must be computed against **all authorised rows**, not just displayed top N.
- Use cursor or limited database queries; avoid loading all company activity into prompts.
- Validate date within permitted FY; `as_of` uses company timezone, not worker time.
- Where a service has an explicit read/reconciliation contract, preserve its branch and accounting rules. If its result is unreconciled, include an unambiguous warning.
- `sourceRefs` must be server-generated URLs to existing ERP report pages with resource IDs and dates, then validated to avoid open redirects.
- Refuse to resolve a customer, product, warehouse or account belonging to a different company even when the ID is valid.
- For any data exposed through MCP, call the same executor; do not duplicate database queries in MCP tools.

## Tool catalogue policy

`ai_tools` can be a code manifest initially (safer than user-configured PHP class names); maintain versioned keys, description, schema hash, risk classification, required permissions, enabled industry capabilities, maximum rows, and allowed response keys. Company settings can disable tools but cannot add arbitrary untrusted tool classes.

## Source metadata and number verification

On tool execution, attach `query_time`, `as_of`, `currency`, `company_id`, `branch_id`, `financial_year_id`, `source_type`, `source_record_ids` (bounded), `control_diff` and an optional signed internal report URL. Before rendering, verify model-copied numbers or display the authoritative tool results in deterministic UI cards, with the model restricted to commentary.

## Error taxonomy

- `AI_UNAUTHENTICATED`, `AI_TOOL_FORBIDDEN`, `AI_SCOPE_INVALID`, `AI_FY_OUT_OF_RANGE`, `AI_RESULT_TOO_LARGE`, `AI_RECONCILIATION_FAILED`, `AI_DOMAIN_UNAVAILABLE`, `AI_MODEL_RATE_LIMITED`, `AI_MODEL_TIMEOUT`, `AI_BUDGET_EXCEEDED`.
- Never include raw SQL, stack traces, internal hostnames or provider secrets in browser-facing errors.
