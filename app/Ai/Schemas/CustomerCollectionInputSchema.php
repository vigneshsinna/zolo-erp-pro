<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CustomerCollectionInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'min:1'],
            'open_item_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,4})?$/D', 'numeric', 'gt:0'],
            'settlement_account_id' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
