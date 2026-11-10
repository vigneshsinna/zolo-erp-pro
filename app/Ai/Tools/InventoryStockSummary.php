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
 * Laravel AI SDK Tool adapter for Inventory Stock Summary.
 */
class InventoryStockSummary implements Tool
{
    public function __construct(
        protected readonly AiPrincipal $principal,
        protected readonly ?ErpToolExecutor $executor = null
    ) {}

    public function description(): Stringable|string
    {
        return 'Get authorised stock availability summary, on-hand, reserved, and available quantities by unit for the active company and warehouse.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_query' => $schema->string()->description('Optional product name or code to check stock for a specific item'),
            'warehouse_id' => $schema->integer()->description('Optional warehouse ID to scope stock to a specific warehouse'),
            'limit' => $schema->integer()->description('Number of top stock products to return (1 to 50)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $rawQuery = $request['product_query'] ?? null;
        $productQuery = is_string($rawQuery) && trim($rawQuery) !== '' ? trim($rawQuery) : null;
        $warehouseId = is_numeric($request['warehouse_id'] ?? null) ? (int) $request['warehouse_id'] : null;
        $limit = is_numeric($request['limit'] ?? null) ? max(1, min(50, (int) $request['limit'])) : 10;

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'inventory.stock_summary.v1',
            rawInput: [
                'product_query' => $productQuery,
                'warehouse_id' => $warehouseId,
                'limit' => $limit,
            ]
        );

        return (string) json_encode([
            'status' => $result->status,
            'tool' => $result->toolKey,
            'scope' => $result->scope,
            'as_of' => $result->asOf,
            'data' => $result->data,
            'reconciliation' => [
                'is_reconciled' => $result->reconciled,
            ],
            'warnings' => $result->warnings,
            'source_refs' => $result->sourceRefs,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ], JSON_THROW_ON_ERROR);
    }
}
