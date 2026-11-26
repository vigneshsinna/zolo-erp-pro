<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Communications\PaymentReminderService;
use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Platform\CompanyContext;

final readonly class PaymentReminderSendAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function __construct(private PaymentReminderService $service) {}

    public function preview(ActionDefinition $definition, array $slots, CompanyContext|BootstrapSetupPrincipal $context): ActionPreviewResult
    {
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('A company context is required.');
        return $this->service->preview($definition, $slots, $context);
    }

    public function execute(ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId, string $idempotencyKey, string $expectedSourceStateHash): ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('A company context is required.');
        return $this->service->enqueue($definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash);
    }

    public function recover(string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context): ?ActionExecutionReceipt
    {
        return $context instanceof CompanyContext ? $this->service->recover($idempotencyKey, $context) : null;
    }
}
