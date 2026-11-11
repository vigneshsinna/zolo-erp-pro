<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\ActionInputSchemaInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\Registries\SetupCapabilityRegistry;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\User;
use App\Services\Platform\CompanyDirectoryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Security and validation gateway for Mode A (Pre-Company Bootstrap).
 * Enforces strict installation-admin authorization and immutable input validation.
 */
final class BootstrapSetupSecurity
{
    public function __construct(
        private readonly CompanyDirectoryService $directory,
        private readonly SetupCapabilityRegistry $registry,
    ) {}

    public function resolvePrincipal(int $actorId, ?string $taskToken = null): BootstrapSetupPrincipal
    {
        $this->assertFoundationEnabled();

        $user = User::find($actorId);
        if (!$user || !$this->directory->isInstallationAdmin($actorId)) {
            throw new AuthorizationException('Installation administrator required for bootstrap setup.');
        }

        $token = $taskToken && preg_match('/^[a-f0-9]{64}$/D', $taskToken) === 1
            ? $taskToken
            : hash('sha256', Str::uuid()->toString() . '|' . $actorId . '|' . microtime(true));

        $ttlMinutes = (int) config('ai_actions.proposal_ttl_minutes', 30);

        return new BootstrapSetupPrincipal(
            actorId: $actorId,
            roleId: (int) $user->role_id,
            allowedCapabilities: ['setup.company.create.v1', 'setup.group.create.v1'],
            taskToken: $token,
            expiresAt: CarbonImmutable::now()->addMinutes($ttlMinutes),
            isActive: (bool) $user->is_active,
        );
    }

    public function definition(string $key, BootstrapSetupPrincipal $principal): ActionDefinition
    {
        $this->assertFoundationEnabled();

        if (!$principal->hasCapability($key)) {
            throw new AuthorizationException('Capability not authorized for bootstrap actor.');
        }

        $definition = $this->registry->get($key);
        if ($definition->mode !== 'bootstrap') {
            throw new AuthorizationException('Non-bootstrap capability requested in Mode A.');
        }

        return $definition;
    }

    public function assertFoundationEnabled(): void
    {
        if (!config('ai_actions.foundation_enabled', false)) {
            throw new AuthorizationException('AI Action Foundation is disabled.');
        }
    }

    public function assertExecutionEnabled(): void
    {
        $this->assertFoundationEnabled();

        if (!config('ai_actions.execution_enabled', false)) {
            throw new AuthorizationException('AI action execution is disabled.');
        }
    }

    public function validate(ActionDefinition $definition, array $input): array
    {
        if (!is_a($definition->inputSchemaClass, ActionInputSchemaInterface::class, true)) {
            throw new InvalidArgumentException('Untrusted bootstrap action schema.');
        }

        $rules = app($definition->inputSchemaClass)->rules();
        if ($rules === [] || array_intersect(array_keys($input), ['actor_id', 'user_id', 'company_id', 'branch_id', 'financial_year_id']) !== []) {
            throw new InvalidArgumentException('Action contains prohibited scope fields.');
        }

        return Validator::make($input, $rules)->validate();
    }
}

