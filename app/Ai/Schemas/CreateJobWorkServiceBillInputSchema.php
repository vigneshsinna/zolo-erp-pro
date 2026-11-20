<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateJobWorkServiceBillInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'receipt_id' => 'required|integer|min:1',
            'business_date' => 'required|date_format:Y-m-d',
            'supplier_invoice_no' => 'required|string|max:60',
            'supplier_invoice_date' => 'required|date_format:Y-m-d',
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:supplier_invoice_date',
            'charge_mode' => 'required|in:capitalise,expense',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty' => 'required|numeric|gt:0',
            'items.*.net_unit_cost' => 'required|numeric|min:0',
            'items.*.purchase_unit_id' => 'nullable|integer|min:1',
            'items.*.discount' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:500',
        ];
    }
}
