<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateUnitInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'unit_code' => ['required', 'string', 'max:50'],
            'unit_name' => ['required', 'string', 'max:255'],
            'base_unit' => ['nullable', 'integer', 'min:1'],
            'operator' => ['nullable', 'string', 'in:*,/'],
            'operation_value' => ['nullable', 'numeric', 'min:0.000001'],
        ];
    }
}

