<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\SalesOrder;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an authoritative Sales Order via OrderLifecycleService::createSale.
 * Enforces atomic idempotency, source-state freshness validation, and stock allocation.
 * Strictly adheres to Untrusted Model, Authoritative Server.
 */
final class SalesOrderCommandAdapter implements ActionCommandAdapterInterface
{
    public function __construct(
        private readonly OrderLifecycleService $orderLifecycleService,
    ) {
    }

    public function execute(
        ActionDefinition $definition,
        array $payload,
        CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId,
        string $idempotencyKey,
        string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        if (!($context instanceof CompanyContext)) {
            throw new \InvalidArgumentException('Mode B company context required for sales order execution.');
        }

        // Idempotency: recover existing execution receipt if already completed
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $customerId = (int) ($payload['customer_id'] ?? 0);
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        $items = (array) ($payload['items'] ?? []);
        $productIds = array_map(fn($item) => (int) ($item['product_id'] ?? 0), $items);

        $salesOrder = DB::transaction(function () use ($context, $payload, $customerId, $warehouseId, $productIds, $expectedSourceStateHash, $idempotencyKey, $actorId, ): SalesOrder {
            // 0. Acquire explicit domain row locks on dependencies before evaluating source state
            DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->first();
            DB::table('fiscal_years')->where('company_id', $context->companyId)->where('id', $context->financialYearId)->lockForUpdate()->first();
            DB::table('customers')->where('company_id', $context->companyId)->where('id', $customerId)->lockForUpdate()->first();
            DB::table('warehouses')->where('company_id', $context->companyId)->where('id', $warehouseId)->lockForUpdate()->first();
            DB::table('products')->where('company_id', $context->companyId)->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();

            // 1. Fresh source-state verification (Anti-Stale / Fail-Closed)
            $liveHash = SalesOrderPreviewAdapter::computeSourceStateHash(
                $context,
                $customerId,
                $warehouseId,
                array_values(array_unique($productIds)),
            );

            if (!hash_equals($expectedSourceStateHash, $liveHash)) {
                throw ValidationException::withMessages([
                    'source_state' => 'The underlying customer, warehouse, pricing, or product records changed since approval. Please re-preview the proposal.',
                ]);
            }

            // 2. Prepare payload for OrderLifecycleService::createSale
            $orderData = [
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId,
                'business_date' => !empty($payload['date']) ? (string) $payload['date'] : now()->toDateString(),
                'items' => array_map(function (array $line): array {
                    return [
                        'product_id' => (int) $line['product_id'],
                        'qty' => (float) $line['qty'],
                        'net_unit_price' => (float) $line['net_unit_price'],
                        'sale_unit_id' => (int) ($line['sale_unit_id'] ?? 1),
                        'discount' => (float) ($line['discount'] ?? 0),
                        'note' => $line['note'] ?? null,
                    ];
                }, $payload['items'] ?? []),
            ];

            if (!empty($payload['delivery_date'])) {
                $orderData['promised_date'] = (string) $payload['delivery_date'];
            }
            if (!empty($payload['note'])) {
                $orderData['note'] = (string) $payload['note'];
            }

            // 3. Authoritative ERP domain service execution
            return $this->orderLifecycleService->createSale(
                $orderData,
                $idempotencyKey,
                $context,
                $actorId,
            );
        });

        return new ActionExecutionReceipt(
            resourceType: 'sales_order',
            resourceId: (int) $salesOrder->id,
            status: 'completed',
            details: [
                'reference_no' => (string) $salesOrder->reference_no,
                'customer_id' => (int) $salesOrder->customer_id,
                'warehouse_id' => (int) $salesOrder->warehouse_id,
                'business_date' => $salesOrder->business_date?->toDateString(),
                'item_count' => $salesOrder->items->count(),
                'status' => 'open',
                'summary' => "Sales order {$salesOrder->reference_no} created successfully.",
            ],
        );
    }

    public function recover(
        string $idempotencyKey,
        CompanyContext|\App\Ai\Security\BootstrapSetupPrincipal $context
    ): ?ActionExecutionReceipt {
        if (!($context instanceof CompanyContext)) {
            return null;
        }
        $claim = DB::table('ai_action_executions')
            ->where('company_id', $context->companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first();

        if (!$claim || !$claim->erp_resource_id) {
            return null;
        }

        $order = SalesOrder::where('company_id', $context->companyId)
            ->find($claim->erp_resource_id);

        if (!$order) {
            return null;
        }

        $committedState = json_decode((string) ($claim->committed_state_json ?? '{}'), true) ?? [];

        return new ActionExecutionReceipt(
            resourceType: 'sales_order',
            resourceId: (int) $order->id,
            status: 'completed',
            details: [
                'reference_no' => (string) $order->reference_no,
                'customer_id' => (int) $order->customer_id,
                'warehouse_id' => (int) $order->warehouse_id,
                'business_date' => $order->business_date?->toDateString(),
                'committed_state' => $committedState,
                'summary' => "Sales order {$order->reference_no} recovered via idempotency key.",
            ],
        );
    }
}
