<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ActionProposalService;

/**
 * Authoritative handler for 'proposals.draft.v1' (P3 Action Proposal Engine).
 * Prepares reviewable drafts (quotations, payment reminders, stock transfers) without direct ERP ledger mutation.
 */
class DraftProposalHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ActionProposalService $proposalService
    ) {}

    public function key(): string
    {
        return 'proposals.draft.v1';
    }

    public function description(): string
    {
        return 'Propose a reviewable draft action (e.g. draft quotation, customer payment reminder, stock transfer request) for human review and approval.';
    }

    public function risk(): string
    {
        return 'r1_draft';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action_type' => [
                    'type' => 'string',
                    'enum' => ['draft_quotation', 'draft_payment_reminder', 'draft_sale_order'],
                    'description' => 'The type of action to propose: draft_quotation, draft_payment_reminder, draft_sale_order.',
                ],
                'action_title' => [
                    'type' => 'string',
                    'description' => 'Short human-readable title describing the proposed action.',
                ],
                'payload' => [
                    'type' => 'object',
                    'description' => 'Structured payload containing customer_id, items, amounts, or reminder notes.',
                ],
            ],
            'required' => ['action_type', 'action_title', 'payload'],
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
        $actionType = $validatedInput['action_type'];
        $actionTitle = $validatedInput['action_title'];
        $payload = $validatedInput['payload'];

        $proposal = $this->proposalService->propose(
            principal: $principal,
            actionType: $actionType,
            actionTitle: $actionTitle,
            payload: $payload
        );

        return ErpToolResult::ok(
            toolKey: 'proposals.draft.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: $proposal,
            sourceRefs: ['/ai/proposals/' . $proposal['proposal_id']],
            warnings: ['Action requires explicit human review and confirmation before ERP execution.'],
            asOf: now()->toDateString(),
            reconciled: true,
            rowCount: 1
        );
    }
}

