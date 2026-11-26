<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedAccountInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'sub_type' => 'required|string|max:100',
            'parent_id' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:500',
        ];
    }
}
