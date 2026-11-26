<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** Shared Mode B checks. No pre-company exception is permitted here. */
final class ActionSecurity
{
    public function __construct(
        private readonly CompanyContextResolver $resolver,
        private readonly CommercialPermission $permissions,
        private readonly ActionCapabilityRegistry $registry,
        private readonly AiFeatureGate $featureGate,
    ) {}

    public function definition(string $key, AiPrincipal $principal): ActionDefinition
    {
        $this->assertEnabled($principal);
        $definition = $this->registry->get($key);
        if ($definition->mode !== 'company' || $definition->enabledByDefault) {
            throw new AuthorizationException('Action policy is unavailable.');
        }

        $this->context($principal);
        foreach (array_unique($definition->requiredPermissions) as $permission) {
            $this->permissions->assert($permission, $this->context($principal), $principal->actorId);
        }

        return $definition;
    }

    public function assertEnabled(AiPrincipal $principal): void
    {
        if (!$principal->isActive
            || !config('ai_actions.foundation_enabled', false)
            || !$this->featureGate->isEnabledForCompany($principal->companyId)) {
            throw new AuthorizationException('AI Action Foundation is disabled or access is denied.');
        }
    }

    public function context(AiPrincipal $principal): CompanyContext
    {
        return $this->resolver->forActor(
            new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId),
            $principal->actorId,
        );
    }

    public function validate(ActionDefinition $definition, array $input): array
    {
        if (!is_a($definition->inputSchemaClass, \App\Ai\Contracts\ActionInputSchemaInterface::class, true)) {
            throw new InvalidArgumentException('Untrusted AI action schema.');
        }

        $rules = app($definition->inputSchemaClass)->rules();
        if ($rules === [] || array_diff(array_keys($input), array_keys($rules)) !== []
            || array_intersect(array_keys($input), ['actor_id', 'user_id', 'company_id', 'branch_id', 'financial_year_id']) !== []) {
            throw new InvalidArgumentException('Action contains unknown or prohibited fields.');
        }

        return Validator::make($input, $rules)->validate();
    }
}
