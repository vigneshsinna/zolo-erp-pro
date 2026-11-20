<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class CreateJobWorkOrderInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'business_date' => 'required|date_format:Y-m-d',
            'job_worker_party_id' => 'required|integer|min:1',
            'process_type_id' => 'required|integer|min:1',
            'expected_return_date' => 'nullable|date_format:Y-m-d|after_or_equal:business_date',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
