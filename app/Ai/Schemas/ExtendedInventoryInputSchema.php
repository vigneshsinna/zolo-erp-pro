<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedInventoryInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'business_date' => 'required|date_format:Y-m-d',
            'warehouse_id' => 'required|integer|min:1',
            'to_warehouse_id' => 'nullable|integer|min:1|different:warehouse_id',
            'reason' => 'required|string|min:3|max:500',
            'lines' => 'required|array|min:1|max:100',
            'lines.*' => 'required|array:product_id,qty,uom_id,unit_cost,variant_id,batch_id,stock_identity_id,serials',
            'lines.*.product_id' => 'required|integer|min:1',
            'lines.*.qty' => 'required|numeric|not_in:0|between:-999999999,999999999',
            'lines.*.uom_id' => 'required|integer|min:1',
            'lines.*.unit_cost' => 'nullable|numeric|min:0|max:999999999',
            'lines.*.variant_id' => 'nullable|integer|min:1',
            'lines.*.batch_id' => 'nullable|integer|min:1',
            'lines.*.stock_identity_id' => 'nullable|integer|min:1',
            'lines.*.serials' => 'sometimes|array|max:1000',
            'lines.*.serials.*' => 'string|max:255|distinct',
        ];
    }
}
