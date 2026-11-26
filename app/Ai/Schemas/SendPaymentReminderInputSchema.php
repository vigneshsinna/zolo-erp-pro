<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

final class SendPaymentReminderInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return array_intersect_key((new CustomerCollectionInputSchema())->rules(),
            array_flip(['customer_id', 'open_item_id', 'date', 'note'])) + [
            // These are regenerated from the current ERP state on every preview.
            'recipient_email' => ['nullable', 'email:rfc', 'max:191'],
            'open_amount' => ['nullable', 'numeric', 'gt:0'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
