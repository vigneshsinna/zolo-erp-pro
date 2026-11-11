<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateGroupInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/'],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['integer', 'min:1'],
        ];
    }
}

