<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Models\User;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Str;

/**
 * Factory constructing authenticated, server-resolved AiPrincipal instances.
 */
class AiPrincipalFactory
{
    public function __construct(
        protected CompanyContextResolver $contextResolver,
        protected AiToolManifest $manifest,
        protected CommercialPermission $permissions,
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
        $permittedTools = $this->resolvePermittedTools($user, $context);

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
    public function resolvePermittedTools(User $user, CompanyContext $context): array
    {
        $handler = $this->manifest->get('receivables.outstanding.v1');
        $required = $handler->requiredPermissions();
        $reportPermissions = ['accounting.reports.view', 'customer-report', 'due-report'];
        $allowed = $this->permissions->allowed([...$required, ...$reportPermissions], $context, $user->id);

        return array_diff($required, $allowed) === [] && array_intersect($reportPermissions, $allowed) !== []
            ? [$handler->key()]
            : [];
    }
}

