<?php

declare(strict_types=1);

namespace App\Services\Optical;

use App\Ai\Knowledge\IndustryContextResolver;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\UomConversionService;
use App\Services\Platform\CompanyContext;
use App\Support\LedgerAmount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deterministic, read-only power-matrix stock availability and replenishment service.
 * Reconciles 1:1 to InventoryAvailabilityService::forCatalogBatch() and stock_movement_lines.
 * Zero model calls and zero AI credits consumed.
 */
final class OpticalPowerMatrixService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly OpticalTransferPolicyService $transferPolicy,
        private readonly IndustryContextResolver $industry,
        private readonly UomConversionService $uom,
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function powerMatrix(CompanyContext $context, int $actorId, array $filters = []): array
    {
        if (!config('ai_business_knowledge.opticals_enabled', false) || !$this->industry->isOptical($context)) {
            throw new AuthorizationException('Optical power matrix requires an active Opticals profile and opt-in flag.');
        }

        $crossStore = !empty($filters['include_authorized_stores'])
            && (bool) config('ai_business_knowledge.opticals_cross_store_enabled', false);
        $authorizedBranches = $this->transferPolicy->authorizedBranches($context, $actorId);
        $scopeBranches = $crossStore ? $authorizedBranches : [$context->branchId];

        $warehousesQuery = Warehouse::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->whereIn('branch_id', $scopeBranches)
            ->orderBy('id');

        if (!empty($filters['warehouse_id'])) {
            $requestedWh = (int) $filters['warehouse_id'];
            $warehouse = Warehouse::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->whereIn('branch_id', $authorizedBranches)
                ->find($requestedWh);
            if (!$warehouse) {
                throw new AuthorizationException('Requested store warehouse is not accessible in your authorized scope.');
            }
            $warehouses = collect([$warehouse]);
        } else {
            $warehouses = $warehousesQuery->get();
        }

        if ($warehouses->isEmpty()) {
            throw new AuthorizationException('No active authorized store warehouses found.');
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $identityQuery = DB::table('optical_lens_identities as oli')
            ->join('products as p', 'p.id', '=', 'oli.product_id')
            ->where('oli.company_id', $context->companyId)
            ->where('p.company_id', $context->companyId)
            ->where('oli.is_active', true)
            ->where('p.is_active', true);

        if (!empty($filters['lens_family'])) {
            $term = addcslashes(trim((string) $filters['lens_family']), '\\%_');
            $identityQuery->where('oli.lens_family', 'LIKE', "%{$term}%");
        }
        if (!empty($filters['product_query'])) {
            $term = addcslashes(trim((string) $filters['product_query']), '\\%_');
            $identityQuery->where(function ($q) use ($term): void {
                $q->where('p.code', 'LIKE', "%{$term}%")
                    ->orWhere('p.name', 'LIKE', "%{$term}%")
                    ->orWhere('oli.lens_family', 'LIKE', "%{$term}%");
            });
        }
        if (isset($filters['sph']) && trim((string) $filters['sph']) !== '') {
            $identityQuery->where('oli.sph', OpticalSpecNormalizer::normalizePower($filters['sph'], 'sph'));
        }
        if (isset($filters['cyl']) && trim((string) $filters['cyl']) !== '') {
            $identityQuery->where('oli.cyl', OpticalSpecNormalizer::normalizePower($filters['cyl'], 'cyl'));
        }
        if (!empty($filters['coating'])) {
            $identityQuery->where('oli.coating', trim((string) $filters['coating']));
        }
        if (!empty($filters['refractive_index'])) {
            $identityQuery->where('oli.refractive_index', trim((string) $filters['refractive_index']));
        }
        if (!empty($filters['dia'])) {
            $identityQuery->where('oli.dia', OpticalSpecNormalizer::normalizeDia($filters['dia'], 'dia'));
        }

        $identities = $identityQuery->orderBy('oli.lens_family')
            ->orderBy('oli.sph')
            ->orderBy('oli.cyl')
            ->orderBy('p.id')
            ->limit($limit)
            ->get([
                'oli.*',
                'p.code as product_code',
                'p.name as product_name',
                'p.unit_id',
                'p.alert_quantity',
            ]);

        if ($identities->isEmpty()) {
            return [
                'as_of' => now('UTC')->toIso8601String(),
                'not_cataloged' => true,
                'authorized_stores' => $warehouses->map(fn (Warehouse $w) => [
                    'warehouse_id' => (int) $w->id,
                    'branch_id' => (int) $w->branch_id,
                    'name' => $w->name,
                ])->values()->all(),
                'cells' => [],
                'writes_executed' => false,
                'model_calls' => 0,
                'credits_charged' => 0,
            ];
        }

        $productIds = $identities->pluck('product_id')->unique()->values()->all();
        $products = Product::where('company_id', $context->companyId)
            ->whereIn('id', $productIds)
            ->with(['unit' => fn ($q) => $q->where('company_id', $context->companyId)])
            ->get()
            ->keyBy('id');

        $stockPerWarehouse = [];
        foreach ($warehouses as $wh) {
            $whContext = new CompanyContext($context->companyId, (int) ($wh->branch_id ?: $context->branchId), $context->financialYearId);
            $stockPerWarehouse[$wh->id] = $this->availability->forCatalogBatch($whContext, $products, [$wh->id]);
        }

        $cells = [];
        foreach ($identities as $row) {
            $product = $products->get($row->product_id);
            if (!$product) {
                continue;
            }
            $unitCode = $product->unit?->unit_code ?: ($product->unit?->unit_name ?: 'PCS');
            $stores = [];
            $totalAvailableUnits = 0;

            foreach ($warehouses as $wh) {
                $bal = $stockPerWarehouse[$wh->id][$product->id] ?? [];
                $onHand = (int) ($bal['on_hand'] ?? 0);
                $reserved = (int) ($bal['reserved'] ?? 0);
                $available = $onHand - $reserved;
                $unreconciled = (bool) ($bal['unreconciled'] ?? false);
                $totalAvailableUnits += $available;

                $stores[] = [
                    'warehouse_id' => (int) $wh->id,
                    'branch_id' => (int) ($wh->branch_id ?: $context->branchId),
                    'warehouse_name' => $wh->name,
                    'on_hand' => LedgerAmount::decimal($onHand),
                    'reserved' => LedgerAmount::decimal($reserved),
                    'available' => LedgerAmount::decimal($available),
                    'unit_code' => $unitCode,
                    'unreconciled' => $unreconciled,
                    'stock_status' => $unreconciled ? 'unreconciled' : ($available > 0 ? 'in_stock' : 'zero_stock'),
                ];
            }

            $cells[] = [
                'identity_id' => (int) $row->id,
                'product_id' => (int) $product->id,
                'code' => (string) $product->code,
                'name' => (string) $product->name,
                'unit_code' => $unitCode,
                'alert_quantity' => $product->alert_quantity !== null ? (string) $product->alert_quantity : '0',
                'optical_spec' => [
                    'lens_family' => $row->lens_family,
                    'lens_material' => $row->lens_material,
                    'refractive_index' => $row->refractive_index,
                    'coating' => $row->coating,
                    'lens_form' => $row->lens_form,
                    'sph' => $row->sph,
                    'cyl' => $row->cyl,
                    'axis' => $row->axis,
                    'addition' => $row->addition,
                    'base_curve' => $row->base_curve,
                    'dia' => $row->dia,
                    'lr' => $row->lr,
                    'spec_signature' => $row->spec_signature,
                ],
                'total_available' => LedgerAmount::decimal($totalAvailableUnits),
                'stores' => $stores,
            ];
        }

        return [
            'as_of' => now('UTC')->toIso8601String(),
            'not_cataloged' => false,
            'authorized_stores' => $warehouses->map(fn (Warehouse $w) => [
                'warehouse_id' => (int) $w->id,
                'branch_id' => (int) ($w->branch_id ?: $context->branchId),
                'name' => $w->name,
            ])->values()->all(),
            'cells' => $cells,
            'writes_executed' => false,
            'model_calls' => 0,
            'credits_charged' => 0,
        ];
    }

    /**
     * Deterministic reorder and multi-store transfer suggestions (never creates stock movements automatically).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function suggestReplenishment(CompanyContext $context, int $actorId, array $filters = []): array
    {
        $matrix = $this->powerMatrix($context, $actorId, $filters + ['include_authorized_stores' => true]);
        $productIds = array_values(array_unique(array_column($matrix['cells'], 'product_id')));

        $pendingPoBaseByWh = [];
        if ($productIds !== [] && Schema::hasTable('commercial_order_items')) {
            $openPoRows = DB::table('commercial_order_items as coi')
                ->join('purchases as p', 'p.id', '=', 'coi.order_id')
                ->where('coi.company_id', $context->companyId)
                ->where('p.company_id', $context->companyId)
                ->where('coi.order_kind', 'purchase')
                ->where('p.status', 4)
                ->whereNull('p.reversed_at')
                ->whereIn('coi.product_id', $productIds)
                ->get(['p.warehouse_id', 'coi.product_id', 'coi.id as order_item_id', 'coi.qty_base', 'coi.cancelled_qty_base']);

            $orderItemIds = $openPoRows->pluck('order_item_id')->all();
            $deliveredBase = $orderItemIds !== []
                ? DB::table('commercial_order_allocations')
                    ->where('company_id', $context->companyId)
                    ->where('stage', 'delivery')
                    ->whereIn('order_item_id', $orderItemIds)
                    ->groupBy('order_item_id')
                    ->selectRaw('order_item_id, SUM(qty_base) as qty_delivered')
                    ->pluck('qty_delivered', 'order_item_id')
                : collect();

            foreach ($openPoRows as $row) {
                $remaining = max(0.0, (float) $row->qty_base - (float) $row->cancelled_qty_base - (float) ($deliveredBase[$row->order_item_id] ?? 0));
                $pendingPoBaseByWh[(int) $row->warehouse_id][(int) $row->product_id] =
                    ($pendingPoBaseByWh[(int) $row->warehouse_id][(int) $row->product_id] ?? 0.0) + $remaining;
            }
        }

        $suggestions = [];
        foreach ($matrix['cells'] as $cell) {
            $reorderThreshold = (float) ($cell['alert_quantity'] ?? 0);
            if ($reorderThreshold <= 0) {
                continue;
            }
            $productId = (int) $cell['product_id'];
            $localStores = array_values(array_filter(
                $cell['stores'],
                fn (array $s): bool => (int) $s['branch_id'] === $context->branchId
            ));
            foreach ($localStores as $local) {
                $localWhId = (int) $local['warehouse_id'];
                $available = (float) $local['available'];
                $pendingInbound = (float) ($pendingPoBaseByWh[$localWhId][$productId] ?? 0.0);
                $effective = $available + $pendingInbound;
                if ($effective >= $reorderThreshold) {
                    continue;
                }
                $shortage = round($reorderThreshold - $effective, 4);

                $donor = null;
                foreach ($cell['stores'] as $candidateStore) {
                    if ((int) $candidateStore['warehouse_id'] === $localWhId) {
                        continue;
                    }
                    $surplus = (float) $candidateStore['available'] - $reorderThreshold;
                    if ($surplus > 0) {
                        $policy = $this->transferPolicy->evaluate($context, $actorId, (int) $candidateStore['warehouse_id'], $localWhId);
                        if ($policy['allowed']) {
                            $donor = [
                                'warehouse_id' => (int) $candidateStore['warehouse_id'],
                                'warehouse_name' => $candidateStore['warehouse_name'],
                                'transferable_qty' => min($shortage, $surplus),
                                'transfer_mode' => $policy['transfer_mode'],
                            ];
                            break;
                        }
                    }
                }

                $suggestions[] = [
                    'product_id' => $productId,
                    'code' => $cell['code'],
                    'optical_spec' => $cell['optical_spec'],
                    'target_warehouse_id' => $localWhId,
                    'available' => $available,
                    'pending_po_qty' => $pendingInbound,
                    'reorder_level' => $reorderThreshold,
                    'shortage' => $shortage,
                    'suggested_action' => $donor !== null ? 'stock_transfer.create.v1' : 'purchase_order.create.v1',
                    'transfer_source' => $donor,
                    'requires_human_approval' => true,
                ];
            }
        }

        return [
            'as_of' => $matrix['as_of'],
            'suggestions' => $suggestions,
            'writes_executed' => false,
            'model_calls' => 0,
            'credits_charged' => 0,
        ];
    }
}
