<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Models\User;
use App\Services\Platform\CompanyContextResolver;

/**
 * Authorizes tool executions against the principal's explicit allowlist
 * and verifies active tenant/membership status.
 */
class AiPrincipalAuthorizer implements AiPrincipalAuthorizerInterface
{
    public function __construct(
        protected ?CompanyContextResolver $contextResolver = null,
        protected ?AiPrincipalFactory $principalFactory = null
    ) {}

    /**
     * Authorize whether the principal can execute the given tool key.
     */
    public function authorize(AiPrincipal $principal, string $toolKey): bool
    {
        if (!$principal->isActive) {
            return false;
        }

        return $principal->hasTool($toolKey);
    }

    /**
     * Refresh principal from persistence/database.
     */
    public function refresh(AiPrincipal $principal): AiPrincipal
    {
        if (!$principal->isActive) {
            return $principal;
        }

        $resolver = $this->contextResolver ?? (function_exists('app') && app()->bound(CompanyContextResolver::class) ? app(CompanyContextResolver::class) : null);
        $factory = $this->principalFactory ?? (function_exists('app') && app()->bound(AiPrincipalFactory::class) ? app(AiPrincipalFactory::class) : null);

        if (!$resolver || !$factory) {
            return $principal;
        }

        try {
            $user = User::find($principal->actorId);
            if (!$user || !($user->is_active ?? true) || ($user->is_deleted ?? false)) {
                return new AiPrincipal(
                    actorId: $principal->actorId,
                    companyId: $principal->companyId,
                    branchId: $principal->branchId,
                    financialYearId: $principal->financialYearId,
                    permittedToolNames: [],
                    requestId: $principal->requestId,
                    effectiveRole: null,
                    isActive: false
                );
            }

            $context = $resolver->resolve(
                userId: $principal->actorId,
                companyId: $principal->companyId,
                branchId: $principal->branchId,
                financialYearId: $principal->financialYearId
            );

            $permittedTools = $factory->resolvePermittedTools($user, $context->companyId);

            return new AiPrincipal(
                actorId: $principal->actorId,
                companyId: $context->companyId,
                branchId: $context->branchId,
                financialYearId: $context->financialYearId,
                permittedToolNames: $permittedTools,
                requestId: $principal->requestId,
                effectiveRole: $user->role_id ? (string) $user->role_id : null,
                isActive: true
            );
        } catch (\Throwable) {
            // Fail closed on any tenancy revocation, permission revocation, or database mismatch
            return new AiPrincipal(
                actorId: $principal->actorId,
                companyId: $principal->companyId,
                branchId: $principal->branchId,
                financialYearId: $principal->financialYearId,
                permittedToolNames: [],
                requestId: $principal->requestId,
                effectiveRole: null,
                isActive: false
            );
        }
    }
}

