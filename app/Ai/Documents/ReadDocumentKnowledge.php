<?php

declare(strict_types=1);

namespace App\Ai\Documents;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class ReadDocumentKnowledge implements Tool
{
    public function __construct(private AiPrincipal $principal) {}
    public function name(): string { return 'read_document_knowledge'; }
    public function description(): string { return app(DocumentKnowledgeHandler::class)->description(); }
    public function schema(JsonSchema $schema): array
    {
        return ['question' => $schema->string()->max(2000)->required(), 'limit' => $schema->integer()->min(1)->max(10)];
    }
    public function handle(Request $request): string
    {
        return json_encode(app(ErpToolExecutor::class)->execute($this->principal->requestId,
            $this->principal, 'knowledge.documents.v1', $request->all())->toArray(), JSON_THROW_ON_ERROR);
    }
}
