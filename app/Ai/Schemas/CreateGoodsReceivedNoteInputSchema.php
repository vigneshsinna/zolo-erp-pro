<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateGoodsReceivedNoteInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'grn_date' => ['nullable', 'date_format:Y-m-d'],
            'purchase_order_id' => ['nullable', 'integer', 'min:1'],
            'order_no' => ['nullable', 'string', 'max:100'],
            'transport_name' => ['nullable', 'string', 'max:150'],
            'lr_no' => ['nullable', 'string', 'max:100'],
            'lr_date' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'min:1'],
            'items.*.order_item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.batch_no' => ['nullable', 'string', 'max:100'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
            'expected_totals' => ['nullable', 'array'],
            'expected_totals.total_qty' => ['nullable', 'numeric'],
            'expected_totals.total_cost' => ['nullable', 'numeric'],
            'expected_totals.grand_total' => ['nullable', 'numeric'],
            'expected_totals.line_totals' => ['nullable', 'array'],
            'expected_totals.line_totals.*.product_id' => ['required_with:expected_totals.line_totals', 'integer'],
            'expected_totals.line_totals.*.qty' => ['nullable', 'numeric'],
            'expected_totals.line_totals.*.cost' => ['nullable', 'numeric'],
            'expected_totals.line_totals.*.line_total' => ['nullable', 'numeric'],
        ];
    }
}

