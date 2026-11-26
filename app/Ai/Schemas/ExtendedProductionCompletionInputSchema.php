<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedProductionCompletionInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'business_date' => 'required|date_format:Y-m-d',
            'production_order_id' => 'required|integer|min:1',
            'completed_qty' => 'required|numeric|gt:0|max:999999999',
            'direct_cost' => 'sometimes|numeric|min:0|max:999999999',
            'overhead_cost' => 'sometimes|numeric|min:0|max:999999999',
            'waste_qty' => 'sometimes|numeric|min:0|max:999999999',
            'yield_basis' => 'sometimes|in:base_qty,volume_cbm',
            'components' => 'sometimes|array|min:1|max:100',
            'components.*' => 'required|array:bom_line_id,qty,variant_id,product_batch_id,stock_identity_id,serials',
            'components.*.bom_line_id' => 'required|integer|min:1|distinct',
            'components.*.qty' => 'required|numeric|gt:0|max:999999999',
            'components.*.variant_id' => 'nullable|integer|min:1',
            'components.*.product_batch_id' => 'nullable|integer|min:1',
            'components.*.stock_identity_id' => 'nullable|integer|min:1',
            'components.*.serials' => 'sometimes|array|max:1000',
            'components.*.serials.*' => 'string|max:255|distinct',
            'outputs' => 'sometimes|array|min:1|max:100',
            'outputs.*' => 'required|array:product_id,qty,uom_id,cost_weight',
            'outputs.*.product_id' => 'required|integer|min:1',
            'outputs.*.qty' => 'required|numeric|gt:0|max:999999999',
            'outputs.*.uom_id' => 'required|integer|min:1',
            'outputs.*.cost_weight' => 'required|numeric|min:0|max:999999999',
            'scrap_outputs' => 'sometimes|array|max:100',
            'scrap_outputs.*' => 'required|array:product_id,qty,uom_id,cost_weight',
            'scrap_outputs.*.product_id' => 'required|integer|min:1',
            'scrap_outputs.*.qty' => 'required|numeric|gt:0|max:999999999',
            'scrap_outputs.*.uom_id' => 'required|integer|min:1',
            'scrap_outputs.*.cost_weight' => 'required|numeric|min:0|max:999999999',
        ];
    }
}
