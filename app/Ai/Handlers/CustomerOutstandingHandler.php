<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadCustomerOutstanding;

/**
 * Authoritative handler for 'receivables.outstanding.v1'.
 */
class CustomerOutstandingHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadCustomerOutstanding $readFacade
    ) {}

    public function key(): string
    {
        return 'receivables.outstanding.v1';
    }

    public function description(): string
    {
        return 'Get authorised customer outstanding dues, aging, and top receivable parties for the current company, branch and financial year.';
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
                'as_of' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Optional reporting date in YYYY-MM-DD format (within active financial year).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                    'default' => 5,
                    'description' => 'Number of top outstanding customers to return (default 5, maximum 20).',
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
        // Enforce principal tenancy boundaries
        return $principal->isActive && $principal->hasTool($this->key());
    }

    public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult
    {
        $asOf = $validatedInput['as_of'] ?? null;
        $limit = isset($validatedInput['limit']) ? (int) $validatedInput['limit'] : 5;

        return $this->readFacade->execute($principal, $asOf, $limit);
    }
}

