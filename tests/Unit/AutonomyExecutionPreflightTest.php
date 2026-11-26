<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\Services\AutonomyExecutionPreflight;
use PHPUnit\Framework\TestCase;

final class AutonomyExecutionPreflightTest extends TestCase
{
    public function test_requested_automatic_level_is_not_accepted_as_posting_authorization(): void
    {
        $decision = AutonomyExecutionPreflight::fromEvaluatedPolicy('purchase_order.create.v1', [
            'requested_level' => 'auto_under_limits',
            'policy_version' => 4,
        ]);
        $this->assertSame('auto_under_limits', $decision['requested_level']);
        $this->assertTrue($decision['requires_signed_human_approval']);
        $this->assertFalse($decision['can_auto_execute_erp_command']);
        $this->assertTrue($decision['can_prepare_draft']);
    }
}
