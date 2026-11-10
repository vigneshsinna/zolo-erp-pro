<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadDocumentPreview;

/**
 * Authoritative handler for 'documents.preview.v1'.
 */
class DocumentPreviewHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadDocumentPreview $readFacade
    ) {}

    public function key(): string
    {
        return 'documents.preview.v1';
    }

    public function description(): string
    {
        return 'Look up an authorized ERP document (sale invoice, purchase order) and generate an authenticated preview link and summary.';
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
                'document_type' => [
                    'type' => 'string',
                    'enum' => ['sale', 'purchase', 'invoice'],
                    'default' => 'sale',
                    'description' => 'Document type: sale, purchase, or invoice.',
                ],
                'reference_no' => [
                    'type' => 'string',
                    'description' => 'Document reference number (e.g. SO-2026-001 or PO-1002).',
                ],
                'document_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional document primary key ID.',
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
        $docType = $validatedInput['document_type'] ?? 'sale';
        $refNo = $validatedInput['reference_no'] ?? null;
        $docId = isset($validatedInput['document_id']) ? (int) $validatedInput['document_id'] : null;

        return $this->readFacade->execute($principal, $docType, $refNo, $docId);
    }
}

