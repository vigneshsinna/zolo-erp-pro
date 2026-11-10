<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FinancialStatements implements Tool
{
    public function __construct(
        protected AiPrincipal $principal,
        protected ?ErpToolExecutor $executor = null
    ) {}

    public function name(): string
    {
        return 'accounting_statements';
    }

    public function description(): string
    {
        return 'Retrieve authoritative Profit and Loss, Balance Sheet, or Trial Balance financial statements.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'statement_type' => $schema->string()->description("Statement type: 'profit_and_loss', 'balance_sheet', 'trial_balance'"),
            'from' => $schema->string()->description('Start date (YYYY-MM-DD)'),
            'to' => $schema->string()->description('End date (YYYY-MM-DD)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $rawInput = $request->all();

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'accounting.statements.v1',
            rawInput: $rawInput
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
