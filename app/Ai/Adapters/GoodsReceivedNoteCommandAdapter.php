<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\Purchase;
use App\Services\Commercial\MaterialDocumentService;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Command adapter for grn.create.v1 (Goods Received Note).
 * Creates an authoritative Goods Received Note, receives physical stock,
 * and posts the inventory accrual journal (Dr Inventory, Cr Goods Received Not Invoiced).
 * Links and consumes purchase order delivery allocations when an open PO is specified.
 */
final class GoodsReceivedNoteCommandAdapter implements ActionCommandAdapterInterface
{
    public function __construct(
        private readonly MaterialDocumentService $materialService,
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
            throw new \InvalidArgumentException('Mode B company context required for GRN execution.');
        }

        // Idempotency: recover existing execution receipt if already completed
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        $supplierId = (int) ($payload['supplier_id'] ?? 0);
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        $poId = (int) ($payload['purchase_order_id'] ?? 0);
        $items = (array) ($payload['items'] ?? []);
        $productIds = array_map(fn ($item) => (int) ($item['product_id'] ?? 0), $items);

        $grn = DB::transaction(function () use (
            $context,
            $payload,
            $supplierId,
            $warehouseId,
            $poId,
            $productIds,
            $items,
            $expectedSourceStateHash,
            $idempotencyKey,
            $actorId,
        ): GoodsReceivedNote {
            // 0. Acquire explicit domain row locks on dependencies before evaluating source state
            DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->first();
            DB::table('fiscal_years')->where('company_id', $context->companyId)->where('id', $context->financialYearId)->lockForUpdate()->first();
            DB::table('suppliers')->where('company_id', $context->companyId)->where('id', $supplierId)->lockForUpdate()->first();
            DB::table('warehouses')->where('company_id', $context->companyId)->where('id', $warehouseId)->lockForUpdate()->first();
            DB::table('products')->where('company_id', $context->companyId)->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();

            if ($poId > 0) {
                DB::table('purchases')->where('company_id', $context->companyId)->where('id', $poId)->lockForUpdate()->first();
            }

            // 1. Transactional source-state verification: verify underlying business conditions have not changed
            $currentSourceStateHash = GoodsReceivedNotePreviewAdapter::computeSourceStateHash(
                $context,
                $supplierId,
                $warehouseId,
                array_values(array_unique($productIds)),
                $poId > 0 ? $poId : null,
            );

            if (!hash_equals($expectedSourceStateHash, $currentSourceStateHash)) {
                throw ValidationException::withMessages([
                    'source_state' => 'Underlying ERP business state changed since proposal approval. Rebuild preview.',
                ]);
            }

            // 2. Reserve document number
            $date = !empty($payload['grn_date']) ? (string) $payload['grn_date'] : now()->toDateString();
            $reservation = null;
            $grnNo = null;

            try {
                $docService = app(DocumentNumberService::class);
                $reservation = $docService->reserve('grn', $context, $date, $actorId);
                $grnNo = $reservation->formatted_number;
            } catch (\Throwable) {
                $grnNo = 'GRN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
            }

            // Calculate totals
            $totalQty = 0.0;
            $totalCost = 0.0;
            foreach ($items as $item) {
                $qty = (float) ($item['qty'] ?? 0);
                $cost = (float) ($item['cost'] ?? 0);
                $totalQty += $qty;
                $totalCost += ($qty * $cost);
            }

            // 3. Create GoodsReceivedNote record
            $grn = GoodsReceivedNote::create([
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId,
                'grn_no' => $grnNo,
                'grn_date' => $date,
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'transport_name' => $payload['transport_name'] ?? null,
                'lr_no' => $payload['lr_no'] ?? null,
                'lr_date' => $payload['lr_date'] ?? null,
                'order_no' => $poId > 0 ? (string) Purchase::where('company_id', $context->companyId)->whereKey($poId)->value('reference_no') : ($payload['order_no'] ?? null),
                'total_qty' => $totalQty,
                'total_cost' => $totalCost,
                'total_tax' => 0.0,
                'grand_total' => $totalCost,
                'remarks' => $payload['remarks'] ?? null,
                'status' => 'pending',
                'created_by' => $actorId,
            ]);

            if ($reservation) {
                app(DocumentNumberService::class)->assign($reservation, $grn);
            }

            // 4. Save items
            foreach ($items as $line) {
                $qty = (float) ($line['qty'] ?? 1);
                $cost = (float) ($line['cost'] ?? 0);
                $amount = round($qty * $cost, 2);

                GoodsReceivedNoteItem::create([
                    'company_id' => $context->companyId,
                    'goods_received_note_id' => $grn->id,
                    'product_id' => (int) $line['product_id'],
                    'unit_id' => (int) ($line['unit_id'] ?? 1),
                    'qty' => $qty,
                    'cost' => $cost,
                    'amount' => $amount,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'total' => $amount,
                    'batch_no' => $line['batch_no'] ?? null,
                    'remarks' => $line['remarks'] ?? null,
                ]);
            }

            // 5. If PO linked, prepare consumer plan before posting
            $plan = [];
            if ($poId > 0) {
                $consumerItems = [];
                foreach ($items as $line) {
                    $consumerItems[] = [
                        'product_id' => (int) $line['product_id'],
                        'qty' => (float) $line['qty'],
                        'unit_id' => (int) ($line['unit_id'] ?? 1),
                        'purchase_unit_id' => (int) ($line['unit_id'] ?? 1),
                        'order_item_id' => isset($line['order_item_id']) ? (int) $line['order_item_id'] : null,
                    ];
                }

                $consumerData = [
                    'business_date' => $date,
                    'warehouse_id' => $warehouseId,
                    'supplier_id' => $supplierId,
                    'purchase_order_id' => $poId,
                    'items' => $consumerItems,
                ];

                $plan = $this->orderLifecycleService->prepareConsumer('grn', $consumerData, $context, $actorId, $grn);
            }

            // 6. Post material document: receives stock movement & posts accrual journal
            $this->materialService->post($grn, $context, $actorId);

            // 7. Consume purchase order delivery allocations
            if ($poId > 0 && !empty($plan)) {
                $this->orderLifecycleService->consume($grn, $plan, $context);
            }

            // 8. Record idempotency row
            $hash = hash('sha256', json_encode(['grn', $context->branchId, $context->financialYearId, $payload], JSON_THROW_ON_ERROR));
            DB::table('idempotency_keys')->insert([
                'company_id' => $context->companyId,
                'key' => $idempotencyKey,
                'request_hash' => $hash,
                'response_type' => 'grn',
                'response_ref' => $grn->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $grn->fresh(['items']);
        });

        return new ActionExecutionReceipt(
            resourceType: 'grn',
            resourceId: (int) $grn->id,
            status: 'completed',
            details: [
                'reference_no' => (string) $grn->grn_no,
                'supplier_id' => (int) $grn->supplier_id,
                'warehouse_id' => (int) $grn->warehouse_id,
                'grn_date' => $grn->grn_date?->toDateString(),
                'total_qty' => sprintf('%.2f', (float) $grn->total_qty),
                'grand_total' => sprintf('%.2f', (float) $grn->grand_total),
                'purchase_order_id' => $poId > 0 ? $poId : null,
                'stock_movement_id' => (int) $grn->stock_movement_id,
                'journal_entry_id' => (int) $grn->journal_entry_id,
                'summary' => "Goods Received Note {$grn->grn_no} recorded and posted successfully.",
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

        if ($existing && $existing->response_type === 'grn') {
            $grn = GoodsReceivedNote::where('company_id', $context->companyId)
                ->find((int) $existing->response_ref);

            if ($grn) {
                return new ActionExecutionReceipt(
                    resourceType: 'grn',
                    resourceId: (int) $grn->id,
                    status: 'completed',
                    details: [
                        'reference_no' => (string) $grn->grn_no,
                        'supplier_id' => (int) $grn->supplier_id,
                        'warehouse_id' => (int) $grn->warehouse_id,
                        'grn_date' => $grn->grn_date?->toDateString(),
                        'total_qty' => (float) $grn->total_qty,
                        'grand_total' => (float) $grn->grand_total,
                        'reconciled_from_idempotency' => true,
                    ],
                );
            }
        }

        return null;
    }
}
