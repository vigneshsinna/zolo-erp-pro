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
        if (
            !preg_match('/^[a-z][a-z0-9_.]*\.v[1-9][0-9]*$/D', $definition->key)
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

    public function registerSetupCapabilities(): void
    {
        if (!$this->has('setup.branch.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'setup.branch.create.v1',
                businessModule: 'branch_setup',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use'],
                inputSchemaClass: \App\Ai\Schemas\CreateBranchInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\BranchSetupPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\BranchSetupCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }

        if (!$this->has('setup.warehouse.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'setup.warehouse.create.v1',
                businessModule: 'warehouse_setup',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use'],
                inputSchemaClass: \App\Ai\Schemas\CreateWarehouseInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\WarehouseSetupPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\WarehouseSetupCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }

        if (!$this->has('setup.unit.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'setup.unit.create.v1',
                businessModule: 'unit_setup',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use', 'unit'],
                inputSchemaClass: \App\Ai\Schemas\CreateUnitInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\UnitSetupPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\UnitSetupCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                requiresFreshSnapshot: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }

        if (!$this->has('setup.product.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'setup.product.create.v1',
                businessModule: 'product_setup',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use', 'products-add'],
                inputSchemaClass: \App\Ai\Schemas\CreateProductInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\ProductSetupPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\ProductSetupCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                requiresFreshSnapshot: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }

        if (!$this->has('setup.supplier.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'setup.supplier.create.v1',
                businessModule: 'supplier_setup',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use', 'suppliers-add'],
                inputSchemaClass: \App\Ai\Schemas\CreateSupplierInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\SupplierSetupPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\SupplierSetupCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                requiresFreshSnapshot: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }

        if (!$this->has('setup.customer.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'setup.customer.create.v1',
                businessModule: 'customer_setup',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use', 'customers-add'],
                inputSchemaClass: \App\Ai\Schemas\CreateCustomerInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\CustomerSetupPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\CustomerSetupCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                requiresFreshSnapshot: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }
    }

    public function registerCommercialCapabilities(): void
    {
        if (!$this->has('purchase_order.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'purchase_order.create.v1',
                businessModule: 'commercial',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use', 'purchases-add'],
                inputSchemaClass: \App\Ai\Schemas\CreatePurchaseOrderInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\PurchaseOrderPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\PurchaseOrderCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                requiresFreshSnapshot: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }

        if (!$this->has('sales_order.create.v1')) {
            $this->register(new ActionDefinition(
                key: 'sales_order.create.v1',
                businessModule: 'commercial',
                riskTier: 'r2_operational',
                requiredPermissions: ['ai.use', 'sales-add'],
                inputSchemaClass: \App\Ai\Schemas\CreateSalesOrderInputSchema::class,
                previewAdapterClass: \App\Ai\Adapters\SalesOrderPreviewAdapter::class,
                commandAdapterClass: \App\Ai\Adapters\SalesOrderCommandAdapter::class,
                domainOwnsTransaction: true,
                requiresApproval: true,
                requiresFreshSnapshot: true,
                enabledByDefault: false,
                mode: 'company',
            ));
        }
    }
}
