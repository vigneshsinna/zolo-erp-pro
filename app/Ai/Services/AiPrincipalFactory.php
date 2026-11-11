<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Models\User;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CapabilityService;
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
        protected CapabilityService $capabilities,
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
            isActive: (bool) $user->is_active && !$user->is_deleted
        );
    }

    /**
     * Determine which AI tools this user is permitted to invoke.
     *
     * @return array<string>
     */
    public function resolvePermittedTools(User $user, CompanyContext $context): array
    {
        $registrations = $this->manifest->all();
        $permissions = ['ai.use'];
        foreach ($registrations as $registration) {
            $permissions = array_merge($permissions, $registration->handler->requiredPermissions(), $registration->requiredAny);
        }

        $allowed = $this->permissions->allowed(array_unique($permissions), $context, $user->id);
        if (!in_array('ai.use', $allowed, true)) {
            return [];
        }

        $domains = $auxiliary = [];
        foreach ($registrations as $key => $registration) {
            if (array_diff($registration->handler->requiredPermissions(), $allowed) !== []) {
                continue;
            }

            if ($registration->requiredAny === []) {
                $auxiliary[] = $key;
                continue;
            }

            if (array_intersect($registration->requiredAny, $allowed) === []) {
                continue;
            }

            $capability = $registration->operationsCapability;
            if ($capability !== null && (!config('operations.enabled') || !$this->capabilities->enabled($capability, $context))) {
                continue;
            }

            $domains[] = $key;
        }

        if (config('ai_actions.foundation_enabled', false)) {
            $actionPerms = $this->permissions->allowed([
                'purchases-add',
                'products-add',
                'unit',
                'suppliers-add',
                'customers-add',
            ], $context, $user->id);

            if (in_array('purchases-add', $actionPerms, true)) {
                $domains[] = 'purchase_order.create.v1';
            }
            if (in_array('products-add', $actionPerms, true)) {
                $domains[] = 'setup.product.create.v1';
            }
            if (in_array('unit', $actionPerms, true)) {
                $domains[] = 'setup.unit.create.v1';
            }
            if (in_array('suppliers-add', $actionPerms, true)) {
                $domains[] = 'setup.supplier.create.v1';
            }
            if (in_array('customers-add', $actionPerms, true)) {
                $domains[] = 'setup.customer.create.v1';
            }
        }

        return $domains === [] ? [] : [...$domains, ...$auxiliary];
    }
}
