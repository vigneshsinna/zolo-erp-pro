<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Laravel AI SDK Tool adapter for Customer Invoices.
 */
class CustomerInvoices implements Tool
{
    public function __construct(
        protected readonly AiPrincipal $principal,
        protected readonly ?ErpToolExecutor $executor = null
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get exact customer invoice details, invoice numbers, document dates, due dates, original and remaining amounts, and payment allocations for an authorized customer or branch.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->integer()->description('Optional target customer ID to inspect invoices for'),
            'as_of' => $schema->string()->description('Optional reporting date in YYYY-MM-DD format (within active financial year)'),
            'status' => $schema->string()->description("Invoice status filter: 'open' (default), 'all', or 'overdue'"),
            'limit' => $schema->integer()->description('Maximum number of invoice rows to return (default 20, max 50)'),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $customerId = is_numeric($request['customer_id'] ?? null) ? (int) $request['customer_id'] : null;

        $rawAsOf = $request['as_of'] ?? null;
        $asOf = null;
        if (is_string($rawAsOf) && trim($rawAsOf) !== '') {
            $rawAsOf = trim($rawAsOf);
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawAsOf, $matches)) {
                $asOf = $matches[0];
            }
        }

        $rawStatus = (string) ($request['status'] ?? 'open');
        $status = in_array($rawStatus, ['open', 'all', 'overdue'], true) ? $rawStatus : 'open';
        $limit = is_numeric($request['limit'] ?? null) ? max(1, min(50, (int) $request['limit'])) : 20;

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'receivables.invoices.v1',
            rawInput: array_filter([
                'customer_id' => $customerId,
                'as_of' => $asOf,
                'status' => $status,
                'limit' => $limit,
            ], fn ($v) => $v !== null)
        );

        return (string) json_encode([
            'status' => $result->status,
            'tool' => $result->toolKey,
            'scope' => $result->scope,
            'as_of' => $result->asOf,
            'data' => $result->data,
            'source_refs' => $result->sourceRefs,
            'warnings' => $result->warnings,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'message' => $result->message,
        ], JSON_THROW_ON_ERROR);
    }
}
