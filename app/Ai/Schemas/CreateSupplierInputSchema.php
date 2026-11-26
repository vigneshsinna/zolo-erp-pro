<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateSupplierInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:100'],
        ];
    }
}

