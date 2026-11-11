<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Purchase;
use App\Services\Commercial\CommercialApplicationService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Command adapter for purchase_order.create.v1.
 * Creates an authoritative Purchase Order (status 4: Ordered) via CommercialApplicationService.
 * Enforces atomic idempotency and freezes order lines in commercial_order_items.
 */
final class PurchaseOrderCommandAdapter implements ActionCommandAdapterInterface
{
    public function __construct(
        private readonly CommercialApplicationService $commercialService,
    ) {}

    public function execute(
        ActionDefinition $definition,
        array $payload,
        CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId,
        string $idempotencyKey,
        string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for purchase order execution.');
        }

        // Idempotency: recover existing execution receipt if already completed
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        // Prepare domain command data: status 4 = Purchase Order (Ordered)
        $data = $payload;
        $data['status'] = 4;
        $data['payment_status'] = 1; // Due / unpaid

        // CommercialApplicationService rejects non-empty order_tax_rate, shipping_cost, or order_discount if zero
        if (isset($data['order_tax_rate']) && (empty($data['order_tax_rate']) || (float) $data['order_tax_rate'] === 0.0)) {
            unset($data['order_tax_rate']);
        }
        if (isset($data['shipping_cost']) && (empty($data['shipping_cost']) || (float) $data['shipping_cost'] === 0.0)) {
            unset($data['shipping_cost']);
        }
        if (isset($data['order_discount']) && (empty($data['order_discount']) || (float) $data['order_discount'] === 0.0)) {
            unset($data['order_discount']);
        }

        // Ensure tax_rate_override is populated if tax_rate is explicitly specified on line items
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as &$item) {
                if (isset($item['tax_rate']) && !isset($item['tax_rate_override'])) {
                    $item['tax_rate_override'] = (string) $item['tax_rate'];
                }
            }
            unset($item);
        }

        // Execute via authoritative ERP domain service
        // CommercialApplicationService::create encapsulates its own DB::transaction
        /** @var Purchase $purchase */
        $purchase = $this->commercialService->create(
            kind: 'purchase',
            data: $data,
            key: $idempotencyKey,
            actor: $actorId,
            context: $context,
        );

        return new ActionExecutionReceipt(
            resourceType: 'purchase_order',
            resourceId: (int) $purchase->id,
            status: 'completed',
            details: [
                'purchase_id' => (int) $purchase->id,
                'reference_no' => (string) $purchase->reference_no,
                'supplier_id' => (int) $purchase->supplier_id,
                'warehouse_id' => (int) $purchase->warehouse_id,
                'grand_total' => sprintf('%.2f', (float) $purchase->grand_total),
                'order_status' => 'ordered',
                'items_count' => $purchase->productPurchases()->count(),
                'source_type' => 'purchase_order',
            ],
        );
    }

    public function recover(
        string $idempotencyKey,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        if (!($context instanceof CompanyContext)) {
            return null;
        }

        $row = DB::table('ai_action_executions')
            ->where('company_id', $context->companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first();

        if (!$row) {
            return null;
        }

        $result = json_decode((string) $row->result_json, true, flags: JSON_THROW_ON_ERROR);

        return new ActionExecutionReceipt(
            resourceType: (string) ($row->erp_resource_type ?? 'purchase_order'),
            resourceId: (int) ($row->erp_resource_id ?? 0),
            status: 'completed',
            details: (array) ($result['details'] ?? []),
        );
    }
}
