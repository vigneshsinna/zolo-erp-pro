<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;
use App\Services\Industry\IndustryCatalog;
use Illuminate\Validation\Rule;

final class CreateCompanyInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/'],
            'company_group_id' => ['nullable', 'integer', 'min:1'],
            'industry' => ['required', 'string', Rule::in(array_keys(IndustryCatalog::SUBTYPES))],
            'subtype' => ['nullable', 'string', 'max:50'],
            'state_code' => ['nullable', 'string', 'max:20'],
            'base_currency_id' => ['nullable', 'integer', 'min:1'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            'fy_start' => ['required', 'date_format:Y-m-d'],
            'fy_end' => ['required', 'date_format:Y-m-d', 'after:fy_start'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'website' => ['nullable', 'string', 'max:100'],
            'pan' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'registration_type' => ['nullable', 'string', 'in:regular,composition,unregistered'],
        ];
    }
}

