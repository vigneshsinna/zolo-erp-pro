<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadFinancialStatements;

class FinancialStatementsHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadFinancialStatements $service
    ) {}

    public function key(): string
    {
        return 'accounting.statements.v1';
    }

    public function description(): string
    {
        return 'Retrieve authoritative financial statements: Profit and Loss (P&L), Balance Sheet, or Trial Balance for the active company and financial year.';
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
                'statement_type' => [
                    'type' => 'string',
                    'enum' => ['profit_and_loss', 'balance_sheet', 'trial_balance'],
                    'description' => "Statement type to generate. Defaults to 'profit_and_loss'.",
                ],
                'from' => [
                    'type' => 'string',
                    'description' => 'Start date (YYYY-MM-DD) inside active financial year.',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'End date (YYYY-MM-DD) inside active financial year.',
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
            statementType: $validatedInput['statement_type'] ?? 'profit_and_loss',
            from: $validatedInput['from'] ?? null,
            to: $validatedInput['to'] ?? null
        );
    }
}
