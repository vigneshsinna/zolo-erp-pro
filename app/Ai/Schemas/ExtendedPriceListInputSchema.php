<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedPriceListInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:3|max:500',
            'effective_date' => 'required|date_format:Y-m-d',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|array:product_id,price',
            'items.*.product_id' => 'required|integer|min:1|distinct',
            'items.*.price' => ['required', 'regex:/^\d{1,12}(\.\d{1,4})?$/D'],
        ];
    }
}
