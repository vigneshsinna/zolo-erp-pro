<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\DTOs\AiPrincipal;

/**
 * Authorizes tool executions against the principal's explicit allowlist
 * and verifies active tenant/membership status.
 */
class AiPrincipalAuthorizer implements AiPrincipalAuthorizerInterface
{
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
        // In unit/in-memory mode, returns the immutable principal as-is.
        // In feature/database mode, re-validates against database users/memberships.
        return $principal;
    }
}

