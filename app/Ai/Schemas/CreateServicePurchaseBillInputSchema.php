<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\Contracts\ActionInputSchemaInterface;

/** Service-only bill retains company/branch accounting location; no dummy stock line. */
final class CreateServicePurchaseBillInputSchema implements ActionInputSchemaInterface
{
    public function rules(): array
    {
        return (new CreatePurchaseBillInputSchema)->rules();
    }
}
