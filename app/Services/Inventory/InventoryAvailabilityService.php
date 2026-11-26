<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Services\Platform\CompanyContext;
use App\Support\LedgerAmount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * on_hand: the transactional projection (what postings lock and check).
 * ledger_on_hand: the movement history total; differs only until opening/reconciliation is clean.
 * reserved: base quantity on Pending (2) and Processing (5) sales, which have not issued stock yet.
 */
class InventoryAvailabilityService
{
    public const RESERVING_SALE_STATUSES = [2, 5];

    public function __construct(private readonly UomConversionService $uom)
    {
    }

    public function forProduct(int $productId, ?int $warehouseId = null, ?int $variantId = null, ?int $batchId = null): array
    {
        $product = Product::findOrFail($productId);
        $filter = function ($query, string $warehouse, string $variant, string $batch) use ($warehouseId, $variantId, $batchId) {
            $warehouseId !== null && $query->where($warehouse, $warehouseId);
            $variantId !== null && $query->where($variant, $variantId);
            $batchId !== null && $query->where($batch, $batchId);

            return $query;
        };

        $onHand = (float) $filter(DB::table('product_warehouse')->where('product_id', $productId),
            'warehouse_id', 'variant_id', 'product_batch_id')->sum('qty');
        $ledger = (float) $filter(DB::table('stock_movement_lines')->where('product_id', $productId),
            'warehouse_id', 'variant_id', 'batch_id')->sum('qty_base');

        $reserved = 0.0;
        $pending = $filter(DB::table('product_sales')->join('sales', 'sales.id', '=', 'product_sales.sale_id')
            ->where('product_sales.product_id', $productId)
            ->whereIn('sales.sale_status', self::RESERVING_SALE_STATUSES)
            ->whereNull('sales.deleted_at'), 'sales.warehouse_id', 'product_sales.variant_id', 'product_sales.product_batch_id')
            ->groupBy('product_sales.sale_unit_id')
            ->selectRaw('product_sales.sale_unit_id AS unit_id, SUM(product_sales.qty) AS qty')->get();
        foreach ($pending as $row) {
            $reserved += $this->uom->toBase($product, (float) $row->qty, $row->unit_id ? (int) $row->unit_id : null);
        }

        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'variant_id' => $variantId,
            'batch_id' => $batchId,
            'on_hand' => round($onHand, 4),
            'ledger_on_hand' => round($ledger, 4),
            'reserved' => round($reserved, 4),
            'available' => round($onHand - $reserved, 4),
        ];
    }

    /**
     * Aggregate a bounded batch of company-owned products for authorized branch warehouses.
     * Quantity and ledger value are returned as scaled integers.
     *
     * @param Collection<int, Product> $products
     * @param int[] $warehouseIds
     */
    public function forCatalogBatch(CompanyContext $context, Collection $products, array $warehouseIds): array
    {
        $products = $products->keyBy('id');
        $ids = $products->keys();
        $dimensions = ['product_id', 'warehouse_id', 'variant_id'];
        $projection = DB::table('product_warehouse')->where('company_id', $context->companyId)
            ->whereIn('product_id', $ids)->whereIn('warehouse_id', $warehouseIds)
            ->groupBy([...$dimensions, 'product_batch_id'])
            ->selectRaw('product_id, warehouse_id, variant_id, product_batch_id as batch_id, SUM(qty) as quantity')->get();
        $ledger = DB::table('stock_movement_lines')->where('company_id', $context->companyId)
            ->whereIn('product_id', $ids)->whereIn('warehouse_id', $warehouseIds)
            ->groupBy([...$dimensions, 'batch_id'])
            ->selectRaw('product_id, warehouse_id, variant_id, batch_id, SUM(qty_base) as quantity, SUM(value) as value')->get();
        $reservations = DB::table('product_sales')->join('sales', 'sales.id', '=', 'product_sales.sale_id')
            ->where('product_sales.company_id', $context->companyId)->where('sales.company_id', $context->companyId)
            ->where('sales.branch_id', $context->branchId)->whereIn('sales.warehouse_id', $warehouseIds)
            ->whereIn('product_sales.product_id', $ids)->whereIn('sales.sale_status', self::RESERVING_SALE_STATUSES)
            ->whereNull('sales.deleted_at')->whereNull('sales.reversed_at')
            ->groupBy('product_sales.product_id', 'sales.warehouse_id', 'product_sales.sale_unit_id')
            ->selectRaw('product_sales.product_id, product_sales.sale_unit_id as unit_id, SUM(product_sales.qty) as quantity')->get();

        $key = static fn ($row) => implode('/', [$row->product_id, $row->warehouse_id, $row->variant_id, $row->batch_id]);
        $projection = $projection->keyBy($key);
        $ledger = $ledger->keyBy($key);
        $result = [];
        foreach ($projection->keys()->merge($ledger->keys())->unique() as $identity) {
            $productId = (int) explode('/', $identity, 2)[0];
            $physical = LedgerAmount::units($projection->get($identity)->quantity ?? 0);
            $posted = LedgerAmount::units($ledger->get($identity)->quantity ?? 0);
            $result[$productId]['on_hand'] = ($result[$productId]['on_hand'] ?? 0) + $physical;
            $result[$productId]['value'] = ($result[$productId]['value'] ?? 0) + LedgerAmount::units($ledger->get($identity)->value ?? 0);
            $result[$productId]['unreconciled'] = ($result[$productId]['unreconciled'] ?? false) || $physical !== $posted;
        }

        foreach ($reservations as $row) {
            $reserved = $this->uom->toBase($products[$row->product_id], (float) $row->quantity, $row->unit_id ? (int) $row->unit_id : null);
            $result[$row->product_id]['reserved'] = ($result[$row->product_id]['reserved'] ?? 0) + LedgerAmount::units($reserved);
        }

        return $result;
    }
}
