<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiPrincipal;

/**
 * Contract for authorizer services that guard tool invocations.
 */
interface AiPrincipalAuthorizerInterface
{
    /**
     * Authorize whether the principal is permitted to execute the named tool.
     */
    public function authorize(AiPrincipal $principal, string $toolKey, array $requiredPermissions = []): bool;

    /**
     * Re-verify and re-resolve principal against the live database state.
     */
    public function refresh(AiPrincipal $principal): AiPrincipal;
}

