<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PayablesSummary implements Tool
{
    public function __construct(
        protected AiPrincipal $principal,
        protected ?ErpToolExecutor $executor = null
    ) {}

    public function name(): string
    {
        return 'payables.summary.v1';
    }

    public function description(): string
    {
        return 'Retrieve authoritative Accounts Payable (AP) summary, supplier outstanding balances, and reconciliation status.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()->description('Target calculation date in YYYY-MM-DD format'),
            'limit' => $schema->integer()->description('Top suppliers count (1..20)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $asOf = isset($request['as_of']) && is_string($request['as_of']) ? trim($request['as_of']) : null;
        $limit = isset($request['limit']) && is_numeric($request['limit']) ? (int) $request['limit'] : 5;

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'payables.summary.v1',
            rawInput: array_filter([
                'as_of' => $asOf,
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
