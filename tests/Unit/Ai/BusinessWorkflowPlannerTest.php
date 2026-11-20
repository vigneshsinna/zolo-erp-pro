<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Knowledge\BusinessWorkflowPlanner;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

final class BusinessWorkflowPlannerTest extends TestCase
{
    public function test_feature_off_prevents_plan_even_without_database_access(): void
    {
        config(['ai_business_knowledge.enabled' => false, 'ai_business_knowledge.planner_enabled' => false]);
        $principal = new AiPrincipal(actorId: 1, companyId: 1, branchId: 1, financialYearId: 1);
        $this->expectException(AuthorizationException::class);
        app(BusinessWorkflowPlanner::class)->preview($principal, 'process.purchase_to_pay.v1');
    }
}
