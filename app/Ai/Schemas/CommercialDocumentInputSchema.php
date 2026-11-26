<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

/** Shared sales document fields; action-specific requirements are checked by the adapter. */
final class CommercialDocumentInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return (new CreateSalesOrderInputSchema())->rules() + [
            'biller_id' => ['nullable', 'integer', 'min:1'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'sales_order_id' => ['nullable', 'integer', 'min:1'],
            'delivery_challan_id' => ['nullable', 'integer', 'min:1'],
            'purpose' => ['nullable', 'in:approval,quantity_unknown,not_supply'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'items.*.order_item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.batch_no' => ['nullable', 'string', 'max:100'],
            'items.*.tax_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
