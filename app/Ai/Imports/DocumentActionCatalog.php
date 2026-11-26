<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\DTOs\ActionDefinition;

final class DocumentActionCatalog
{
    /** @return list<ActionDefinition> */
    public static function definitions(): array
    {
        return [new ActionDefinition(
            key: 'setup.products.import.v1', businessModule: 'product_import', riskTier: 'r2_operational',
            requiredPermissions: ['ai.use', 'products-import', 'products-add'],
            inputSchemaClass: ProductImportInputSchema::class,
            previewAdapterClass: ProductImportPreviewAdapter::class,
            commandAdapterClass: ProductImportCommandAdapter::class,
        )];
    }
}
