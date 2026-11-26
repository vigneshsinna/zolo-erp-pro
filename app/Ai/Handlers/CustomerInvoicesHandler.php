<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadCustomerInvoices;

/**
 * Authoritative handler for 'receivables.invoices.v1'.
 */
class CustomerInvoicesHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadCustomerInvoices $readFacade
    ) {}

    public function key(): string
    {
        return 'receivables.invoices.v1';
    }

    public function description(): string
    {
        return 'Get exact customer invoice details, invoice numbers, document dates, due dates, original and remaining amounts, and payment allocations for an authorized customer or branch.';
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
                'customer_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Target customer ID to inspect invoices for.',
                ],
                'as_of' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Optional reporting date in YYYY-MM-DD format (within active financial year).',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['open', 'all', 'overdue'],
                    'default' => 'open',
                    'description' => 'Invoice status filter: open (default), all, or overdue.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 20,
                    'description' => 'Maximum number of invoice rows to return (default 20, maximum 50).',
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
        $customerId = isset($validatedInput['customer_id']) ? (int) $validatedInput['customer_id'] : null;
        $asOf = $validatedInput['as_of'] ?? null;
        $status = $validatedInput['status'] ?? 'open';
        $limit = isset($validatedInput['limit']) ? (int) $validatedInput['limit'] : 20;

        return $this->readFacade->execute($principal, $customerId, $asOf, $status, $limit);
    }
}

