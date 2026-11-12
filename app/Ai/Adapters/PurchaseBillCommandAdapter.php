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
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Command adapter for purchase_bill.create.v1 (Purchase Bill / Supplier Invoice).
 * Creates an authoritative Purchase Bill (status 1: Completed Bill) via CommercialApplicationService.
 * Enforces atomic idempotency, source-state freshness validation, GL journal posting,
 * Input Tax Credit recording, and GRN accrual settlement (with zero duplicate stock movements).
 */
final class PurchaseBillCommandAdapter implements ActionCommandAdapterInterface
{
    public function __construct(
        private readonly CommercialApplicationService $commercialService,
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
            throw new \InvalidArgumentException('Mode B company context required for purchase bill execution.');
        }

        // Idempotency: recover existing execution receipt if already completed
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $supplierId = (int) ($payload['supplier_id'] ?? 0);
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        $poId = (int) ($payload['purchase_order_id'] ?? 0);
        $grnId = (int) ($payload['grn_id'] ?? 0);
        $items = (array) ($payload['items'] ?? []);
        $productIds = array_map(fn ($item) => (int) ($item['product_id'] ?? 0), $items);

        $purchase = DB::transaction(function () use (
            $context,
            $payload,
            $supplierId,
            $warehouseId,
            $poId,
            $grnId,
            $productIds,
            $expectedSourceStateHash,
            $idempotencyKey,
            $actorId,
        ): Purchase {
            // 0. Acquire explicit domain row locks on dependencies before evaluating source state
            DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->first();
            DB::table('fiscal_years')->where('company_id', $context->companyId)->where('id', $context->financialYearId)->lockForUpdate()->first();
            DB::table('suppliers')->where('company_id', $context->companyId)->where('id', $supplierId)->lockForUpdate()->first();
            DB::table('party_tax_profiles')->where('company_id', $context->companyId)->where('party_type', 'supplier')->where('party_id', $supplierId)->lockForUpdate()->first();
            DB::table('warehouses')->where('company_id', $context->companyId)->where('id', $warehouseId)->lockForUpdate()->first();
            DB::table('products')->where('company_id', $context->companyId)->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();

            if ($poId > 0) {
                DB::table('purchases')->where('company_id', $context->companyId)->where('id', $poId)->lockForUpdate()->first();
            }
            if ($grnId > 0) {
                DB::table('goods_received_notes')->where('company_id', $context->companyId)->where('id', $grnId)->lockForUpdate()->first();
            }

            // 1. Transactional source-state verification: verify underlying business conditions have not changed
            $currentSourceStateHash = PurchaseBillPreviewAdapter::computeSourceStateHash(
                $context,
                $supplierId,
                $warehouseId,
                array_values(array_unique($productIds)),
                $poId > 0 ? $poId : null,
                $grnId > 0 ? $grnId : null,
            );

            if (!hash_equals($expectedSourceStateHash, $currentSourceStateHash)) {
                throw ValidationException::withMessages([
                    'source_state' => 'Underlying ERP business state changed since proposal approval. Rebuild preview.',
                ]);
            }

            // 2. Prepare domain command data: status 1 = Completed Bill
            $data = $payload;
            $data['status'] = 1;
            $data['payment_status'] = 1; // Due / unpaid
            $data['business_date'] = !empty($payload['date']) ? (string) $payload['date'] : now()->toDateString();
            unset($data['expected_totals']);

            if (!empty($payload['supplier_invoice_no'])) {
                $data['invoice_no'] = (string) $payload['supplier_invoice_no'];
            }
            if ($poId > 0) {
                $data['purchase_order_id'] = $poId;
            }
            if ($grnId > 0) {
                $data['grn_id'] = $grnId;
                $data['goods_received_note_id'] = $grnId;
            }

            if (isset($data['order_discount']) && (empty($data['order_discount']) || (float) $data['order_discount'] === 0.0)) {
                unset($data['order_discount']);
            }
            if (isset($data['shipping_cost']) && (empty($data['shipping_cost']) || (float) $data['shipping_cost'] === 0.0)) {
                unset($data['shipping_cost']);
            }

            // 3. Execute authoritative ERP command
            $createdPurchase = $this->commercialService->create(
                'purchase',
                $data,
                $idempotencyKey,
                $actorId,
                $context,
            );

            if (!($createdPurchase instanceof Purchase)) {
                throw new LogicException('Expected Purchase instance from CommercialApplicationService.');
            }

            // 4. Assert financial totals against approved expectation
            if (!empty($payload['expected_totals'])) {
                $expected = $payload['expected_totals'];
                $actualGrandTotal = (float) $createdPurchase->grand_total;
                $expectedGrandTotal = (float) ($expected['grand_total'] ?? 0);

                if (abs($actualGrandTotal - $expectedGrandTotal) > 0.02) {
                    throw new LogicException("Financial total discrepancy: expected {$expectedGrandTotal}, got {$actualGrandTotal}.");
                }

                if (isset($expected['tax_total'])) {
                    $actualTaxTotal = (float) $createdPurchase->total_tax;
                    $expectedTaxTotal = (float) $expected['tax_total'];

                    if (abs($actualTaxTotal - $expectedTaxTotal) > 0.02) {
                        throw new LogicException("Tax total discrepancy: expected {$expectedTaxTotal}, got {$actualTaxTotal}.");
                    }
                }
            }

            return $createdPurchase->fresh(['productPurchases']);
        });

        return new ActionExecutionReceipt(
            resourceType: 'purchase_bill',
            resourceId: (int) $purchase->id,
            status: 'completed',
            details: [
                'reference_no' => (string) $purchase->reference_no,
                'supplier_id' => (int) $purchase->supplier_id,
                'warehouse_id' => (int) $purchase->warehouse_id,
                'business_date' => $purchase->created_at?->toDateString(),
                'item_count' => $purchase->productPurchases->count(),
                'grand_total' => sprintf('%.2f', (float) $purchase->grand_total),
                'total_tax' => sprintf('%.2f', (float) $purchase->total_tax),
                'status' => 'completed',
                'purchase_order_id' => $poId > 0 ? $poId : null,
                'grn_id' => $grnId > 0 ? $grnId : null,
                'summary' => "Purchase Bill {$purchase->reference_no} created and posted successfully.",
            ],
        );
    }

    public function recover(string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context): ?ActionExecutionReceipt
    {
        if ($context instanceof BootstrapSetupPrincipal) {
            return null;
        }

        $existing = DB::table('idempotency_keys')
            ->where('company_id', $context->companyId)
            ->where('key', $idempotencyKey)
            ->first();

        if ($existing && $existing->response_type === 'purchase') {
            $purchase = Purchase::visibleIn($context)
                ->where('status', 1)
                ->find((int) $existing->response_ref);

            if ($purchase) {
                return new ActionExecutionReceipt(
                    resourceType: 'purchase_bill',
                    resourceId: (int) $purchase->id,
                    status: 'completed',
                    details: [
                        'reference_no' => (string) $purchase->reference_no,
                        'supplier_id' => (int) $purchase->supplier_id,
                        'warehouse_id' => (int) $purchase->warehouse_id,
                        'business_date' => $purchase->created_at?->toDateString(),
                        'grand_total' => (float) $purchase->grand_total,
                        'total_tax' => (float) $purchase->total_tax,
                        'status' => 'completed',
                        'reconciled_from_idempotency' => true,
                    ],
                );
            }
        }

        return null;
    }
}
