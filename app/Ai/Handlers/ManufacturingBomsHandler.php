<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadManufacturingBoms;

/**
 * Authoritative handler for 'manufacturing.boms.v1'.
 */
class ManufacturingBomsHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadManufacturingBoms $readFacade
    ) {}

    public function key(): string
    {
        return 'manufacturing.boms.v1';
    }

    public function description(): string
    {
        return 'Get Bill of Materials (BOM) specifications, finished goods output, and required raw material components for manufacturing.';
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
                'product_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional finished good product ID to filter BOMs for.',
                ],
                'code' => [
                    'type' => 'string',
                    'description' => 'Optional BOM code or keyword search.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                    'description' => 'Maximum number of BOM records to return (default 10).',
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
        $productId = isset($validatedInput['product_id']) ? (int) $validatedInput['product_id'] : null;
        $code = $validatedInput['code'] ?? null;
        $limit = isset($validatedInput['limit']) ? (int) $validatedInput['limit'] : 10;

        return $this->readFacade->execute($principal, $productId, $code, $limit);
    }
}

