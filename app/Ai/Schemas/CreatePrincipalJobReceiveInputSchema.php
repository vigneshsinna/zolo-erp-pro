<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreatePrincipalJobReceiveInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|min:1',
            'business_date' => 'required|date_format:Y-m-d',
            'challan_no' => 'required|string|max:255',
            'challan_date' => 'required|date_format:Y-m-d',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.hsn_code' => 'required|string|max:20',
            'lines.*.uqc' => 'required|string|max:10',
            'lines.*.qty' => 'required|numeric|gt:0|max:999999999',
            'lines.*.value' => 'required|numeric|min:0|max:999999999999',
        ];
    }
}
