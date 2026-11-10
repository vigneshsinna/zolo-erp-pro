<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadGstSummary;

class GstSummaryHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadGstSummary $service
    ) {}

    public function key(): string
    {
        return 'tax.gst_summary.v1';
    }

    public function description(): string
    {
        return 'Retrieve authoritative GST tax liability breakdown (CGST, SGST, IGST, Cess) and input tax credit for the active branch and financial year.';
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
                    'format' => 'date',
                    'description' => 'Start date (YYYY-MM-DD) inside active financial year.',
                ],
                'to' => [
                    'type' => 'string',
                    'format' => 'date',
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
            from: $validatedInput['from'] ?? null,
            to: $validatedInput['to'] ?? null
        );
    }
}
