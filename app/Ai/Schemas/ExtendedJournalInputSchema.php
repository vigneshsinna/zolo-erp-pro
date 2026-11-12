<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class ExtendedJournalInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return [
            'business_date' => 'required|date_format:Y-m-d',
            'description' => 'required|string|min:3|max:500',
            'items' => 'required|array|min:2|max:100',
            'items.*' => 'required|array:chart_of_account_id,debit,credit,memo,partner_type,partner_id',
            'items.*.chart_of_account_id' => 'required|integer|min:1',
            'items.*.debit' => ['sometimes', 'regex:/^\d{1,12}(\.\d{1,4})?$/D'],
            'items.*.credit' => ['sometimes', 'regex:/^\d{1,12}(\.\d{1,4})?$/D'],
            'items.*.memo' => 'nullable|string|max:255',
            'items.*.partner_type' => 'nullable|in:customer,supplier',
            'items.*.partner_id' => 'nullable|integer|min:1',
        ];
    }
}
