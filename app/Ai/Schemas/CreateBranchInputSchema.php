<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateBranchInputSchema implements ActionInputSchemaInterface
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}

