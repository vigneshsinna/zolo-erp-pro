<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\ActionFieldRequirement;
use App\Ai\DTOs\WorkflowTaskContext;

interface ActionRequirementsProviderInterface
{
    public function actionKey(): string;

    /**
     * @param array<string, mixed> $currentSlots Current draft inputs for dynamic line expansion
     * @return list<ActionFieldRequirement>
     */
    public function requirements(WorkflowTaskContext $context, array $currentSlots = []): array;

    /**
     * Deterministic, authoritative validation of complete inputs without ERP mutation.
     *
     * @param array<string, mixed> $resolvedInputs
     * @return array<string, string> Validation errors keyed by field path, empty if valid.
     */
    public function validate(WorkflowTaskContext $context, array $resolvedInputs): array;
}
