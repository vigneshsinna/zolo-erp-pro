<?php

declare(strict_types=1);

namespace App\Policies;

use App\Ai\DTOs\AiPrincipal;
use App\Models\AiMediaAsset;
use App\Models\User;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;

/**
 * Strict company + actor tenancy policy for uploaded AI media assets.
 */
class AiMediaAssetPolicy
{
    public function __construct(
        protected CompanyContextResolver $contextResolver,
        protected CommercialPermission $permissions,
    ) {}

    public function create(User $user): bool
    {
        $context = $this->contextResolver->resolve(userId: $user->id);

        return $this->permissions->allows('ai.use', $context, $user->id);
    }

    public function view(User $user, AiMediaAsset $asset): bool
    {
        $context = $this->contextResolver->resolve(userId: $user->id);

        return (int) $asset->company_id === $context->companyId
            && (int) $asset->actor_id === (int) $user->id
            && $this->permissions->allows('ai.use', $context, $user->id);
    }

    public function update(User $user, AiMediaAsset $asset): bool
    {
        return $this->view($user, $asset);
    }

    public function delete(User $user, AiMediaAsset $asset): bool
    {
        return $this->view($user, $asset);
    }

    /**
     * Authoritative principal-level boundary verification used by multimodal services.
     */
    public function canAccessPrincipal(AiPrincipal $principal, AiMediaAsset $asset): bool
    {
        return $principal->isActive
            && (int) $asset->company_id === $principal->companyId
            && (int) $asset->actor_id === $principal->actorId;
    }
}

