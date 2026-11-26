<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateSalesOrderInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'customer_reference_no' => ['nullable', 'string', 'max:150'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.net_unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.sale_unit_id' => ['nullable', 'integer', 'min:1'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'order_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'order_discount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'expected_totals' => ['nullable', 'array'],
            'expected_totals.grand_total' => ['nullable', 'numeric'],
            'expected_totals.tax_total' => ['nullable', 'numeric'],
            'expected_totals.total_price' => ['nullable', 'numeric'],
            'expected_totals.subtotal' => ['nullable', 'numeric'],
            'expected_totals.tax_breakdown' => ['nullable', 'array'],
            'expected_totals.tax_breakdown.cgst' => ['nullable', 'numeric'],
            'expected_totals.tax_breakdown.sgst' => ['nullable', 'numeric'],
            'expected_totals.tax_breakdown.igst' => ['nullable', 'numeric'],
            'expected_totals.tax_breakdown.cess' => ['nullable', 'numeric'],
            'expected_totals.line_totals' => ['nullable', 'array'],
            'expected_totals.line_totals.*.product_id' => ['required_with:expected_totals.line_totals', 'integer'],
            'expected_totals.line_totals.*.qty' => ['nullable', 'numeric'],
            'expected_totals.line_totals.*.net_unit_price' => ['nullable', 'numeric'],
            'expected_totals.line_totals.*.tax_amount' => ['nullable', 'numeric'],
            'expected_totals.line_totals.*.line_total' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }
}

