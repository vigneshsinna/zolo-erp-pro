<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * Immutable server-constructed representation of an authenticated human actor
 * operating within a strict multi-tenant company, branch, and financial year boundary.
 */
final readonly class AiPrincipal
{
    /**
     * @param int $actorId Authenticated user ID (App\Models\User)
     * @param int $companyId Authoritative company tenant ID (App\Models\Company)
     * @param int $branchId Authoritative branch ID (App\Models\Branch)
     * @param int $financialYearId Active fiscal year ID (App\Models\Accounting\FiscalYear)
     * @param array<string> $permittedToolNames Allowlist of tool keys authorized for this principal
     * @param string $requestId Unique correlation UUID for the request
     * @param string|null $effectiveRole The resolved role within the company (e.g. 'admin', 'accountant')
     * @param int|null $tenantBoundaryId Optional explicit tenant isolation boundary ID
     * @param bool $isActive Whether the actor's user account and company membership are active
     */
    public function __construct(
        public int $actorId,
        public int $companyId,
        public int $branchId,
        public int $financialYearId,
        public array $permittedToolNames = [],
        public string $requestId = '',
        public ?string $effectiveRole = null,
        public ?int $tenantBoundaryId = null,
        public bool $isActive = true,
    ) {}

    /**
     * Check if a specific tool key is authorized on this principal.
     */
    public function hasTool(string $toolKey): bool
    {
        return in_array($toolKey, $this->permittedToolNames, true);
    }
}

