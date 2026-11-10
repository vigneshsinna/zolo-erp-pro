<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Laravel AI SDK Tool adapter for Customer Outstanding.
 */
class CustomerOutstanding implements Tool
{
    public function __construct(
        protected readonly AiPrincipal $principal,
        protected readonly ?ErpToolExecutor $executor = null
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get authorised customer outstanding dues and aging for the current company, branch and financial year.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()->description('Optional date in YYYY-MM-DD format (within active financial year)'),
            'limit' => $schema->integer()->description('Number of top outstanding customers to return (1 to 20)'),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $rawAsOf = $request['as_of'] ?? null;
        $asOf = null;
        if (is_string($rawAsOf) && trim($rawAsOf) !== '') {
            $rawAsOf = trim($rawAsOf);
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawAsOf, $matches)) {
                $asOf = $matches[0];
            }
        }
        $limit = is_numeric($request['limit'] ?? null) ? max(1, min(20, (int) $request['limit'])) : 5;

        $executor = $this->executor ?? app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'receivables.outstanding.v1',
            rawInput: [
                'as_of' => $asOf,
                'limit' => $limit,
            ]
        );

        return (string) json_encode([
            'status' => $result->status,
            'tool' => $result->toolKey,
            'scope' => $result->scope,
            'as_of' => $result->asOf,
            'data' => $result->data,
            'reconciliation' => [
                'is_reconciled' => $result->reconciled,
            ],
            'warnings' => $result->warnings,
            'source_refs' => $result->sourceRefs,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ], JSON_THROW_ON_ERROR);
    }
}
