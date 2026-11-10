<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadInventoryStockSummary;

/**
 * Authoritative handler for 'inventory.stock_summary.v1'.
 */
class InventoryStockSummaryHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadInventoryStockSummary $readFacade
    ) {}

    public function key(): string
    {
        return 'inventory.stock_summary.v1';
    }

    public function description(): string
    {
        return 'Get authorised stock availability summary, on-hand, reserved, and available quantities by unit for the active company and warehouse.';
    }

    public function risk(): string
    {
        return 'read_only';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_query' => [
                    'type' => 'string',
                    'description' => 'Optional product name or code to check stock availability for a specific item.',
                ],
                'warehouse_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional warehouse ID to scope stock availability to a specific warehouse.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                    'description' => 'Number of top stock products to return (default 10, maximum 50).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function requiredPermissions(): array
    {
        return ['ai.use'];
    }

    public function authorize(AiPrincipal $principal, array $input): bool
    {
        return $principal->isActive && $principal->hasTool($this->key());
    }

    public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult
    {
        return $this->readFacade->execute(
            principal: $principal,
            productQuery: $validatedInput['product_query'] ?? null,
            warehouseId: isset($validatedInput['warehouse_id']) ? (int) $validatedInput['warehouse_id'] : null,
            limit: (int) ($validatedInput['limit'] ?? 10)
        );
    }
}
