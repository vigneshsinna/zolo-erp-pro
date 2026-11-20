<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\Services\AutonomyExecutionPreflight;
use App\Ai\Services\AutonomyPolicyService;
use App\Services\Platform\CompanyContext;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AutonomyExecutionPreflightTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_requested_automatic_level_is_not_accepted_as_posting_authorization(): void
    {
        $context = new CompanyContext(3, 4, 5);
        $policy = Mockery::mock(AutonomyPolicyService::class);
        $policy->shouldReceive('evaluate')->once()->with($context, 'purchase_order.create.v1')
            ->andReturn(['requested_level' => 'auto_under_limits', 'policy_version' => 4]);
        $decision = (new AutonomyExecutionPreflight($policy))->inspect($context, 'purchase_order.create.v1');
        $this->assertSame('auto_under_limits', $decision['requested_level']);
        $this->assertTrue($decision['requires_signed_human_approval']);
        $this->assertFalse($decision['can_auto_execute_erp_command']);
    }
}
