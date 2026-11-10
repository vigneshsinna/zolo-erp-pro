<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadOpenOrders;

/**
 * Authoritative handler for 'orders.open.v1'.
 */
class OpenOrdersHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadOpenOrders $readFacade
    ) {}

    public function key(): string
    {
        return 'orders.open.v1';
    }

    public function description(): string
    {
        return 'Query open and unfulfilled sales orders, pending customer deliveries, and open purchase orders.';
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
                'type' => [
                    'type' => 'string',
                    'enum' => ['all', 'sale', 'purchase'],
                    'default' => 'all',
                    'description' => 'Order type filter: all (default), sale, purchase.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 20,
                    'description' => 'Maximum number of order records to return (default 20).',
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
        $type = $validatedInput['type'] ?? 'all';
        $limit = isset($validatedInput['limit']) ? (int) $validatedInput['limit'] : 20;

        return $this->readFacade->execute($principal, $type, $limit);
    }
}

