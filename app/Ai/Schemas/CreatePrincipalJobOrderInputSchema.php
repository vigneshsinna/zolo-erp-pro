<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreatePrincipalJobOrderInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|min:1',
            'business_date' => 'required|date_format:Y-m-d',
            'process' => 'required|string|max:255',
            'service_product_id' => 'required|integer|min:1',
            'rate' => 'required|numeric|gt:0|max:999999999',
            'max_loss_percent' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
