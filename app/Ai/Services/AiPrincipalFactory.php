<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Models\User;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Str;

/**
 * Factory constructing authenticated, server-resolved AiPrincipal instances.
 */
class AiPrincipalFactory
{
    public function __construct(
        protected CompanyContextResolver $contextResolver
    ) {}

    /**
     * Resolve an AiPrincipal for an authenticated user within explicit or session tenancy.
     */
    public function createForUser(
        int $userId,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $financialYearId = null,
        ?string $requestId = null
    ): AiPrincipal {
        $context = $this->contextResolver->resolve(
            userId: $userId,
            companyId: $companyId,
            branchId: $branchId,
            financialYearId: $financialYearId
        );

        $user = User::findOrFail($userId);

        // Discover permitted tools for actor
        $permittedTools = $this->resolvePermittedTools($user, $context->companyId);

        return new AiPrincipal(
            actorId: $userId,
            companyId: $context->companyId,
            branchId: $context->branchId,
            financialYearId: $context->financialYearId,
            permittedToolNames: $permittedTools,
            requestId: $requestId ?? (string) Str::uuid(),
            effectiveRole: $user->role_id ? (string) $user->role_id : null,
            isActive: (bool) ($user->is_active ?? true)
        );
    }

    /**
     * Determine which AI tools this user is permitted to invoke.
     *
     * @return array<string>
     */
    public function resolvePermittedTools(User $user, int $companyId): array
    {
        $tools = [];

        $hasReceivablesAccess = false;

        // Admin / SuperAdmin role bypass
        if ((int) $user->role_id === 1) {
            $hasReceivablesAccess = true;
        } else {
            try {
                if ($user->can('accounting.reports.view') || $user->can('customer-report') || $user->can('due-report')) {
                    $hasReceivablesAccess = true;
                } elseif ($user->role_id) {
                    $role = \Spatie\Permission\Models\Role::find($user->role_id);
                    if ($role && ($role->hasPermissionTo('due-report') || $role->hasPermissionTo('customer-report') || $role->hasPermissionTo('accounting.reports.view'))) {
                        $hasReceivablesAccess = true;
                    }
                }
            } catch (\Throwable) {
                // Fail closed on authorization lookup exceptions
                $hasReceivablesAccess = false;
            }
        }

        if ($hasReceivablesAccess) {
            $tools[] = 'receivables.outstanding.v1';
        }

        return $tools;
    }
}

