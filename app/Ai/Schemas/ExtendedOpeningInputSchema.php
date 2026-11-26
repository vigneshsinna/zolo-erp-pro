<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedOpeningInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return ['batch_id' => 'required|integer|min:1'];
    }
}
