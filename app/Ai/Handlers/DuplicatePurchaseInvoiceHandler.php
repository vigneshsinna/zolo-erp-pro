<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadDuplicatePurchaseInvoice;

final class DuplicatePurchaseInvoiceHandler implements ErpToolHandlerInterface
{
    public function __construct(private readonly ReadDuplicatePurchaseInvoice $reader) {}

    public function key(): string
    {
        return 'erp_checks.duplicate_purchase';
    }

    public function description(): string
    {
        return 'Check possible matching supplier invoice numbers within authorized purchases in the active company, branch and fiscal year.';
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
                'supplier_invoice_no' => [
                    'type' => 'string', 'maxLength' => 80,
                    'description' => 'Exact supplier invoice number supplied by the user. Never invent a number.',
                ],
            ],
            'required' => ['supplier_invoice_no'],
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
        return $this->reader->execute($principal, $validatedInput['supplier_invoice_no']);
    }
}
