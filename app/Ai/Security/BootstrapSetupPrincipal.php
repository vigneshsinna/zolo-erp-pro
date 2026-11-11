<?php

declare(strict_types=1);

namespace App\Ai\Security;

use Carbon\CarbonImmutable;

/**
 * Strictly limited installation administrator identity for Mode A (pre-company bootstrap).
 * NEVER a general-purpose superuser. Holds only pre-company bootstrap capabilities.
 */
final readonly class BootstrapSetupPrincipal
{
    /**
     * @param int $actorId Authenticated user ID (App\Models\User)
     * @param int $roleId User role ID (1 = Admin, 2 = Owner)
     * @param array<string> $allowedCapabilities Explicit list of authorized bootstrap capability keys
     * @param string $taskToken 64-char hex task token tying proposal sessions to this actor
     * @param CarbonImmutable $expiresAt Session token expiration time
     * @param bool $isActive Whether user account is active
     */
    public function __construct(
        public int $actorId,
        public int $roleId,
        public array $allowedCapabilities,
        public string $taskToken,
        public CarbonImmutable $expiresAt,
        public bool $isActive = true,
    ) {}

    public function hasCapability(string $capabilityKey): bool
    {
        return in_array($capabilityKey, $this->allowedCapabilities, true);
    }
}

