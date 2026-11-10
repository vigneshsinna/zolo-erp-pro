# 09 — Working vertical slice: customer outstanding copilot

**Goal:** a read-only end-to-end path from chat input to validated AR ageing and an audit record. This is the **first functional milestone**. It must pass the tenant-isolation tests before adding other AI tools.

## Why receivables first

`FinancialReportService::ageing(CompanyContext $context, string $partyType, ?string $asOf, array $filters)` already calculates open-item amounts, age buckets and reconciliation with control accounts for a selected branch. The method currently calls `CompanyWriteGuard::context($context, auth()->id())`, so asynchronous use requires an actor-explicit read interface, **not** a direct service call with absent HTTP auth.

## Sequence of tasks

1. Add `AiPrincipal` immutable class with actor + CompanyContext resolved by the **authenticated controller**.
2. Add `ReadCustomerOutstanding` domain facade; make all reads use the existing finance service's correct branch, FY and date semantics. If current service is HTTP-auth dependent, refactor to accept an explicit authorised actor or create a separate, tested read path; do **not** bypass `CompanyWriteGuard` or fake `auth()`.
3. Implement `CustomerOutstanding` as a `Laravel\Ai\Contracts\Tool`; tool model schema exposes only `as_of` and `limit`. The model does **not** supply user/company/branch/FY.
4. `ErpToolExecutor` revalidates actor and business report permission, records audit, returns typed result and reconciled/exception status.
5. Add read-only `ErpCopilot` agent registering only that tool. Wrap SDK call in `ApprovedModelGateway` with provider budget, failure handling and tracing.
6. Add `POST /api/v1/ai/chat` and `GET /api/v1/ai/conversations` with existing session/Sanctum authentication, context middleware and per-user throttling. Check history ownership in database.
7. Add Blade AI Workspace with one question input, progress state, authoritative amount card, due-table and source-report link.
8. Add unit, integration, adversarial and browser tests; ship behind `AI_ENABLED=false` until pilot.

## Tool skeleton — adapted from official Laravel AI SDK tool contract

```php
<?php
namespace App\Ai\Tools;

use App\Ai\Security\AiPrincipal;
use App\Ai\Tools\ErpToolExecutor; // zoloERP-owned class to create
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class CustomerOutstanding implements Tool
{
    public function __construct(private readonly AiPrincipal $principal) {}

    public function description(): Stringable|string
    {
        return 'Get authorised customer outstanding ageing for the current company, branch and financial year.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()->description('Optional date in YYYY-MM-DD format'),
            'limit' => $schema->integer()->min(1)->max(20),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        // Do not pass model-provided company/branch/actor IDs.
        $validated = validator([
            'as_of' => $request['as_of'] ?? null,
            'limit' => $request['limit'] ?? 5,
        ], [
            'as_of' => 'nullable|date_format:Y-m-d',
            'limit' => 'integer|min:1|max:20',
        ])->validate();

        $result = app(ErpToolExecutor::class)->run(
            'receivables.outstanding.v1', $this->principal, $validated
        );
        return json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    }
}
```

**Important:** `AiPrincipal`, `ErpToolExecutor` and `toArray()` are proposed application classes/methods and **must be implemented**; the skeleton is not a standalone drop-in file. The SDK tool interface and JSON schema methods are supported in the Laravel 13 docs as of planning date; check generated code after install.

## Deterministic tool result example — synthetic

```json
{
  "schema_version": 1,
  "tool": "receivables.outstanding.v1",
  "scope": { "company_id": 22, "branch_id": 9, "financial_year_id": 17 },
  "as_of": "2026-11-10",
  "currency": "INR",
  "data": {
    "outstanding_total": "842500.00",
    "top_customers": [
      { "customer_id": 51, "name": "Example Customer", "due": "157000.00" }
    ]
  },
  "reconciliation": { "is_reconciled": true, "difference": "0.00" },
  "warnings": [],
  "source_refs": [{ "type": "erp-report", "href": "/report/customer?as_of=2026-11-10" }]
}
```

The numbers and IDs are **dummy examples**; no live ERP data is included. The real adapter must calculate the total over all authorised rows, aggregate per party, sort descending, cap shown rows, and provide control-account reconciliation. Do not rely on the SDK to calculate amounts.

## Acceptance scenarios

| Test | Expected result |
|---|---|
| Authorised branch user asks for top five dues | Correct total (all rows), five ranked customers, source link |
| A company user asks for another company | 403 or equivalent; no tool/data leak |
| One-branch user asks for another branch | Denied |
| Closed FY historical date read within authorised FY | Only data permitted by existing report policy |
| Requested as-of outside selected FY | Validation error |
| AR ledger unreconciled | Report shows discrepancy; AI does not state reconciled |
| Customer has credit/negative balance | Credit handled consistently with authoritative ageing; not counted as positive overdue without rule |
| Provider is down | No invented numbers; safe retry/error; no ERP changes |
| Stale `conversationId` from another user | Denied |
| Repeated prompt/tool invocation | Rate/quota control, stable audit correlation |

## End-of-phase demo

Show an authenticated user asking: "Who are my five largest outstanding customers?". The UI displays exact source-linked figures, allowed branch/date, reconciliation state and an audit ID. Then demonstrate the same question from a disallowed company account returning no business data.
