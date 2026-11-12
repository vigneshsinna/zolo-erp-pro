<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedJobWorkInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'business_date' => 'required|date_format:Y-m-d',
            'warehouse_id' => 'required|integer|min:1',
            'order_id' => 'nullable|integer|min:1',
            'dispatch_id' => 'nullable|integer|min:1',
            'rejected_warehouse_id' => 'nullable|integer|min:1',
            'lines' => 'required|array|min:1|max:100',
            'lines.*' => 'required|array:product_id,qty,uom_id,variant_id,product_batch_id,stock_identity_id,serials,dispatch_line_id,accepted_qty,rejected_qty,loss_qty',
            'lines.*.product_id' => 'sometimes|integer|min:1',
            'lines.*.qty' => 'sometimes|numeric|gt:0|max:999999999',
            'lines.*.uom_id' => 'sometimes|integer|min:1',
            'lines.*.variant_id' => 'nullable|integer|min:1',
            'lines.*.product_batch_id' => 'nullable|integer|min:1',
            'lines.*.stock_identity_id' => 'nullable|integer|min:1',
            'lines.*.serials' => 'sometimes|array|max:1000',
            'lines.*.serials.*' => 'string|max:255|distinct',
            'lines.*.dispatch_line_id' => 'sometimes|integer|min:1|distinct',
            'lines.*.accepted_qty' => 'sometimes|numeric|min:0|max:999999999',
            'lines.*.rejected_qty' => 'sometimes|numeric|min:0|max:999999999',
            'lines.*.loss_qty' => 'sometimes|numeric|min:0|max:999999999',
            'outputs' => 'sometimes|array|max:100',
            'outputs.*' => 'required|array:product_id,qty,uom_id,cost_weight',
            'outputs.*.product_id' => 'required|integer|min:1',
            'outputs.*.qty' => 'required|numeric|gt:0|max:999999999',
            'outputs.*.uom_id' => 'required|integer|min:1',
            'outputs.*.cost_weight' => 'required|numeric|min:0|max:999999999',
        ];
    }
}
