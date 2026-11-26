<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ApprovedActionExecutor;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionProposalService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

final class ActionFoundationDisabledTest extends TestCase
{
    private function principal(): AiPrincipal
    {
        return new AiPrincipal(actorId: 1, companyId: 1, branchId: 1, financialYearId: 1, isActive: true);
    }

    public function test_action_execution_is_disabled_without_db_access(): void
    {
        config()->set('ai_actions.execution_enabled', false);
        $this->expectException(AuthorizationException::class);
        app(ApprovedActionExecutor::class)->execute($this->principal(), '00000000-0000-0000-0000-000000000000', 1);
    }

    public function test_proposal_creation_is_disabled_without_db_access(): void
    {
        config()->set('ai_actions.foundation_enabled', false);
        $this->expectException(AuthorizationException::class);
        app(ActionProposalService::class)->create($this->principal(), 'purchase_order.create.v1', []);
    }

    public function test_approval_is_disabled_without_db_access(): void
    {
        config()->set('ai_actions.foundation_enabled', false);
        $this->expectException(AuthorizationException::class);
        app(ActionApprovalService::class)->approve($this->principal(), '00000000-0000-0000-0000-000000000000', 1);
    }
}
