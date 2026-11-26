<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreatePrincipalJobServiceInvoiceInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|min:1',
            'business_date' => 'required|date_format:Y-m-d',
            'qty' => 'required|numeric|gt:0',
            'rate' => 'required|numeric|min:0',
        ];
    }
}
