<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ErpToolExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DraftProposal implements Tool
{
    public function __construct(
        protected AiPrincipal $principal
    ) {}

    public function name(): string
    {
        return 'draft_proposal';
    }

    public function description(): string
    {
        return 'Propose a reviewable draft action (draft quotation, payment reminder) for human review and approval before execution.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action_type' => $schema->string()->description('The type of action to propose: draft_quotation, draft_payment_reminder, draft_sale_order.')->required(),
            'action_title' => $schema->string()->description('Short human-readable title describing the proposed action.')->required(),
            'payload' => $schema->array()->description('Structured payload containing customer_id, items, amounts, or reminder notes.')->required(),
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
            toolKey: 'proposals.draft.v1',
            rawInput: $input
        );

        return (string) json_encode($result->toArray(), JSON_THROW_ON_ERROR);
    }
}
