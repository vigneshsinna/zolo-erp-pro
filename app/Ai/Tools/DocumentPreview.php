<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DocumentPreview implements Tool
{
    public function __construct(
        protected AiPrincipal $principal
    ) {}

    public function name(): string
    {
        return 'document_preview';
    }

    public function description(): string
    {
        return 'Retrieve authorized ERP document preview, header details, line items count, totals, and direct view links.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_type' => $schema->string()->description('Document type: sale, purchase, or invoice (default sale).'),
            'reference_no' => $schema->string()->description('Document reference number (e.g. SO-2026-001 or PO-1002).'),
            'document_id' => $schema->integer()->description('Optional document primary key ID.'),
        ];
    }

    public function handle(Request $request): string
    {
        $rawInput = $request->all();

        $executor = app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'documents.preview.v1',
            rawInput: $rawInput
        );

        return (string) json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    }
}
