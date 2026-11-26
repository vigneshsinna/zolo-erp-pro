<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ProductImportInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return ['batch_id' => ['required', 'uuid'], 'accepted_rows' => ['required', 'array', 'min:1', 'max:1000'],
            'accepted_rows.*' => ['required', 'integer', 'min:2'], 'review_hash' => ['required', 'string', 'size:64']];
    }
}
