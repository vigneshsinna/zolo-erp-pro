<?php

namespace App\Services\Industry;

use App\Services\ERP\CompanyWriteGuard;
use App\Services\Operations\OperationPosting;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Database\Query\Builder;

/** Read-only profile views of shared inventory balances and persisted dimensional results. */
class IndustryStockQueries
{
    public function pieces(int $product, int $warehouse, string $term, CompanyContext $context, int $actor): array
    {
        app(OperationPosting::class)->authorize('inventory.dimension_tracking', 'products-index', $context, $actor);
        app(CompanyWriteGuard::class)->owned(\App\Models\Product::class, $product, $context, 'product_id');
        app(CompanyWriteGuard::class)->warehouse($warehouse, $context, $actor);
        $balances = DB::table('stock_movement_lines as l')->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->where('l.company_id', $context->companyId)->where('m.company_id', $context->companyId)
            ->where('m.projection_mode', 'applied')->where('l.warehouse_id', $warehouse)->where('l.product_id', $product)
            ->groupBy('l.stock_identity_id')->selectRaw('l.stock_identity_id, SUM(l.qty_base) AS qty_base')->havingRaw('SUM(l.qty_base) > 0.00005');
        $species = DB::table('product_attribute_values as v')->join('product_attribute_definitions as d', 'd.id', '=', 'v.definition_id')
            ->where('v.company_id', $context->companyId)->where('d.company_id', $context->companyId)->where('d.key', 'species')
            ->where('v.product_id', $product)->value('v.value_json');
        $species = $species ? (string) json_decode($species) : '';
        $term = mb_strtolower(trim($term));
        return DB::table('stock_identities as i')->join('stock_dimensions as d', 'd.stock_identity_id', '=', 'i.id')
            ->joinSub($balances, 'b', 'b.stock_identity_id', '=', 'i.id')->where('i.company_id', $context->companyId)
            ->where('i.product_id', $product)->where('i.warehouse_id', $warehouse)->where('i.identity_type', 'piece')
            ->where('i.status', 'in_stock')->orderBy('i.id')->get(['i.id','i.identity_no','d.length','d.width','d.thickness',
                'd.dimension_uom','d.grade','d.computed_cft','d.computed_cbm','d.formula_version','b.qty_base'])
            ->map(fn ($row) => (array) $row + ['species' => $species])->filter(fn ($row) => $term === ''
                || str_contains(mb_strtolower(implode(' ', array_map(fn ($value) => (string) $value, $row))), $term))
            ->take(200)->values()->all();
    }

    /** Lots of a product with stock in a warehouse, oldest expiry first, for choosing the lot on a sale or issue. */
    public function lots(int $product, int $warehouse, CompanyContext $context, int $actor): array
    {
        app(\App\Services\Commercial\CommercialPermission::class)->assert('products-index', $context, $actor);
        app(CompanyWriteGuard::class)->owned(\App\Models\Product::class, $product, $context, 'product_id');
        app(CompanyWriteGuard::class)->warehouse($warehouse, $context, $actor);
        return DB::table('product_warehouse as w')->join('product_batches as b', 'b.id', '=', 'w.product_batch_id')
            ->where('w.product_id', $product)->where('w.warehouse_id', $warehouse)->where('b.product_id', $product)
            ->where(fn ($q) => $q->whereNull('b.company_id')->orWhere('b.company_id', $context->companyId))
            ->groupBy('b.id', 'b.batch_no', 'b.expired_date')->havingRaw('SUM(w.qty) > 0.00005')
            ->orderByRaw('b.expired_date IS NULL, b.expired_date')->orderBy('b.id')
            ->selectRaw('b.id, b.batch_no, b.expired_date, SUM(w.qty) AS qty')->limit(200)->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Warehouse period quantities come from all applied ledger lines, including original/reversal pairs.
     * Custody counters are current only: the material allocation contract does not store release business dates.
     * Never add them to a historical warehouse closing balance.
     */
    public function stockByLot(array $filters, CompanyContext $context, int $actor): Builder
    {
        app(OperationPosting::class)->authorize('core.inventory', 'products-index', $context, $actor);
        $guard = app(CompanyWriteGuard::class);
        if (!empty($filters['product_id'])) $guard->owned(\App\Models\Product::class, $filters['product_id'], $context, 'product_id');
        if (!empty($filters['warehouse_id'])) $guard->warehouse($filters['warehouse_id'], $context, $actor);
        $from = $filters['from']; $to = \Carbon\CarbonImmutable::parse($filters['to'])->addDay()->toDateString();
        $lot = "COALESCE(b.batch_no, '')";
        $warehouse = DB::table('stock_movement_lines as l')->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('product_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.company_id', $context->companyId)->where('m.company_id', $context->companyId)
            ->where('m.projection_mode', 'applied')->where('m.status', 'posted')
            ->where('m.movement_type', '!=', 'reversal')->whereNull('m.reversal_of_id')
            ->groupBy('l.product_id', 'l.warehouse_id')->groupByRaw($lot)
            ->selectRaw("l.product_id, l.warehouse_id, {$lot} AS lot")
            ->selectRaw('SUM(CASE WHEN m.movement_date < ? THEN l.qty_base ELSE 0 END) AS opening_qty', [$from])
            ->selectRaw('SUM(CASE WHEN m.movement_date >= ? AND m.movement_date < ? AND l.qty_base > 0 THEN l.qty_base ELSE 0 END) AS inward_qty', [$from, $to])
            ->selectRaw('SUM(CASE WHEN m.movement_date >= ? AND m.movement_date < ? AND l.qty_base < 0 THEN -l.qty_base ELSE 0 END) AS outward_qty', [$from, $to])
            ->selectRaw('SUM(CASE WHEN m.movement_date < ? THEN l.qty_base ELSE 0 END) AS closing_qty', [$to])
            ->selectRaw('SUM(l.qty_base) AS current_qty, 0 AS custody_qty, 0 AS custody_value');
        $events = $warehouse;
        if (Schema::hasTable('delivery_challan_items') && Schema::hasColumn('delivery_challan_items', 'qty_base')) {
            $open = '(COALESCE(i.qty_base, 0) - COALESCE(i.billed_qty_base, 0) - COALESCE(i.returned_qty_base, 0))';
            $custody = DB::table('delivery_challan_items as i')->join('delivery_challans as d', 'd.id', '=', 'i.delivery_challan_id')
                ->where('d.company_id', $context->companyId)->whereNotNull('d.posted_at')
                ->whereRaw($open.' > 0.00005')->groupBy('i.product_id', 'd.warehouse_id')->groupByRaw("COALESCE(i.batch_no, '')")
                ->selectRaw("i.product_id, d.warehouse_id, COALESCE(i.batch_no, '') AS lot, 0 AS opening_qty, 0 AS inward_qty, 0 AS outward_qty, 0 AS closing_qty, 0 AS current_qty")
                ->selectRaw("SUM({$open}) AS custody_qty, SUM(CASE WHEN i.qty_base > 0 THEN i.value * {$open} / i.qty_base ELSE 0 END) AS custody_value");
            $events->unionAll($custody);
        }
        $combined = ($filters['group_by'] ?? 'lot') === 'product' && empty($filters['lot']);
        $totals = DB::query()->fromSub($events, 'e')->groupBy('e.product_id', 'e.warehouse_id')
            ->select('e.product_id', 'e.warehouse_id');
        if ($combined) $totals->selectRaw("'Combined lots' AS lot");
        else $totals->groupBy('e.lot')->addSelect('e.lot');
        foreach (['opening_qty','inward_qty','outward_qty','closing_qty','current_qty','custody_qty','custody_value'] as $column) {
            $totals->selectRaw("SUM(e.{$column}) AS {$column}");
        }
        $query = DB::query()->fromSub($totals, 's')->join('products as p', 'p.id', '=', 's.product_id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $context->companyId)->where('w.company_id', $context->companyId)->where('w.branch_id', $context->branchId)
            ->select('s.*', 'p.name as product', 'p.code as product_code', 'w.name as warehouse')->selectRaw("COALESCE(NULLIF(u.unit_code, ''), u.unit_name, 'Unspecified') AS base_unit")
            ->selectRaw('(s.current_qty + s.custody_qty) AS owned_current_qty');
        if (!empty($filters['product_id'])) $query->where('s.product_id', $filters['product_id']);
        if (!empty($filters['warehouse_id'])) $query->where('s.warehouse_id', $filters['warehouse_id']);
        if (isset($filters['lot']) && $filters['lot'] !== '') $query->where('s.lot', $filters['lot'] === '__unbatched__' ? '' : $filters['lot']);
        return $query->orderBy('p.name')->orderBy('s.product_id')->orderBy('s.warehouse_id')->orderBy('s.lot');
    }

    public function batchBalances(CompanyContext $context, int $actor): array
    {
        app(OperationPosting::class)->authorize('inventory.batch_expiry', 'products-index', $context, $actor);
        return DB::table('product_warehouse as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('product_batches as b', 'b.id', '=', 's.product_batch_id')->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.company_id', $context->companyId)->where('w.company_id', $context->companyId)->where('w.branch_id', $context->branchId)
            ->where('b.company_id', $context->companyId)->where('p.company_id', $context->companyId)->where('s.qty', '>', 0)
            ->orderBy('b.expired_date')->limit(500)->get(['p.name as product', 'w.name as warehouse', 'b.batch_no', 'b.mfg_date', 'b.expired_date', 'b.mrp', 's.qty'])->toArray();
    }
}
