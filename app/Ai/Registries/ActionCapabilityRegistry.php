<?php

declare(strict_types=1);

namespace App\Ai\Registries;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use InvalidArgumentException;

/**
 * Explicit allowlist of company-scoped action definitions.
 * PH1 intentionally registers ZERO commands. Never auto-discover PHP methods.
 */
final class ActionCapabilityRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $definitions = [];

    public function register(ActionDefinition $definition): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.]*\.v[1-9][0-9]*$/D', $definition->key)
            || $definition->mode !== 'company'
            || isset($this->definitions[$definition->key])
            || !in_array('ai.use', $definition->requiredPermissions, true)
            || !in_array($definition->riskTier, ['r1_draft', 'r2_operational', 'r3_financial', 'r4_restricted'], true)
            || !$definition->requiresApproval
            || $definition->enabledByDefault
            || !is_a($definition->previewAdapterClass, ActionPreviewAdapterInterface::class, true)
            || !is_a($definition->commandAdapterClass, ActionCommandAdapterInterface::class, true)
        ) {
            throw new InvalidArgumentException('Unsafe or duplicate AI action capability definition.');
        }

        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): ActionDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException('AI action capability is not registered.');
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /** @return array<string, ActionDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
