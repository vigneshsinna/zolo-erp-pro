<?php

declare(strict_types=1);

namespace App\Ai\Registries;

use App\Ai\Adapters\CompanyBootstrapCommandAdapter;
use App\Ai\Adapters\CompanyBootstrapPreviewAdapter;
use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\Schemas\CreateCompanyInputSchema;
use InvalidArgumentException;

/**
 * Dedicated capability registry for Mode A (Pre-Company Bootstrap).
 * Completely separate from tenant-scoped ActionCapabilityRegistry.
 */
final class SetupCapabilityRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        $this->register(new ActionDefinition(
            key: 'setup.company.create.v1',
            businessModule: 'company_bootstrap',
            riskTier: 'r4_restricted',
            requiredPermissions: ['ai.use'],
            inputSchemaClass: CreateCompanyInputSchema::class,
            previewAdapterClass: CompanyBootstrapPreviewAdapter::class,
            commandAdapterClass: CompanyBootstrapCommandAdapter::class,
            domainOwnsTransaction: true,
            requiresApproval: true,
            requiresFreshSnapshot: false,
            enabledByDefault: false,
            mode: 'bootstrap',
        ));
    }

    public function register(ActionDefinition $definition): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.]*\.v[1-9][0-9]*$/D', $definition->key)
            || $definition->mode !== 'bootstrap'
            || isset($this->definitions[$definition->key])
            || !in_array($definition->riskTier, ['r3_financial', 'r4_restricted'], true)
            || !$definition->requiresApproval
            || !is_a($definition->previewAdapterClass, ActionPreviewAdapterInterface::class, true)
            || !is_a($definition->commandAdapterClass, ActionCommandAdapterInterface::class, true)
        ) {
            throw new InvalidArgumentException('Unsafe or invalid bootstrap setup capability definition.');
        }

        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): ActionDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException('Bootstrap capability is not registered.');
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

