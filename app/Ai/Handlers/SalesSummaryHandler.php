<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadSalesAnalytics;

class SalesSummaryHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadSalesAnalytics $service
    ) {}

    public function key(): string
    {
        return 'sales.summary.v1';
    }

    public function description(): string
    {
        return 'Authoritative summary of sales transactions, turnover, tax, discounts, and top selling products for the active branch and financial year.';
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
                'from' => [
                    'type' => 'string',
                    'description' => 'Start date for sales period in YYYY-MM-DD format (must fall inside active financial year).',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'End date for sales period in YYYY-MM-DD format (defaults to today or FY end date).',
                ],
                'warehouse_id' => [
                    'type' => 'integer',
                    'description' => 'Optional warehouse ID filter. Must belong to the active branch.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum top products to return (default 10, max 50).',
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
        return $this->service->execute(
            principal: $principal,
            from: $validatedInput['from'] ?? null,
            to: $validatedInput['to'] ?? null,
            warehouseId: isset($validatedInput['warehouse_id']) ? (int) $validatedInput['warehouse_id'] : null,
            limit: isset($validatedInput['limit']) ? (int) $validatedInput['limit'] : 10
        );
    }
}
