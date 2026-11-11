<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Services\Platform\CompanyContext;

interface ActionPreviewAdapterInterface
{
    /**
     * Read/validate only. Must NOT create an ERP record or hold a DB transaction
     * across model inference or human review.
     */
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext $context,
    ): ActionPreviewResult;
}
