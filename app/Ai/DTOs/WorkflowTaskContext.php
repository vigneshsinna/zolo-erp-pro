<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Platform\CompanyContext;

final readonly class WorkflowTaskContext
{
    public function __construct(
        public AiPrincipal|BootstrapSetupPrincipal $principal,
        public ?CompanyContext $context = null,
        public string $mode = 'company', // company, bootstrap
        public ?string $taskId = null,
        public ?string $threadId = null,
        public array $metadata = [],
    ) {
    }

    public function actorId(): int
    {
        return $this->principal->actorId;
    }

    public function companyId(): ?int
    {
        return $this->context?->companyId;
    }

    public function branchId(): ?int
    {
        return $this->context?->branchId;
    }

    public function financialYearId(): ?int
    {
        return $this->context?->financialYearId;
    }
}
