<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateProductInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:standard,combo,digital,service'],
            'barcode_symbology' => ['nullable', 'string', 'in:code128,code39,ean8,ean13,upca,upce'],
            'unit_id' => ['nullable', 'integer', 'min:1'],
            'purchase_unit_id' => ['nullable', 'integer', 'min:1'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'tax_category_id' => ['nullable', 'integer', 'min:1'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}

