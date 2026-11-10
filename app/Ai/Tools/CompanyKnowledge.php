<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CompanyKnowledge implements Tool
{
    public function __construct(
        protected AiPrincipal $principal
    ) {}

    public function name(): string
    {
        return 'company_knowledge';
    }

    public function description(): string
    {
        return 'Look up verified company terms, credit policies, return rules, and statutory compliance settings.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic' => $schema->string()->description('Topic to look up: company_profile, payment_terms, credit_policy, tax_policy, return_policy, all (default all).'),
            'query' => $schema->string()->description('Optional specific query term.'),
        ];
    }

    public function handle(Request $request): string
    {
        $input = $request->all();
        /** @var ErpToolExecutor $executor */
        $executor = app(ErpToolExecutor::class);

        $result = $executor->execute(
            runId: $this->principal->requestId,
            principal: $this->principal,
            toolKey: 'knowledge.query.v1',
            rawInput: $input
        );

        return (string) json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    }
}
