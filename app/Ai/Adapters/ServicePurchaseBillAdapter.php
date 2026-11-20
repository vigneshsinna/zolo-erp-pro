<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Product;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Guarded specialization over existing Commercial purchase bill path.
 * The authoritative PurchasePostingService ignores service stock movements.
 */
final class ServicePurchaseBillAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function __construct(
        private readonly PurchaseBillPreviewAdapter $previewAdapter,
        private readonly PurchaseBillCommandAdapter $commandAdapter,
    ) {}

    public function preview(
        ActionDefinition $definition, array $slots, CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $this->assertServiceOnly($slots, $context);
        $result = $this->previewAdapter->preview($definition, $slots, $context);
        return new ActionPreviewResult(
            payload: $result->payload,
            preview: $result->preview + [
                'classification' => 'service_only',
                'inventory_effect' => 'no_stock_receipt',
                'warehouse_role' => 'accounting_branch_location',
                'posted' => false,
            ],
            sourceStateHash: $result->sourceStateHash,
            warnings: $result->warnings,
        );
    }

    public function execute(
        ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId, string $idempotencyKey, string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        $this->assertServiceOnly($payload, $context);
        return $this->commandAdapter->execute(
            $definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash,
        );
    }

    public function recover(
        string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        return $this->commandAdapter->recover($idempotencyKey, $context);
    }

    private function assertServiceOnly(array $slots, CompanyContext|BootstrapSetupPrincipal $context): void
    {
        if (!config('ai_business_knowledge.service_bill_enabled', false)) {
            throw ValidationException::withMessages(['action' => 'Service bill AI capability is disabled.']);
        }
        if (!$context instanceof CompanyContext) {
            throw new \InvalidArgumentException('Company context required for service billing.');
        }
        if (!empty($slots['purchase_order_id']) || !empty($slots['grn_id'])) {
            throw ValidationException::withMessages(['links' => 'Service-only bills cannot settle inventory PO/GRN through this route.']);
        }
        $items = $slots['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            throw ValidationException::withMessages(['items' => 'At least one service item is required.']);
        }
        $ids = array_map(static fn ($row): int => (int) ($row['product_id'] ?? 0), $items);
        if (in_array(0, $ids, true)) {
            throw ValidationException::withMessages(['items' => 'Select an authorised service item.']);
        }
        $products = Product::forCompany($context)->whereIn('id', array_unique($ids))
            ->where('type', 'service')->where('is_active', true)->pluck('id')->all();
        if (count($products) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'Only active service catalog items can be used.']);
        }
        // Never allow a job-work receipt to be billed as a generic service bill.
        if (array_key_exists('job_work_receipt_id', $slots)) {
            throw ValidationException::withMessages(['job_work_receipt_id' => 'Use the dedicated job-work bill.']);
        }
    }
}
