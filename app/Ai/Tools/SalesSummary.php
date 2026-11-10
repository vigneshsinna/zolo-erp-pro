<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SalesSummary implements Tool
{
    public function __construct(
        protected AiPrincipal $principal,
        protected ?ErpToolExecutor $executor = null
    ) {}

    public function name(): string
    {
        return 'sales.summary.v1';
    }

    public function description(): string
    {
        return 'Retrieve sales totals, gross/net sales, tax, discounts, and top selling products for the active branch and financial year.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date (YYYY-MM-DD)'),
            'to' => $schema->string()->description('End date (YYYY-MM-DD)'),
            'warehouse_id' => $schema->integer()->description('Optional warehouse ID filter'),
            'limit' => $schema->integer()->description('Top products count (1..50)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $from = isset($request['from']) && is_string($request['from']) ? trim($request['from']) : null;
        $to = isset($request['to']) && is_string($request['to']) ? trim($request['to']) : null;
        $warehouseId = isset($request['warehouse_id']) && is_numeric($request['warehouse_id']) ? (int) $request['warehouse_id'] : null;
        $limit = isset($request['limit']) && is_numeric($request['limit']) ? (int) $request['limit'] : 10;

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'sales.summary.v1',
            rawInput: array_filter([
                'from' => $from,
                'to' => $to,
                'warehouse_id' => $warehouseId,
                'limit' => $limit,
            ], fn ($v) => $v !== null)
        );

        return (string) json_encode([
            'status' => $result->status,
            'tool' => $result->toolKey,
            'scope' => $result->scope,
            'as_of' => $result->asOf,
            'data' => $result->data,
            'source_refs' => $result->sourceRefs,
            'warnings' => $result->warnings,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'message' => $result->message,
        ], JSON_THROW_ON_ERROR);
    }
}
