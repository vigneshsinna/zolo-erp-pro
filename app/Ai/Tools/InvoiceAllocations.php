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
 * Laravel AI SDK Tool adapter for Invoice Allocations.
 */
class InvoiceAllocations implements Tool
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
        return 'Get payment allocations, receipts, credit notes, and reversals applied against a specific open invoice item.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'open_item_id' => $schema->integer()->description('Required open item ID to inspect allocations for'),
            'as_of' => $schema->string()->description('Optional reporting date in YYYY-MM-DD format (within active financial year)'),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $openItemId = (int) ($request['open_item_id'] ?? 0);

        $rawAsOf = $request['as_of'] ?? null;
        $asOf = null;
        if (is_string($rawAsOf) && trim($rawAsOf) !== '') {
            $rawAsOf = trim($rawAsOf);
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawAsOf, $matches)) {
                $asOf = $matches[0];
            }
        }

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'receivables.invoice_allocations.v1',
            rawInput: array_filter([
                'open_item_id' => $openItemId,
                'as_of' => $asOf,
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
