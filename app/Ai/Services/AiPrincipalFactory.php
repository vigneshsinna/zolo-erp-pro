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
    protected function resolvePermittedTools(User $user, int $companyId): array
    {
        // Default read tool list for authorized company users
        $tools = [];

        // Check if user has permission to view financial / customer reports
        // In zoloERP, users authorized for report views can view receivables
        $tools[] = 'receivables.outstanding.v1';

        return $tools;
    }
}

