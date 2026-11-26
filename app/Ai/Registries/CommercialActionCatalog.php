<?php

declare(strict_types=1);

namespace App\Ai\Registries;

use App\Ai\Adapters\CommercialDocumentAdapter;
use App\Ai\Adapters\CustomerCollectionAdapter;
use App\Ai\Adapters\PaymentReminderSendAdapter;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\Schemas\CommercialDocumentInputSchema;
use App\Ai\Schemas\CustomerCollectionInputSchema;
use App\Ai\Schemas\SendPaymentReminderInputSchema;

final class CommercialActionCatalog
{
    public static function definitions(): array
    {
        $definitions = [];
        foreach ([
            'quotation.create.v1' => ['quotes-add', 'r2_operational'],
            'sales_invoice.create.v1' => ['sales-add', 'r3_financial'],
            'delivery_challan.create.v1' => ['sales-add', 'r2_operational'],
            'payment_receipt.create.v1' => ['accounting.voucher.post', 'r3_financial'],
            'payment_reminder.prepare.v1' => ['accounting.voucher.post', 'r2_operational'],
        ] as $key => [$permission, $risk]) {
            $collection = str_starts_with($key, 'payment_');
            $definitions[] = new ActionDefinition(
                key: $key,
                businessModule: $collection ? 'collections' : 'sales',
                riskTier: $risk,
                requiredPermissions: ['ai.use', $permission],
                inputSchemaClass: $collection ? CustomerCollectionInputSchema::class : CommercialDocumentInputSchema::class,
                previewAdapterClass: $collection ? CustomerCollectionAdapter::class : CommercialDocumentAdapter::class,
                commandAdapterClass: $collection ? CustomerCollectionAdapter::class : CommercialDocumentAdapter::class,
            );
        }
        $definitions[] = new ActionDefinition(
            key: 'payment_reminder.send.v1', businessModule: 'collections', riskTier: 'r3_financial',
            requiredPermissions: ['ai.use', 'accounting.voucher.post', 'documents-dispatch'],
            inputSchemaClass: SendPaymentReminderInputSchema::class,
            previewAdapterClass: PaymentReminderSendAdapter::class,
            commandAdapterClass: PaymentReminderSendAdapter::class,
        );
        return $definitions;
    }
}
