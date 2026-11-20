<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Services\Platform\CompanyContext;

/**
 * Deterministic decision boundary. A stored preference is never an ERP approval.
 * New non-financial autonomous actions need their own separate audited authorizer.
 */
final class AutonomyExecutionPreflight
{
    public function __construct(private readonly AutonomyPolicyService $policies) {}

    public function inspect(CompanyContext $context, string $actionKey): array
    {
        $policy = $this->policies->evaluate($context, $actionKey);
        return [
            'action_key' => $actionKey,
            'requested_level' => $policy['requested_level'],
            'policy_version' => $policy['policy_version'],
            'can_prepare_draft' => true,
            'can_auto_execute_erp_command' => false,
            'requires_signed_human_approval' => true,
            'reason' => 'A company preference cannot replace the signed approval and authoritative ERP posting checks.',
        ];
    }
}
