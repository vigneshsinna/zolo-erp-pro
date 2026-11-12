<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedProductionInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'business_date' => 'required|date_format:Y-m-d',
            'bom_id' => 'required|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'planned_qty' => 'required|numeric|gt:0|max:999999999',
        ];
    }
}
