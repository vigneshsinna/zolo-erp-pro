<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Services\Platform\CompanyContext;

interface ActionCommandAdapterInterface
{
    /**
     * Must revalidate ERP prerequisites WITHIN the domain transaction and
     * apply a stable idempotency key; never trust only the earlier preview.
     * The AI foundation does not register any implementation in Phase 1.
     */
    public function execute(
        ActionDefinition $definition,
        array $payload,
        CompanyContext $context,
        int $actorId,
        string $idempotencyKey,
        string $expectedSourceStateHash,
    ): ActionExecutionReceipt;

    /** Recover committed ERP receipt after a retry or lost network response. */
    public function recover(string $idempotencyKey, CompanyContext $context): ?ActionExecutionReceipt;
}
