<?php

declare(strict_types=1);

namespace App\Ai\Registries;

use App\Ai\Contracts\ActionRequirementsProviderInterface;
use App\Ai\Requirements\PurchaseOrderRequirementsProvider;
use App\Ai\Requirements\SalesOrderRequirementsProvider;
use InvalidArgumentException;

final class ActionRequirementsRegistry
{
    /** @var array<string, ActionRequirementsProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new PurchaseOrderRequirementsProvider());
        $this->register(new SalesOrderRequirementsProvider());
    }

    public function register(ActionRequirementsProviderInterface $provider): void
    {
        $this->providers[$provider->actionKey()] = $provider;
    }

    public function get(string $actionKey): ActionRequirementsProviderInterface
    {
        if (!isset($this->providers[$actionKey])) {
            throw new InvalidArgumentException("No requirements provider registered for action key: '{$actionKey}'.");
        }

        return $this->providers[$actionKey];
    }

    public function has(string $actionKey): bool
    {
        return isset($this->providers[$actionKey]);
    }

    /**
     * @return array<string, ActionRequirementsProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
