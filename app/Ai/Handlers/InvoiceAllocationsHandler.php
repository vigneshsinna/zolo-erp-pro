<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadInvoiceAllocations;

/**
 * Authoritative handler for 'receivables.invoice_allocations.v1'.
 */
class InvoiceAllocationsHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadInvoiceAllocations $readFacade
    ) {}

    public function key(): string
    {
        return 'receivables.invoice_allocations.v1';
    }

    public function description(): string
    {
        return 'Get payment allocations, receipts, credit notes, and reversals applied against a specific open invoice item.';
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
                'open_item_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Target open item ID to inspect allocations for.',
                ],
                'as_of' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Optional reporting date in YYYY-MM-DD format (within active financial year).',
                ],
            ],
            'required' => ['open_item_id'],
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
        $openItemId = (int) $validatedInput['open_item_id'];
        $asOf = $validatedInput['as_of'] ?? null;

        return $this->readFacade->execute($principal, $openItemId, $asOf);
    }
}

