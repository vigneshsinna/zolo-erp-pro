<?php

declare(strict_types=1);

namespace App\Ai\Registries;

use App\Ai\Adapters\ExtendedActionAdapter;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\Requirements\ExtendedActionRequirementsProvider;
use App\Ai\Schemas\ExtendedAccountInputSchema;
use App\Ai\Schemas\ExtendedInventoryInputSchema;
use App\Ai\Schemas\ExtendedJobWorkInputSchema;
use App\Ai\Schemas\ExtendedJournalInputSchema;
use App\Ai\Schemas\ExtendedOpeningInputSchema;
use App\Ai\Schemas\ExtendedPriceListInputSchema;
use App\Ai\Schemas\ExtendedProductionInputSchema;
use App\Ai\Schemas\ExtendedProductionCompletionInputSchema;

/** Reviewed capabilities backed by the existing inventory, accounting and operations owners. */
final class ExtendedActionCatalog
{
    /** @return list<ActionDefinition> */
    public static function definitions(): array
    {
        $rows = [
            ['stock_transfer.create.v1', 'inventory', 'r2_operational', 'transfers-add', ExtendedInventoryInputSchema::class],
            ['stock_adjust.create.v1', 'inventory', 'r3_financial', 'adjustment', ExtendedInventoryInputSchema::class],
            ['stock_adjustment.create.v1', 'inventory', 'r3_financial', 'adjustment', ExtendedInventoryInputSchema::class],
            ['journal_entry.create.v1', 'accounting', 'r3_financial', 'accounting.voucher.post', ExtendedJournalInputSchema::class],
            ['production_order.create.v1', 'manufacturing', 'r2_operational', 'manufacturing.production.post', ExtendedProductionInputSchema::class],
            ['production_order.complete.v1', 'manufacturing', 'r3_financial', 'manufacturing.production.post', ExtendedProductionCompletionInputSchema::class],
            ['job_work.outward.v1', 'job_work', 'r3_financial', 'job_work.dispatch', ExtendedJobWorkInputSchema::class],
            ['job_work.inward.v1', 'job_work', 'r3_financial', 'job_work.receive', ExtendedJobWorkInputSchema::class],
            ['setup.account.create.v1', 'account_setup', 'r3_financial', 'accounting.voucher.post', ExtendedAccountInputSchema::class],
            ['setup.pricelist.update.v1', 'price_list', 'r2_operational', 'products-edit', ExtendedPriceListInputSchema::class],
            ['setup.opening_stock.create.v1', 'opening_stock', 'r3_financial', 'accounting.voucher.post', ExtendedOpeningInputSchema::class],
            ['setup.opening_balance.create.v1', 'opening_balance', 'r3_financial', 'accounting.voucher.post', ExtendedOpeningInputSchema::class],
        ];

        return array_map(static fn (array $row): ActionDefinition => new ActionDefinition(
            key: $row[0], businessModule: $row[1], riskTier: $row[2],
            requiredPermissions: ['ai.use', $row[3]], inputSchemaClass: $row[4],
            previewAdapterClass: ExtendedActionAdapter::class, commandAdapterClass: ExtendedActionAdapter::class,
            enabledByDefault: false,
        ), $rows);
    }

    public static function register(ActionCapabilityRegistry $registry): void
    {
        foreach (self::definitions() as $definition) {
            if (!$registry->has($definition->key)) {
                $registry->register($definition);
            }
        }
    }

    /** @return list<ExtendedActionRequirementsProvider> */
    public static function requirements(): array
    {
        return array_map(static fn (ActionDefinition $definition) => new ExtendedActionRequirementsProvider($definition), self::definitions());
    }
}
