<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ManufacturingBoms implements Tool
{
    public function __construct(
        protected AiPrincipal $principal
    ) {}

    public function name(): string
    {
        return 'manufacturing_boms';
    }

    public function description(): string
    {
        return 'Look up manufacturing Bill of Materials (BOM), finished good output definitions, and raw material component recipes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('Optional finished good product ID to filter BOMs for.'),
            'code' => $schema->string()->description('Optional BOM code or keyword search.'),
            'limit' => $schema->integer()->description('Maximum number of BOM records to return (1 to 50, default 10).'),
        ];
    }

    public function handle(Request $request): string
    {
        $rawInput = $request->all();

        $executor = app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'manufacturing.boms.v1',
            rawInput: $rawInput
        );

        return (string) json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    }
}
