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
 * Command adapter for purchase_order.create.v1.
 * Creates an authoritative Purchase Order (status 4: Ordered) via CommercialApplicationService.
 * Enforces atomic idempotency, source-state freshness validation, and freezes order lines in commercial_order_items.
 */
final class PurchaseOrderCommandAdapter implements ActionCommandAdapterInterface
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
            throw new \InvalidArgumentException('Mode B company context required for purchase order execution.');
        }

        // Idempotency: recover existing execution receipt if already completed
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $supplierId = (int) ($payload['supplier_id'] ?? 0);
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        $items = (array) ($payload['items'] ?? []);
        $productIds = array_map(fn ($item) => (int) ($item['product_id'] ?? 0), $items);

        $purchase = DB::transaction(function () use (
            $context,
            $payload,
            $supplierId,
            $warehouseId,
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

            // 1. Transactional source-state verification: verify underlying business conditions have not changed
            $currentSourceStateHash = PurchaseOrderPreviewAdapter::computeSourceStateHash(
                $context,
                $supplierId,
                $warehouseId,
                array_values(array_unique($productIds)),
            );

            if (!hash_equals($expectedSourceStateHash, $currentSourceStateHash)) {
                throw ValidationException::withMessages([
                    'source_state' => 'Underlying ERP business state changed since proposal approval. Rebuild preview.',
                ]);
            }

            // 2. Prepare domain command data: status 4 = Purchase Order (Ordered)
            $data = $payload;
            $data['status'] = 4;
            $data['payment_status'] = 1; // Due / unpaid
            unset($data['expected_totals']);

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

            // 3. Execute via authoritative ERP domain service
            /** @var Purchase $createdPurchase */
            $createdPurchase = $this->commercialService->create(
                kind: 'purchase',
                data: $data,
                key: $idempotencyKey,
                actor: $actorId,
                context: $context,
            );

            // 4. Assert executed totals match approved proposal totals exactly
            if (!empty($payload['expected_totals']['grand_total'])) {
                $expectedGrandTotal = (float) $payload['expected_totals']['grand_total'];
                $actualGrandTotal = (float) $createdPurchase->grand_total;
                if (abs($actualGrandTotal - $expectedGrandTotal) > 0.01) {
                    throw ValidationException::withMessages([
                        'totals' => sprintf(
                            'Executed order grand total (%.2f) differs from approved proposal grand total (%.2f). Execution aborted.',
                            $actualGrandTotal,
                            $expectedGrandTotal,
                        ),
                    ]);
                }
            }

            if (!empty($payload['expected_totals']['tax_total'])) {
                $expectedTaxTotal = (float) $payload['expected_totals']['tax_total'];
                $actualTaxTotal = (float) $createdPurchase->total_tax;
                if (abs($actualTaxTotal - $expectedTaxTotal) > 0.01) {
                    throw ValidationException::withMessages([
                        'totals' => sprintf(
                            'Executed order tax total (%.2f) differs from approved proposal tax total (%.2f). Execution aborted.',
                            $actualTaxTotal,
                            $expectedTaxTotal,
                        ),
                    ]);
                }
            }

            // Tax breakdown assertion (CGST, SGST, IGST, CESS)
            if (!empty($payload['expected_totals']['tax_breakdown']) && is_array($payload['expected_totals']['tax_breakdown'])) {
                $actualTaxTotals = [
                    'cgst' => 0.0,
                    'sgst' => 0.0,
                    'igst' => 0.0,
                    'cess' => 0.0,
                ];
                foreach ($createdPurchase->productPurchases as $pLine) {
                    $lineSnapshot = (array) ($pLine->tax_snapshot_json ?? []);
                    $actualTaxTotals['cgst'] += (float) ($lineSnapshot['cgst'] ?? 0);
                    $actualTaxTotals['sgst'] += (float) ($lineSnapshot['sgst'] ?? 0);
                    $actualTaxTotals['igst'] += (float) ($lineSnapshot['igst'] ?? 0);
                    $actualTaxTotals['cess'] += (float) ($lineSnapshot['cess'] ?? 0);
                }

                foreach (['cgst', 'sgst', 'igst', 'cess'] as $taxComponent) {
                    if (isset($payload['expected_totals']['tax_breakdown'][$taxComponent])) {
                        $expectedComp = (float) $payload['expected_totals']['tax_breakdown'][$taxComponent];
                        $actualComp = $actualTaxTotals[$taxComponent];
                        if (abs($actualComp - $expectedComp) > 0.01) {
                            throw ValidationException::withMessages([
                                'totals' => sprintf(
                                    'Executed order tax breakdown %s (%.2f) differs from approved proposal (%.2f). Execution aborted.',
                                    strtoupper($taxComponent),
                                    $actualComp,
                                    $expectedComp,
                                ),
                            ]);
                        }
                    }
                }
            }

            // Per-line item verification
            if (!empty($payload['expected_totals']['line_totals']) && is_array($payload['expected_totals']['line_totals'])) {
                $actualLines = $createdPurchase->productPurchases()->get();
                foreach ($payload['expected_totals']['line_totals'] as $expectedLine) {
                    $pId = (int) ($expectedLine['product_id'] ?? 0);
                    $actualLine = $actualLines->first(fn ($l) => (int) $l->product_id === $pId);
                    if (!$actualLine) {
                        throw ValidationException::withMessages([
                            'totals' => "Executed order missing line item for product ID {$pId}.",
                        ]);
                    }
                    if (isset($expectedLine['line_total'])) {
                        $expectedLineTotal = (float) $expectedLine['line_total'];
                        $actualLineTotal = (float) $actualLine->total;
                        if (abs($actualLineTotal - $expectedLineTotal) > 0.01) {
                            throw ValidationException::withMessages([
                                'totals' => sprintf(
                                    'Executed line total for product ID %d (%.2f) differs from approved proposal (%.2f). Execution aborted.',
                                    $pId,
                                    $actualLineTotal,
                                    $expectedLineTotal,
                                ),
                            ]);
                        }
                    }
                    if (isset($expectedLine['tax_amount'])) {
                        $expectedLineTax = (float) $expectedLine['tax_amount'];
                        $actualLineTax = (float) $actualLine->tax;
                        if (abs($actualLineTax - $expectedLineTax) > 0.01) {
                            throw ValidationException::withMessages([
                                'totals' => sprintf(
                                    'Executed line tax for product ID %d (%.2f) differs from approved proposal (%.2f). Execution aborted.',
                                    $pId,
                                    $actualLineTax,
                                    $expectedLineTax,
                                ),
                            ]);
                        }
                    }
                }
            }

            return $createdPurchase;
        });

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