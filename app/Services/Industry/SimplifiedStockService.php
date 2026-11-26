<?php
namespace App\Services\Industry;

use App\Models\{Product, Warehouse};
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Operations\OperationPosting;
use App\Services\Platform\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SimplifiedStockService
{
    public function __construct(
        private readonly OperationPosting $posting,
        private readonly CompanyWriteGuard $guard,
    ) {}

    /**
     * Query simplified business-centric stock flow:
     * Purchased -> Given to Job Work -> Returned after Job Work -> Final Available Stock
     */
    public function simplifiedStock(array $filters, CompanyContext $context, int $actor): array
    {
        $this->posting->authorize('core.inventory', 'products-index', $context, $actor);
        if (!empty($filters['product_id'])) {
            $this->guard->owned(Product::class, (int) $filters['product_id'], $context, 'product_id');
        }
        if (!empty($filters['warehouse_id'])) {
            $this->guard->warehouse((int) $filters['warehouse_id'], $context, $actor);
        }

        $from = $filters['from'];
        $to = CarbonImmutable::parse($filters['to'])->addDay()->toDateString();
        $groupBy = $filters['group_by'] ?? 'simplified';
        $byLot = ($groupBy === 'lot') || (!empty($filters['lot']));

        // Categorize warehouses safely checking column existence
        $hasQuarantine = Schema::hasColumn('warehouses', 'is_quarantine');
        $hasExtJwo = Schema::hasColumn('warehouses', 'external_job_order_id');

        $whColumns = ['id', 'name'];
        if ($hasQuarantine) $whColumns[] = 'is_quarantine';
        if ($hasExtJwo) $whColumns[] = 'external_job_order_id';

        $warehouses = DB::table('warehouses')
            ->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('is_active', 1)
            ->get($whColumns);

        $internalWhIds = $warehouses->filter(fn ($w) => empty($w->is_quarantine ?? null) && empty($w->external_job_order_id ?? null))->pluck('id')->all();
        $quarantineWhIds = $hasQuarantine ? $warehouses->filter(fn ($w) => !empty($w->is_quarantine))->pluck('id')->all() : [];
        $externalWhIds = $hasExtJwo ? $warehouses->filter(fn ($w) => !empty($w->external_job_order_id))->pluck('id')->all() : [];

        // 1. Stock Movements for Warehouse Balances & Direct Purchases
        $lotCol = $byLot ? "COALESCE(b.batch_no, '')" : "''";
        
        $movements = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('product_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.company_id', $context->companyId)
            ->where('m.company_id', $context->companyId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('m.movement_type', '!=', 'reversal')
            ->whereNull('m.reversal_of_id');

        if (!empty($filters['product_id'])) {
            $movements->where('l.product_id', (int) $filters['product_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $movements->where('l.warehouse_id', (int) $filters['warehouse_id']);
        }
        if ($byLot && isset($filters['lot']) && $filters['lot'] !== '') {
            $lotFilter = $filters['lot'] === '__unbatched__' ? '' : $filters['lot'];
            $movements->whereRaw("COALESCE(b.batch_no, '') = ?", [$lotFilter]);
        }

        $movementsQuery = clone $movements;
        $movementRows = $movementsQuery
            ->groupBy('l.product_id')
            ->groupByRaw($lotCol)
            ->selectRaw("l.product_id, {$lotCol} AS lot")
            // Purchased inward into internal warehouses within date range
            ->selectRaw("SUM(CASE WHEN m.movement_date >= ? AND m.movement_date < ? AND l.qty_base > 0 
                AND (m.movement_type = 'purchase' OR m.source_type = 'purchase') 
                THEN l.qty_base ELSE 0 END) AS purchased_qty", [$from, $to])
            // Sold outward to customers within date range
            ->selectRaw("SUM(CASE WHEN m.movement_date >= ? AND m.movement_date < ? AND l.qty_base < 0 
                AND (m.movement_type = 'sale' OR m.source_type = 'sale') 
                THEN -l.qty_base ELSE 0 END) AS sold_qty", [$from, $to])
            // Current in godown (internal warehouses, all time)
            ->selectRaw("SUM(CASE WHEN " . ($internalWhIds ? "l.warehouse_id IN (" . implode(',', $internalWhIds) . ")" : "0=1") . " 
                THEN l.qty_base ELSE 0 END) AS in_godown_qty")
            // Current in quarantine
            ->selectRaw("SUM(CASE WHEN " . ($quarantineWhIds ? "l.warehouse_id IN (" . implode(',', $quarantineWhIds) . ")" : "0=1") . " 
                THEN l.qty_base ELSE 0 END) AS in_quarantine_qty")
            // Current at external job work warehouses
            ->selectRaw("SUM(CASE WHEN " . ($externalWhIds ? "l.warehouse_id IN (" . implode(',', $externalWhIds) . ")" : "0=1") . " 
                THEN l.qty_base ELSE 0 END) AS at_job_worker_qty")
            ->get();

        // 2. Job Work Dispatches (Given to Job Work)
        $dispatches = DB::table('job_work_dispatch_lines as dl')
            ->join('job_work_dispatches as d', 'd.id', '=', 'dl.dispatch_id')
            ->where('d.company_id', $context->companyId)
            ->where('d.branch_id', $context->branchId)
            ->whereNull('d.reversed_at')
            ->where('d.business_date', '>=', $from)
            ->where('d.business_date', '<', $to);

        if (!empty($filters['product_id'])) {
            $dispatches->where('dl.product_id', (int) $filters['product_id']);
        }

        $dispatchRows = $dispatches
            ->groupBy('dl.product_id')
            ->select('dl.product_id', DB::raw('SUM(dl.qty_base) as dispatched_qty'))
            ->get()
            ->keyBy('product_id');

        // 3. Job Work Receipts & Stock Name Changes
        $receipts = DB::table('job_work_receipt_lines as rl')
            ->join('job_work_receipts as r', 'r.id', '=', 'rl.receipt_id')
            ->join('job_work_dispatch_lines as dl', 'dl.id', '=', 'rl.dispatch_line_id')
            ->where('r.company_id', $context->companyId)
            ->where('r.branch_id', $context->branchId)
            ->whereNull('r.reversed_at')
            ->where('r.business_date', '>=', $from)
            ->where('r.business_date', '<', $to);

        if (!empty($filters['product_id'])) {
            $receipts->where('dl.product_id', (int) $filters['product_id']);
        }

        $receiptRows = $receipts
            ->groupBy('dl.product_id')
            ->select('dl.product_id', DB::raw('SUM(rl.accepted_qty) as returned_qty'))
            ->get()
            ->keyBy('product_id');

        // 4. Traceability & Renamed Stock Notes
        $notes = [];
        $conversionReceipts = DB::table('job_work_receipts')
            ->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->whereNull('reversed_at')
            ->whereNotNull('service_purchase_id')
            ->where('details_json', 'like', '%"stock_name_change"%')
            ->get(['reference_no', 'details_json']);

        $convertedIn = [];
        $convertedOut = [];

        foreach ($conversionReceipts as $receipt) {
            $change = json_decode($receipt->details_json ?? '{}', true)['stock_name_change'] ?? null;
            if (!$change || ($change['date'] ?? '') > $filters['to']) continue;
            foreach ($change['items'] ?? [] as $item) {
                $ref = ' (' . $receipt->reference_no . ' / ' . ($change['purchase_no'] ?? '') . ')';
                $qtyFormatted = rtrim(rtrim(number_format((float) $item['qty'], 4, '.', ''), '0'), '.');
                $fromPid = (int) $item['from_product_id'];
                $toPid = (int) $item['to_product_id'];

                $notes[$fromPid][] = "{$qtyFormatted} returned as {$item['to_name']}{$ref}";
                $notes[$toPid][] = "{$qtyFormatted} received from {$item['from_name']}{$ref}";

                $convertedOut[$fromPid] = ($convertedOut[$fromPid] ?? 0) + (float) $item['qty'];
                $convertedIn[$toPid] = ($convertedIn[$toPid] ?? 0) + (float) $item['qty'];
            }
        }

        // Also discover conversions recorded via paired applied stock movements on job work receipts
        $consumedMovements = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('l.qty_base', '<', 0)
            ->where('m.movement_date', '<', $to)
            ->select('m.source_id as receipt_id', 'l.product_id', 'p.name', 'l.qty_base', 'm.source_no', 'm.movement_date')
            ->get()
            ->groupBy('receipt_id');

        $producedMovements = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('l.qty_base', '>', 0)
            ->where('m.movement_date', '<', $to)
            ->select('m.source_id as receipt_id', 'l.product_id', 'p.name', 'l.qty_base', 'm.source_no', 'm.movement_date')
            ->get()
            ->groupBy('receipt_id');

        foreach ($consumedMovements as $receiptId => $rawList) {
            $convList = $producedMovements->get($receiptId);
            if (!$convList) continue;
            foreach ($rawList as $raw) {
                foreach ($convList as $conv) {
                    if ($raw->product_id == $conv->product_id) continue;
                    $fromPid = (int) $raw->product_id;
                    $toPid = (int) $conv->product_id;
                    $qty = abs((float) $conv->qty_base);
                    $qtyFormatted = rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
                    $ref = ' (' . $raw->source_no . ')';

                    $noteFrom = "{$qtyFormatted} returned as {$conv->name}{$ref}";
                    if (!in_array($noteFrom, $notes[$fromPid] ?? [], true)) {
                        $notes[$fromPid][] = $noteFrom;
                        $convertedOut[$fromPid] = ($convertedOut[$fromPid] ?? 0) + $qty;
                    }

                    $noteTo = "{$qtyFormatted} received from {$raw->name}{$ref}";
                    if (!in_array($noteTo, $notes[$toPid] ?? [], true)) {
                        $notes[$toPid][] = $noteTo;
                        $convertedIn[$toPid] = ($convertedIn[$toPid] ?? 0) + $qty;
                    }
                }
            }
        }

        // 5. Merge all distinct products touched
        $productIds = collect()
            ->merge($movementRows->pluck('product_id'))
            ->merge($dispatchRows->pluck('product_id'))
            ->merge($receiptRows->pluck('product_id'))
            ->merge(array_keys($convertedIn))
            ->merge(array_keys($convertedOut))
            ->unique()
            ->filter();

        if (!empty($filters['product_id'])) {
            $productIds = $productIds->filter(fn ($id) => (int) $id === (int) $filters['product_id']);
        }

        $products = Product::whereIn('products.id', $productIds)
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->get(['products.id', 'products.name', 'products.code', 'units.unit_code', 'units.unit_name'])
            ->keyBy('id');

        $rows = [];
        $movementByProduct = $movementRows->groupBy('product_id');

        foreach ($productIds as $pid) {
            $prod = $products->get($pid);
            if (!$prod) continue;

            $mRows = $movementByProduct->get($pid, collect());
            $purchased = (float) $mRows->sum('purchased_qty');
            $sold = (float) $mRows->sum('sold_qty');
            $inGodown = (float) $mRows->sum('in_godown_qty');
            $inQuarantine = (float) $mRows->sum('in_quarantine_qty');
            $atJobWorker = (float) $mRows->sum('at_job_worker_qty');

            $dispatched = (float) ($dispatchRows->get($pid)?->dispatched_qty ?? 0);
            
            // Returned after job work: either direct returned or converted output
            $directReturned = (float) ($receiptRows->get($pid)?->returned_qty ?? 0);
            $convOut = (float) ($convertedOut[$pid] ?? 0);
            $convIn = (float) ($convertedIn[$pid] ?? 0);
            
            // For raw material, returned after job work is direct + converted out
            // For converted finished goods, returned/received is converted in
            $returned = max($directReturned, $convOut) + $convIn;

            $unit = $prod->unit_code ?: ($prod->unit_name ?: 'Pc');
            $owned = $inGodown + $inQuarantine + $atJobWorker;

            $prodNotes = $notes[$pid] ?? [];

            // Extract lifecycle handle / series keyword
            $handle = '';
            if (preg_match('/\(([^)]+)\)$/', $prod->name, $matches)) {
                $handle = $matches[1];
            } elseif (preg_match('/-([A-Z0-9]+)$/', $prod->code ?? '', $matches)) {
                $handle = $matches[1];
            } else {
                $handle = $prod->code ?: 'MAIN';
            }

            $rows[] = (object) [
                'product_id' => $pid,
                'product' => $prod->name,
                'product_code' => $prod->code,
                'handle' => $handle,
                'lot' => $byLot ? ($mRows->first()?->lot ?: 'Unbatched') : 'Combined lots',
                'base_unit' => $unit,
                'purchased_qty' => $purchased,
                'sold_qty' => $sold,
                'given_to_job_work_qty' => $dispatched,
                'returned_after_job_work_qty' => $returned,
                'in_godown_qty' => $inGodown,
                'in_quarantine_qty' => $inQuarantine,
                'at_job_workers_qty' => $atJobWorker,
                'final_stock_qty' => $owned,
                'conversion_notes' => $prodNotes,
            ];
        }

        // Filter by keyword / handle if provided
        if (!empty($filters['search'])) {
            $term = mb_strtolower(trim($filters['search']));
            $rows = array_values(array_filter($rows, function ($r) use ($term) {
                return str_contains(mb_strtolower($r->product), $term)
                    || str_contains(mb_strtolower($r->product_code ?? ''), $term)
                    || str_contains(mb_strtolower($r->lot ?? ''), $term)
                    || str_contains(mb_strtolower($r->handle ?? ''), $term)
                    || collect($r->conversion_notes ?? [])->contains(fn ($n) => str_contains(mb_strtolower($n), $term));
            }));
        }

        // Sort by product name
        usort($rows, fn ($a, $b) => strcmp($a->product, $b->product));

        // Summary totals across all rows
        $totals = [
            'total_purchased' => array_sum(array_column($rows, 'purchased_qty')),
            'total_sold' => array_sum(array_column($rows, 'sold_qty')),
            'total_given_to_job_work' => array_sum(array_column($rows, 'given_to_job_work_qty')),
            'total_returned' => array_sum(array_column($rows, 'returned_after_job_work_qty')),
            'total_in_godown' => array_sum(array_column($rows, 'in_godown_qty')),
            'total_in_quarantine' => array_sum(array_column($rows, 'in_quarantine_qty')),
            'total_at_job_workers' => array_sum(array_column($rows, 'at_job_workers_qty')),
            'total_final_stock' => array_sum(array_column($rows, 'final_stock_qty')),
        ];

        return [
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $filters,
        ];
    }

    /**
     * Build chronological interactive lifecycle timeline for a product
     */
    public function inventoryTimeline(int $productId, ?string $lot, array $filters, CompanyContext $context, int $actor): array
    {
        $this->posting->authorize('core.inventory', 'products-index', $context, $actor);
        $this->guard->owned(Product::class, $productId, $context, 'product_id');

        $product = Product::with('unit')->findOrFail($productId);
        $unit = $product->unit?->unit_code ?: ($product->unit?->unit_name ?: 'Pc');
        $from = $filters['from'] ?? '2000-01-01';
        $to = $filters['to'] ?? now()->toDateString();
        $toDateEndOfDay = \Carbon\CarbonImmutable::parse($to)->addDay()->toDateString();

        // Opening balance calculation before period start
        $openingGodownStock = (float) DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('m.movement_date', '<', $from)
            ->sum('l.qty_base');

        $events = [];

        // 1. Inward Purchases
        $purchases = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('purchases as p', function ($join) {
                $join->on('p.id', '=', 'm.source_id')->where('m.source_type', '=', 'purchase');
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('l.qty_base', '>', 0)
            ->where(function ($q) {
                $q->where('m.movement_type', 'purchase')->orWhere('m.source_type', 'purchase');
            })
            ->orderBy('m.movement_date')
            ->get(['m.movement_date', 'm.movement_no', 'm.source_no', 'l.qty_base', 'l.unit_cost', 'l.value',
                   'w.name as warehouse_name', 's.name as supplier_name', 'p.reference_no as purchase_ref']);

        foreach ($purchases as $p) {
            $events[] = [
                'date' => substr($p->movement_date, 0, 10),
                'timestamp' => $p->movement_date,
                'stage' => 'purchase',
                'badge' => 'Purchased Inward',
                'badge_class' => 'badge-purchase',
                'icon' => 'dripicons-download',
                'title' => 'Raw Material Inward',
                'ref_no' => $p->purchase_ref ?: ($p->source_no ?: $p->movement_no),
                'party' => $p->supplier_name ?: 'Direct Purchase',
                'warehouse' => $p->warehouse_name ?: 'Main Godown',
                'inward_qty' => (float) $p->qty_base,
                'outward_qty' => 0.0,
                'qty' => (float) $p->qty_base,
                'unit' => $unit,
                'cost' => (float) $p->unit_cost,
                'total_value' => (float) $p->value,
                'description' => "Purchased {$p->qty_base} {$unit} received into {$p->warehouse_name}",
            ];
        }

        // 2. Job Work Dispatches (Given to Job Work)
        $dispatches = DB::table('job_work_dispatch_lines as dl')
            ->join('job_work_dispatches as d', 'd.id', '=', 'dl.dispatch_id')
            ->join('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as jw', 'jw.id', '=', 'o.job_worker_party_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->where('d.company_id', $context->companyId)
            ->where('dl.product_id', $productId)
            ->whereNull('d.reversed_at')
            ->orderBy('d.business_date')
            ->get(['d.id as dispatch_id', 'd.business_date', 'd.reference_no as dc_no', 'dl.qty_base', 'dl.value',
                   'o.reference_no as jwo_no', 'jw.name as job_worker_name', 'w.name as warehouse_name', 'o.details_json']);

        foreach ($dispatches as $d) {
            $proc = json_decode($d->details_json ?? '{}', true)['process'] ?? [];
            $procName = $proc['name'] ?? ($proc['code'] ?? 'Job Processing');

            $events[] = [
                'date' => substr($d->business_date, 0, 10),
                'timestamp' => $d->business_date,
                'stage' => 'dispatch',
                'badge' => 'Given to Job Work',
                'badge_class' => 'badge-dispatch',
                'icon' => 'dripicons-export',
                'title' => "Dispatched for {$procName}",
                'ref_no' => $d->dc_no,
                'jwo_ref' => $d->jwo_no,
                'party' => $d->job_worker_name ?: 'Job Worker',
                'warehouse' => $d->warehouse_name ?: 'Main Godown',
                'inward_qty' => 0.0,
                'outward_qty' => (float) $d->qty_base,
                'qty' => (float) $d->qty_base,
                'unit' => $unit,
                'process' => $procName,
                'description' => "Dispatched {$d->qty_base} {$unit} to {$d->job_worker_name} for {$procName} (Order: {$d->jwo_no})",
            ];
        }

        // 3. Job Work Receipts (Returned after Job Work)
        $receipts = DB::table('job_work_receipt_lines as rl')
            ->join('job_work_receipts as r', 'r.id', '=', 'rl.receipt_id')
            ->join('job_work_dispatch_lines as dl', 'dl.id', '=', 'rl.dispatch_line_id')
            ->join('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
            ->join('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as jw', 'jw.id', '=', 'o.job_worker_party_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->where('r.company_id', $context->companyId)
            ->where('dl.product_id', $productId)
            ->whereNull('r.reversed_at')
            ->orderBy('r.business_date')
            ->get(['r.business_date', 'r.reference_no as grn_no', 'rl.received_qty', 'rl.accepted_qty',
                   'rl.rejected_qty', 'rl.loss_qty', 'w.name as warehouse_name', 'jw.name as job_worker_name',
                   'r.details_json', 'd.reference_no as dc_no']);

        $convertedToProducts = [];

        foreach ($receipts as $r) {
            $change = json_decode($r->details_json ?? '{}', true)['stock_name_change'] ?? null;
            $convertedTo = null;
            if ($change && isset($change['items'])) {
                foreach ($change['items'] as $item) {
                    if ((int) $item['from_product_id'] === $productId) {
                        $convertedTo = $item['to_name'];
                        $convertedToProducts[$item['to_product_id']] = [
                            'id' => (int) $item['to_product_id'],
                            'name' => $item['to_name'],
                            'qty' => (float) $item['qty'],
                            'ref' => $r->grn_no,
                            'date' => substr($r->business_date, 0, 10),
                        ];
                    }
                }
            }

            $events[] = [
                'date' => substr($r->business_date, 0, 10),
                'timestamp' => $r->business_date,
                'stage' => 'return',
                'badge' => 'Returned after Job Work',
                'badge_class' => 'badge-returned',
                'icon' => 'dripicons-checkmark',
                'title' => $convertedTo ? "Returned & Converted to {$convertedTo}" : 'Returned from Processor',
                'ref_no' => $r->grn_no,
                'dc_ref' => $r->dc_no,
                'party' => $r->job_worker_name ?: 'Job Worker',
                'warehouse' => $r->warehouse_name ?: 'Main Godown',
                'inward_qty' => $convertedTo ? 0.0 : (float) $r->accepted_qty,
                'outward_qty' => 0.0,
                'accepted_qty' => (float) $r->accepted_qty,
                'rejected_qty' => (float) $r->rejected_qty,
                'loss_qty' => (float) $r->loss_qty,
                'qty' => (float) $r->accepted_qty,
                'unit' => $unit,
                'converted_to' => $convertedTo,
                'description' => "Received back {$r->accepted_qty} {$unit} into {$r->warehouse_name}" .
                    ($convertedTo ? " (Converted to {$convertedTo})" : "") .
                    ((float) $r->loss_qty > 0 ? " · Process loss: {$r->loss_qty} {$unit}" : "") .
                    ((float) $r->rejected_qty > 0 ? " · Rejected: {$r->rejected_qty} {$unit}" : ""),
            ];
        }

        // Discover converted output products from job work receipts via applied stock movements
        $consumedReceiptIds = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('l.product_id', $productId)
            ->where('l.qty_base', '<', 0)
            ->pluck('m.source_id')
            ->all();

        if (!empty($consumedReceiptIds)) {
            $producedRows = DB::table('stock_movements as m')
                ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
                ->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('m.company_id', $context->companyId)
                ->where('m.source_type', 'job_work_receipts')
                ->whereIn('m.source_id', $consumedReceiptIds)
                ->where('l.product_id', '!=', $productId)
                ->where('l.qty_base', '>', 0)
                ->get(['l.product_id', 'p.name as product_name', 'l.qty_base', 'm.source_no', 'm.movement_date']);

            foreach ($producedRows as $pRow) {
                if (!isset($convertedToProducts[$pRow->product_id])) {
                    $convertedToProducts[$pRow->product_id] = [
                        'id' => (int) $pRow->product_id,
                        'name' => $pRow->product_name,
                        'qty' => (float) $pRow->qty_base,
                        'ref' => $pRow->source_no,
                        'date' => substr($pRow->movement_date, 0, 10),
                    ];
                }
            }
        }

        // 4. Receipts where this product is the RESULT of a conversion (Converted Inward)
        $conversionInReceipts = DB::table('job_work_receipts')
            ->where('company_id', $context->companyId)
            ->whereNull('reversed_at')
            ->where('details_json', 'like', '%"stock_name_change"%')
            ->get();

        $convertedFromProducts = [];

        foreach ($conversionInReceipts as $cr) {
            $change = json_decode($cr->details_json ?? '{}', true)['stock_name_change'] ?? null;
            if (!$change || empty($change['items'])) continue;
            foreach ($change['items'] as $item) {
                if ((int) $item['to_product_id'] === $productId) {
                    $convertedFromProducts[$item['from_product_id']] = [
                        'id' => (int) $item['from_product_id'],
                        'name' => $item['from_name'],
                        'qty' => (float) $item['qty'],
                        'ref' => $cr->reference_no,
                        'date' => substr($change['date'] ?? $cr->business_date, 0, 10),
                    ];

                    $events[] = [
                        'date' => substr($change['date'] ?? $cr->business_date, 0, 10),
                        'timestamp' => $change['date'] ?? $cr->business_date,
                        'stage' => 'conversion_in',
                        'badge' => 'Produced from Job Work',
                        'badge_class' => 'badge-produced',
                        'icon' => 'dripicons-star',
                        'title' => "Created from {$item['from_name']}",
                        'ref_no' => $cr->reference_no,
                        'party' => 'In-House Production',
                        'warehouse' => 'Main Godown',
                        'inward_qty' => (float) $item['qty'],
                        'outward_qty' => 0.0,
                        'qty' => (float) $item['qty'],
                        'unit' => $unit,
                        'converted_from' => $item['from_name'],
                        'description' => "Created {$item['qty']} {$unit} from processing of {$item['from_name']} (Bill: {$change['purchase_no']})",
                    ];
                }
            }
        }

        // Discover raw source materials from job work receipts via applied stock movements
        $receivedReceiptIds = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('l.product_id', $productId)
            ->where('l.qty_base', '>', 0)
            ->pluck('m.source_id')
            ->all();

        if (!empty($receivedReceiptIds)) {
            $rawRows = DB::table('stock_movements as m')
                ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
                ->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('m.company_id', $context->companyId)
                ->where('m.source_type', 'job_work_receipts')
                ->whereIn('m.source_id', $receivedReceiptIds)
                ->where('l.product_id', '!=', $productId)
                ->where('l.qty_base', '<', 0)
                ->get(['l.product_id', 'p.name as product_name', 'l.qty_base', 'm.source_no', 'm.movement_date']);

            foreach ($rawRows as $rRow) {
                if (!isset($convertedFromProducts[$rRow->product_id])) {
                    $convertedFromProducts[$rRow->product_id] = [
                        'id' => (int) $rRow->product_id,
                        'name' => $rRow->product_name,
                        'qty' => abs((float) $rRow->qty_base),
                        'ref' => $rRow->source_no,
                        'date' => substr($rRow->movement_date, 0, 10),
                    ];
                }
            }
        }

        // 5. Sales Outward
        $sales = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('sales as s', function ($join) {
                $join->on('s.id', '=', 'm.source_id')->where('m.source_type', '=', 'sale');
            })
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where(function ($q) {
                $q->where('m.movement_type', 'sale')->orWhere('m.source_type', 'sale');
            })
            ->orderBy('m.movement_date')
            ->get(['m.movement_date', 'm.movement_no', 'm.source_no', 'l.qty_base', 'l.unit_cost', 'l.value',
                   'w.name as warehouse_name', 'c.name as customer_name', 's.reference_no as sale_ref']);

        foreach ($sales as $s) {
            $saleQty = abs((float) $s->qty_base);
            $events[] = [
                'date' => substr($s->movement_date, 0, 10),
                'timestamp' => $s->movement_date,
                'stage' => 'sale',
                'badge' => 'Sales Outward',
                'badge_class' => 'badge-sale',
                'icon' => 'dripicons-cart',
                'title' => 'Commercial Sale Outward',
                'ref_no' => $s->sale_ref ?: ($s->source_no ?: $s->movement_no),
                'party' => $s->customer_name ?: 'Customer Issue',
                'warehouse' => $s->warehouse_name ?: 'Main Godown',
                'inward_qty' => 0.0,
                'outward_qty' => $saleQty,
                'qty' => $saleQty,
                'unit' => $unit,
                'cost' => (float) $s->unit_cost,
                'total_value' => (float) $s->value,
                'description' => "Dispatched {$saleQty} {$unit} for sale to {$s->customer_name}",
            ];
        }

        // 6. Stock Adjustments
        $adjustments = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('m.movement_type', 'adjustment')
            ->orderBy('m.movement_date')
            ->get(['m.movement_date', 'm.movement_no', 'm.source_no', 'l.qty_base', 'l.unit_cost', 'l.value',
                   'w.name as warehouse_name']);

        foreach ($adjustments as $adj) {
            $adjQty = (float) $adj->qty_base;
            $isPos = $adjQty >= 0;
            $events[] = [
                'date' => substr($adj->movement_date, 0, 10),
                'timestamp' => $adj->movement_date,
                'stage' => 'adjustment',
                'badge' => $isPos ? 'Stock Adjustment (+)' : 'Stock Adjustment (-)',
                'badge_class' => $isPos ? 'badge-adjustment-pos' : 'badge-adjustment-neg',
                'icon' => 'dripicons-retweet',
                'title' => 'Inventory Count Adjustment',
                'ref_no' => $adj->source_no ?: $adj->movement_no,
                'party' => 'Audit Reconciliation',
                'warehouse' => $adj->warehouse_name ?: 'Main Godown',
                'inward_qty' => $isPos ? $adjQty : 0.0,
                'outward_qty' => $isPos ? 0.0 : abs($adjQty),
                'qty' => abs($adjQty),
                'unit' => $unit,
                'cost' => (float) $adj->unit_cost,
                'total_value' => (float) $adj->value,
                'description' => "Audited stock balance adjustment of {$adjQty} {$unit} in {$adj->warehouse_name}",
            ];
        }

        // 7. Sales Returns from Customers (Inward Return)
        $hasReturnsTable = \Illuminate\Support\Facades\Schema::hasTable('returns');
        $hasCustomersTable = \Illuminate\Support\Facades\Schema::hasTable('customers');
        $canJoinCustomer = $hasReturnsTable && $hasCustomersTable;

        $salesReturnsQuery = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id');

        if ($hasReturnsTable) {
            $salesReturnsQuery->leftJoin('returns as ret', function ($join) {
                $join->on('ret.id', '=', 'm.source_id')->where('m.source_type', '=', 'return');
            });
            if ($canJoinCustomer) {
                $salesReturnsQuery->leftJoin('customers as c', 'c.id', '=', 'ret.customer_id');
            }
        }

        $salesReturns = $salesReturnsQuery
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where(function ($q) {
                $q->where('m.movement_type', 'sale_return')
                  ->orWhere('m.source_type', 'return')
                  ->orWhere('m.movement_type', 'return');
            })
            ->orderBy('m.movement_date')
            ->get([
                'm.movement_date', 'm.movement_no', 'm.source_no', 'l.qty_base', 'l.unit_cost', 'l.value',
                'w.name as warehouse_name',
                $canJoinCustomer ? 'c.name as customer_name' : DB::raw("NULL as customer_name"),
                $hasReturnsTable ? 'ret.reference_no as return_ref' : DB::raw("NULL as return_ref"),
            ]);

        foreach ($salesReturns as $sr) {
            $returnQty = abs((float) $sr->qty_base);
            $events[] = [
                'date' => substr($sr->movement_date, 0, 10),
                'timestamp' => $sr->movement_date,
                'stage' => 'return',
                'badge' => 'Customer Return (+)',
                'badge_class' => 'badge-returned',
                'icon' => 'dripicons-backspace',
                'title' => 'Customer Sales Return',
                'ref_no' => $sr->return_ref ?: ($sr->source_no ?: $sr->movement_no),
                'party' => $sr->customer_name ?: 'Customer Return',
                'warehouse' => $sr->warehouse_name ?: 'Main Godown',
                'inward_qty' => $returnQty,
                'outward_qty' => 0.0,
                'qty' => $returnQty,
                'unit' => $unit,
                'cost' => (float) $sr->unit_cost,
                'total_value' => (float) $sr->value,
                'description' => "Received {$returnQty} {$unit} back from customer " . ($sr->customer_name ?: 'Customer'),
            ];
        }

        // 8. Purchase Returns to Suppliers (Outward Return)
        $hasReturnPurchasesTable = \Illuminate\Support\Facades\Schema::hasTable('return_purchases');
        $hasSuppliersTable = \Illuminate\Support\Facades\Schema::hasTable('suppliers');
        $canJoinSupplier = $hasReturnPurchasesTable && $hasSuppliersTable;

        $purchaseReturnsQuery = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id');

        if ($hasReturnPurchasesTable) {
            $purchaseReturnsQuery->leftJoin('return_purchases as rp', function ($join) {
                $join->on('rp.id', '=', 'm.source_id')->where('m.source_type', '=', 'return_purchase');
            });
            if ($canJoinSupplier) {
                $purchaseReturnsQuery->leftJoin('suppliers as s', 's.id', '=', 'rp.supplier_id');
            }
        }

        $purchaseReturns = $purchaseReturnsQuery
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where(function ($q) {
                $q->where('m.movement_type', 'purchase_return')
                  ->orWhere('m.source_type', 'return_purchase');
            })
            ->orderBy('m.movement_date')
            ->get([
                'm.movement_date', 'm.movement_no', 'm.source_no', 'l.qty_base', 'l.unit_cost', 'l.value',
                'w.name as warehouse_name',
                $canJoinSupplier ? 's.name as supplier_name' : DB::raw("NULL as supplier_name"),
                $hasReturnPurchasesTable ? 'rp.reference_no as pr_ref' : DB::raw("NULL as pr_ref"),
            ]);

        foreach ($purchaseReturns as $pr) {
            $prQty = abs((float) $pr->qty_base);
            $events[] = [
                'date' => substr($pr->movement_date, 0, 10),
                'timestamp' => $pr->movement_date,
                'stage' => 'dispatch',
                'badge' => 'Purchase Return (-)',
                'badge_class' => 'badge-dispatch',
                'icon' => 'dripicons-export',
                'title' => 'Return to Supplier',
                'ref_no' => $pr->pr_ref ?: ($pr->source_no ?: $pr->movement_no),
                'party' => $pr->supplier_name ?: 'Supplier Return',
                'warehouse' => $pr->warehouse_name ?: 'Main Godown',
                'inward_qty' => 0.0,
                'outward_qty' => $prQty,
                'qty' => $prQty,
                'unit' => $unit,
                'cost' => (float) $pr->unit_cost,
                'total_value' => (float) $pr->value,
                'description' => "Returned {$prQty} {$unit} to supplier " . ($pr->supplier_name ?: 'Supplier'),
            ];
        }

        // 9. Damage & Scrap Deductions
        if (\Illuminate\Support\Facades\Schema::hasTable('damage_stocks')) {
            $damages = DB::table('damage_stocks as ds')
                ->leftJoin('warehouses as w', 'w.id', '=', 'ds.warehouse_id')
                ->where('ds.company_id', $context->companyId)
                ->where('ds.product_id', $productId)
                ->orderBy('ds.created_at')
                ->get(['ds.created_at', 'ds.reference_no', 'ds.qty', 'ds.unit_cost', 'ds.total_loss', 'ds.reason', 'ds.note', 'w.name as warehouse_name']);

            foreach ($damages as $dmg) {
                $dmgQty = abs((float) $dmg->qty);
                $events[] = [
                    'date' => substr($dmg->created_at, 0, 10),
                    'timestamp' => $dmg->created_at,
                    'stage' => 'adjustment',
                    'badge' => 'Damage / Scrap (-)',
                    'badge_class' => 'badge-adjustment-neg',
                    'icon' => 'dripicons-warning',
                    'title' => 'Damage / Scrap Written Off',
                    'ref_no' => $dmg->reference_no,
                    'party' => $dmg->reason ?: 'Damaged Goods',
                    'warehouse' => $dmg->warehouse_name ?: 'Main Godown',
                    'inward_qty' => 0.0,
                    'outward_qty' => $dmgQty,
                    'qty' => $dmgQty,
                    'unit' => $unit,
                    'cost' => (float) $dmg->unit_cost,
                    'total_value' => (float) $dmg->total_loss,
                    'description' => "Written off {$dmgQty} {$unit} as damage/scrap in {$dmg->warehouse_name}" . ($dmg->reason ? " ({$dmg->reason})" : ""),
                ];
            }
        }

        // Filter events strictly within requested date window
        $events = array_values(array_filter($events, function ($e) use ($from, $to) {
            return $e['date'] >= $from && $e['date'] <= $to;
        }));

        // Sort all events chronologically
        usort($events, fn ($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        // Compute running balance step-by-step
        $running = $openingGodownStock;
        foreach ($events as &$ev) {
            $running = $running + $ev['inward_qty'] - $ev['outward_qty'];
            $ev['running_balance'] = $running;
        }
        unset($ev);

        // Compute current live snapshot
        $hasQuarantine = Schema::hasColumn('warehouses', 'is_quarantine');
        $hasExtJwo = Schema::hasColumn('warehouses', 'external_job_order_id');

        $currentGodownQuery = DB::table('stock_movement_lines as l')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId);
        if ($hasQuarantine) $currentGodownQuery->where('w.is_quarantine', 0);
        if ($hasExtJwo) $currentGodownQuery->whereNull('w.external_job_order_id');
        $currentGodown = (float) $currentGodownQuery->sum('l.qty_base');

        $currentQuarantine = $hasQuarantine ? (float) DB::table('stock_movement_lines as l')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->where('w.is_quarantine', 1)
            ->sum('l.qty_base') : 0.0;

        $currentWip = $hasExtJwo ? (float) DB::table('stock_movement_lines as l')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.company_id', $context->companyId)
            ->where('l.product_id', $productId)
            ->whereNotNull('w.external_job_order_id')
            ->sum('l.qty_base') : 0.0;

        $totalInward = array_sum(array_column($events, 'inward_qty'));
        $totalOutward = array_sum(array_column($events, 'outward_qty'));
        $netMovement = $totalInward - $totalOutward;

        $summary = [
            'opening_stock' => $openingGodownStock,
            'total_purchased' => array_sum(array_map(fn ($e) => $e['stage'] === 'purchase' ? $e['qty'] : 0, $events)),
            'total_dispatched' => array_sum(array_map(fn ($e) => $e['stage'] === 'dispatch' ? $e['qty'] : 0, $events)),
            'total_returned' => array_sum(array_map(fn ($e) => in_array($e['stage'], ['return', 'conversion_in']) ? $e['qty'] : 0, $events)),
            'total_inward' => $totalInward,
            'total_outward' => $totalOutward,
            'net_movement' => $netMovement,
            'transaction_count' => count($events),
            'in_godown' => $currentGodown,
            'in_quarantine' => $currentQuarantine,
            'at_processors_wip' => $currentWip,
            'final_owned' => $currentGodown + $currentQuarantine + $currentWip,
            'unit' => $unit,
        ];

        // 7. Time-Series Chart Data Generation for Chart.js
        $chartDates = [];
        $chartBalances = [];
        $chartInwards = [];
        $chartOutwards = [];
        $chartCumulative = [];
        $chartAnnotations = [];
        $chartGodownSeries = [];
        $chartWipSeries = [];
        $chartInwardSeries = [];
        $chartReturnedSeries = [];
        $chartPointsMeta = [];

        // Baseline on from date
        $chartDates[] = $from;
        $chartBalances[] = round($openingGodownStock, 2);
        $chartInwards[] = 0;
        $chartOutwards[] = 0;
        $chartCumulative[] = round($openingGodownStock, 2);
        $chartAnnotations[] = 'Opening: ' . round($openingGodownStock, 2) . ' ' . $unit;
        $chartGodownSeries[] = round($openingGodownStock, 2);
        $chartWipSeries[] = 0.0;
        $chartInwardSeries[] = 0.0;
        $chartReturnedSeries[] = 0.0;
        $chartPointsMeta[] = [
            'date' => $from,
            'title' => 'Period Baseline',
            'stage' => 'baseline',
            'ref_no' => 'Period Start',
            'party' => 'Starting Balance',
            'movement' => round($openingGodownStock, 2),
            'qty_label' => round($openingGodownStock, 2) . ' ' . $unit,
            'qty_class' => 'text-primary',
            'godown_balance' => round($openingGodownStock, 2),
            'wip_balance' => 0.0,
            'why' => round($openingGodownStock, 2) . " {$unit} period opening balance",
        ];

        $cum = $openingGodownStock;
        $currentGodownTrack = $openingGodownStock;
        $currentWipTrack = 0.0;
        $cumPurchasedTrack = 0.0;
        $cumReturnedTrack = 0.0;

        foreach ($events as $ev) {
            $chartDates[] = $ev['date'];
            $chartBalances[] = round($ev['running_balance'], 2);
            $chartInwards[] = round($ev['inward_qty'], 2);
            $chartOutwards[] = round($ev['outward_qty'], 2);
            $cum += ($ev['inward_qty'] - $ev['outward_qty']);
            $chartCumulative[] = round($cum, 2);

            $tag = '';
            if ($ev['inward_qty'] > 0) $tag = '+' . round($ev['inward_qty'], 2);
            elseif ($ev['outward_qty'] > 0) $tag = '-' . round($ev['outward_qty'], 2);
            $chartAnnotations[] = $tag ? ($tag . ' ' . $unit) : ($ev['badge']);

            // Update process tracking
            $refStr = (!empty($ev['ref_no']) && $ev['ref_no'] !== '-') ? " ({$ev['ref_no']})" : "";
            if ($ev['stage'] === 'purchase') {
                $currentGodownTrack += $ev['inward_qty'];
                $cumPurchasedTrack += $ev['inward_qty'];
                $whyText = rtrim(rtrim(number_format($ev['inward_qty'], 4), '0'), '.') . " {$unit} purchased from {$ev['party']}{$refStr}";
            } elseif ($ev['stage'] === 'dispatch') {
                $currentGodownTrack -= $ev['outward_qty'];
                $currentWipTrack += $ev['outward_qty'];
                $whyText = rtrim(rtrim(number_format($ev['outward_qty'], 4), '0'), '.') . " {$unit} dispatched to {$ev['party']}{$refStr}";
            } elseif (in_array($ev['stage'], ['return', 'conversion_in'])) {
                $retQty = $ev['qty'];
                $currentWipTrack = max(0.0, $currentWipTrack - $retQty);
                if (empty($ev['converted_to'])) {
                    $currentGodownTrack += $retQty;
                }
                $cumReturnedTrack += $retQty;
                $convStr = !empty($ev['converted_to']) ? " (Converted to {$ev['converted_to']})" : "";
                $whyText = rtrim(rtrim(number_format($retQty, 4), '0'), '.') . " {$unit} returned from {$ev['party']}{$refStr}{$convStr}";
            } elseif ($ev['stage'] === 'sale') {
                $currentGodownTrack -= $ev['outward_qty'];
                $whyText = rtrim(rtrim(number_format($ev['outward_qty'], 4), '0'), '.') . " {$unit} sold to {$ev['party']}{$refStr}";
            } else {
                $currentGodownTrack += ($ev['inward_qty'] - $ev['outward_qty']);
                $whyText = rtrim(rtrim(number_format($ev['qty'], 4), '0'), '.') . " {$unit} adjusted in {$ev['warehouse']}{$refStr}";
            }

            $chartGodownSeries[] = round($ev['running_balance'], 2);
            $chartWipSeries[] = round($currentWipTrack, 2);
            $chartInwardSeries[] = round($cumPurchasedTrack, 2);
            $chartReturnedSeries[] = round($cumReturnedTrack, 2);

            $qtyLabel = '';
            $qtyClass = 'text-primary';
            if ($ev['stage'] === 'purchase') {
                $qtyLabel = '+' . rtrim(rtrim(number_format($ev['inward_qty'], 4), '0'), '.') . ' ' . $unit;
                $qtyClass = 'text-success';
            } elseif ($ev['stage'] === 'dispatch') {
                $qtyLabel = '-' . rtrim(rtrim(number_format($ev['outward_qty'], 4), '0'), '.') . ' ' . $unit;
                $qtyClass = 'text-danger';
            } elseif (in_array($ev['stage'], ['return', 'conversion_in'])) {
                $qtyLabel = '+' . rtrim(rtrim(number_format($ev['qty'], 4), '0'), '.') . ' ' . $unit;
                $qtyClass = 'text-success';
            } elseif ($ev['stage'] === 'sale') {
                $qtyLabel = '-' . rtrim(rtrim(number_format($ev['outward_qty'], 4), '0'), '.') . ' ' . $unit;
                $qtyClass = 'text-danger';
            } else {
                $qtyLabel = rtrim(rtrim(number_format($ev['qty'], 4), '0'), '.') . ' ' . $unit;
                $qtyClass = 'text-primary';
            }

            $chartPointsMeta[] = [
                'date' => $ev['date'],
                'title' => $ev['title'],
                'stage' => $ev['stage'],
                'ref_no' => $ev['ref_no'],
                'party' => $ev['party'],
                'movement' => $ev['inward_qty'] > 0 ? (+$ev['inward_qty']) : (-$ev['outward_qty']),
                'qty_label' => $qtyLabel,
                'qty_class' => $qtyClass,
                'godown_balance' => round($ev['running_balance'], 2),
                'wip_balance' => round($currentWipTrack, 2),
                'why' => $whyText,
            ];
        }

        // If to date is after last event date, plot closing node
        if (end($chartDates) !== $to) {
            $chartDates[] = $to;
            $chartBalances[] = round($running, 2);
            $chartInwards[] = 0;
            $chartOutwards[] = 0;
            $chartCumulative[] = round($cum, 2);
            $chartAnnotations[] = 'Closing: ' . round($running, 2) . ' ' . $unit;
            $chartGodownSeries[] = round($running, 2);
            $chartWipSeries[] = round($currentWipTrack, 2);
            $chartInwardSeries[] = round($cumPurchasedTrack, 2);
            $chartReturnedSeries[] = round($cumReturnedTrack, 2);
            $chartPointsMeta[] = [
                'date' => $to,
                'title' => 'Closing Reconciled Position',
                'stage' => 'closing',
                'ref_no' => 'Reconciled',
                'party' => 'Main Godown',
                'movement' => round($running, 2),
                'qty_label' => rtrim(rtrim(number_format($running, 4), '0'), '.') . ' ' . $unit,
                'qty_class' => 'text-primary font-weight-bold',
                'godown_balance' => round($running, 2),
                'wip_balance' => round($currentWipTrack, 2),
                'why' => rtrim(rtrim(number_format($running, 4), '0'), '.') . " {$unit} current stock in hand",
            ];
        }

        $chart = [
            'labels' => $chartDates,
            'balances' => $chartBalances,
            'inwards' => $chartInwards,
            'outwards' => $chartOutwards,
            'cumulative' => $chartCumulative,
            'annotations' => $chartAnnotations,
            'godown_series' => $chartGodownSeries,
            'wip_series' => $chartWipSeries,
            'inward_series' => $chartInwardSeries,
            'returned_series' => $chartReturnedSeries,
            'points_meta' => $chartPointsMeta,
        ];

        // 8. Daily Summary Table Aggregation
        $dailyGrouped = [];
        foreach ($events as $ev) {
            $d = $ev['date'];
            if (!isset($dailyGrouped[$d])) {
                $dailyGrouped[$d] = [
                    'date' => $d,
                    'inward' => 0.0,
                    'outward' => 0.0,
                    'tx_count' => 0,
                    'closing' => $ev['running_balance'],
                ];
            }
            $dailyGrouped[$d]['inward'] += $ev['inward_qty'];
            $dailyGrouped[$d]['outward'] += $ev['outward_qty'];
            $dailyGrouped[$d]['tx_count']++;
            $dailyGrouped[$d]['closing'] = $ev['running_balance'];
        }
        $dailySummary = array_values($dailyGrouped);

        // 9. Movement Summary Aggregation
        $moveGrouped = [];
        foreach ($events as $ev) {
            $st = $ev['stage'];
            if (!isset($moveGrouped[$st])) {
                $moveGrouped[$st] = [
                    'stage' => $st,
                    'badge' => $ev['badge'],
                    'badge_class' => $ev['badge_class'],
                    'icon' => $ev['icon'],
                    'count' => 0,
                    'inward' => 0.0,
                    'outward' => 0.0,
                ];
            }
            $moveGrouped[$st]['count']++;
            $moveGrouped[$st]['inward'] += $ev['inward_qty'];
            $moveGrouped[$st]['outward'] += $ev['outward_qty'];
        }
        $movementSummary = array_values($moveGrouped);

        // 10. Horizontal Connected Process Lifecycle Stages & Milestones (Combined Sleek Pipeline)
        $firstPurchase = collect($events)->where('stage', 'purchase')->first();
        $firstDispatch = collect($events)->where('stage', 'dispatch')->first();
        $firstReturn = collect($events)->whereIn('stage', ['return', 'conversion_in'])->first();

        $lifecycleStages = [
            [
                'step' => 1,
                'key' => 'purchased',
                'title' => '1. Raw Material Inward',
                'label' => 'Purchased Inward',
                'qty' => $summary['total_purchased'],
                'unit' => $unit,
                'status' => $summary['total_purchased'] > 0 ? 'completed' : 'pending',
                'status_label' => $summary['total_purchased'] > 0 ? 'Inward Complete' : 'No Purchase',
                'icon' => 'dripicons-download',
                'tone' => 'z-tone-success',
                'badge_class' => 'badge-success',
                'subtext' => 'Direct vendor receipts',
                'date' => $firstPurchase['date'] ?? null,
                'ref_no' => $firstPurchase['ref_no'] ?? null,
                'party' => $firstPurchase['party'] ?? null,
            ],
            [
                'step' => 2,
                'key' => 'dispatched',
                'title' => '2. Sent to Job Work',
                'label' => 'Dispatched (DC)',
                'qty' => $summary['total_dispatched'],
                'unit' => $unit,
                'status' => $summary['total_dispatched'] > 0 ? 'completed' : 'pending',
                'status_label' => $summary['total_dispatched'] > 0 ? 'Dispatched' : 'In Godown Only',
                'icon' => 'dripicons-export',
                'tone' => 'z-tone-warning',
                'badge_class' => 'badge-warning',
                'subtext' => 'Delivery challans issued',
                'date' => $firstDispatch['date'] ?? null,
                'ref_no' => $firstDispatch['ref_no'] ?? null,
                'party' => $firstDispatch['party'] ?? null,
            ],
            [
                'step' => 3,
                'key' => 'wip',
                'title' => '3. At Processors (WIP)',
                'label' => 'Processor WIP',
                'qty' => $summary['at_processors_wip'],
                'unit' => $unit,
                'status' => $summary['at_processors_wip'] > 0 ? 'active' : 'completed',
                'status_label' => $summary['at_processors_wip'] > 0 ? 'Processing Active' : 'All Returned',
                'icon' => 'dripicons-gear',
                'tone' => 'z-tone-info',
                'badge_class' => $summary['at_processors_wip'] > 0 ? 'badge-info' : 'badge-secondary',
                'subtext' => 'Pending with workers',
                'date' => $summary['at_processors_wip'] > 0 ? 'In Progress' : 'Completed',
                'ref_no' => $firstDispatch['jwo_ref'] ?? ($firstDispatch['ref_no'] ?? null),
                'party' => $firstDispatch['party'] ?? null,
            ],
            [
                'step' => 4,
                'key' => 'returned',
                'title' => '4. Returned & Converted',
                'label' => 'Returned Goods',
                'qty' => $summary['total_returned'],
                'unit' => $unit,
                'status' => $summary['total_returned'] > 0 ? 'completed' : 'pending',
                'status_label' => $summary['total_returned'] > 0 ? 'Received & Stocked' : 'Awaiting Return',
                'icon' => 'dripicons-checkmark',
                'tone' => 'z-tone-success',
                'badge_class' => 'badge-success',
                'subtext' => !empty($convertedToProducts) ? 'Converted & Named' : 'Processed Goods',
                'date' => $firstReturn['date'] ?? null,
                'ref_no' => $firstReturn['ref_no'] ?? null,
                'party' => $firstReturn['party'] ?? null,
            ],
            [
                'step' => 5,
                'key' => 'stock',
                'title' => '5. Available Ready Stock',
                'label' => 'Ready in Godown',
                'qty' => $summary['in_godown'],
                'unit' => $unit,
                'status' => $summary['in_godown'] > 0 ? 'completed' : 'low',
                'status_label' => $summary['in_godown'] > 0 ? 'Ready for Sale / Issue' : 'Nil Balance',
                'icon' => 'dripicons-box',
                'tone' => 'z-tone-primary',
                'badge_class' => 'badge-primary',
                'subtext' => 'Owned physical stock',
                'date' => $to,
                'ref_no' => 'Reconciled',
                'party' => 'Main Godown',
            ],
        ];

        return [
            'product' => $product,
            'events' => $events,
            'summary' => $summary,
            'chart' => $chart,
            'dailySummary' => $dailySummary,
            'movementSummary' => $movementSummary,
            'lifecycleStages' => $lifecycleStages,
            'convertedToProducts' => array_values($convertedToProducts),
            'convertedFromProducts' => array_values($convertedFromProducts),
            'filters' => $filters,
        ];
    }

    /**
     * Build rich interactive conversion and lifecycle tree nodes for products
     */
    public function conversionLifecycleTree(array $filters, CompanyContext $context, int $actor): array
    {
        $this->posting->authorize('core.inventory', 'products-index', $context, $actor);

        $stockData = $this->simplifiedStock($filters, $context, $actor);
        $rows = $stockData['rows'];
        $to = CarbonImmutable::parse($filters['to'] ?? now()->toDateString())->addDay()->toDateString();

        // 1. All conversions from job_work_receipts details_json (stock_name_change)
        $convJsonReceipts = DB::table('job_work_receipts as r')
            ->leftJoin('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
            ->leftJoin('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
            ->where('r.company_id', $context->companyId)
            ->whereNull('r.reversed_at')
            ->where('r.details_json', 'like', '%"stock_name_change"%')
            ->get(['r.id', 'r.reference_no', 'r.details_json', 'r.business_date', 's.name as supplier_name']);

        $conversionsTo = [];
        $conversionsFrom = [];

        foreach ($convJsonReceipts as $cr) {
            $change = json_decode($cr->details_json ?? '{}', true)['stock_name_change'] ?? null;
            if (!$change) continue;
            foreach ($change['items'] ?? [] as $item) {
                $fromPid = (int) $item['from_product_id'];
                $toPid = (int) $item['to_product_id'];
                $qty = (float) $item['qty'];
                $date = $change['date'] ?? $cr->business_date;
                $ref = $cr->reference_no . (!empty($change['purchase_no']) ? ' / ' . $change['purchase_no'] : '');
                $processor = $cr->supplier_name ?: 'Cauvery Dyeing & Sizing Works';

                $conversionsTo[$fromPid][] = [
                    'to_id' => $toPid,
                    'to_name' => $item['to_name'],
                    'qty' => $qty,
                    'ref' => $ref,
                    'date' => $date,
                    'processor' => $processor,
                    'source' => 'stock_name_change',
                ];

                $conversionsFrom[$toPid][] = [
                    'from_id' => $fromPid,
                    'from_name' => $item['from_name'],
                    'qty' => $qty,
                    'ref' => $ref,
                    'date' => $date,
                    'processor' => $processor,
                    'source' => 'stock_name_change',
                ];
            }
        }

        // 2. All conversions from paired stock_movements (source_type = 'job_work_receipts')
        $consumed = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('job_work_receipts as r', 'r.id', '=', 'm.source_id')
            ->leftJoin('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
            ->leftJoin('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('l.qty_base', '<', 0)
            ->select('m.source_id as receipt_id', 'l.product_id', 'p.name', 'p.code', 'l.qty_base', 'm.source_no', 'm.movement_date', 's.name as supplier_name')
            ->get()
            ->groupBy('receipt_id');

        $produced = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('l.qty_base', '>', 0)
            ->select('m.source_id as receipt_id', 'l.product_id', 'p.name', 'p.code', 'l.qty_base', 'm.source_no', 'm.movement_date')
            ->get()
            ->groupBy('receipt_id');

        foreach ($consumed as $receiptId => $rawList) {
            $convList = $produced->get($receiptId);
            if (!$convList) continue;
            foreach ($rawList as $raw) {
                foreach ($convList as $conv) {
                    if ($raw->product_id == $conv->product_id) continue;
                    $fromPid = (int) $raw->product_id;
                    $toPid = (int) $conv->product_id;
                    $qty = abs((float) $conv->qty_base);
                    $ref = $raw->source_no;
                    $date = substr($raw->movement_date, 0, 10);
                    $processor = $raw->supplier_name ?: 'Cauvery Dyeing & Sizing Works';

                    $existingTo = collect($conversionsTo[$fromPid] ?? [])->firstWhere('to_id', $toPid);
                    if (!$existingTo) {
                        $conversionsTo[$fromPid][] = [
                            'to_id' => $toPid,
                            'to_name' => $conv->name,
                            'qty' => $qty,
                            'ref' => $ref,
                            'date' => $date,
                            'processor' => $processor,
                            'source' => 'paired_movements',
                        ];
                    }

                    $existingFrom = collect($conversionsFrom[$toPid] ?? [])->firstWhere('from_id', $fromPid);
                    if (!$existingFrom) {
                        $conversionsFrom[$toPid][] = [
                            'from_id' => $fromPid,
                            'from_name' => $raw->name,
                            'qty' => $qty,
                            'ref' => $ref,
                            'date' => $date,
                            'processor' => $processor,
                            'source' => 'paired_movements',
                        ];
                    }
                }
            }
        }

        // 3. Transactions for branches (purchases, dispatches)
        $purchasesByProduct = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('purchases as p', function ($join) {
                $join->on('p.id', '=', 'm.source_id')->where('m.source_type', '=', 'purchase');
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('l.company_id', $context->companyId)
            ->where('m.company_id', $context->companyId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('l.qty_base', '>', 0)
            ->where(function ($q) {
                $q->where('m.movement_type', 'purchase')->orWhere('m.source_type', 'purchase');
            })
            ->select([
                'l.product_id',
                'm.source_no as ref_no',
                'm.movement_date',
                'l.qty_base as qty',
                's.name as supplier_name',
            ])
            ->get()
            ->groupBy('product_id');

        $dispatchesByProduct = DB::table('job_work_dispatch_lines as dl')
            ->join('job_work_dispatches as d', 'd.id', '=', 'dl.dispatch_id')
            ->join('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
            ->where('d.company_id', $context->companyId)
            ->whereNull('d.reversed_at')
            ->select([
                'dl.product_id',
                'd.reference_no as dispatch_ref',
                'o.reference_no as order_ref',
                'd.business_date',
                'dl.qty_base as qty',
                's.name as processor_name',
            ])
            ->get()
            ->groupBy('product_id');

        $nodes = [];

        foreach ($rows as $r) {
            $pid = $r->product_id;
            $handle = $r->handle ?? '';
            if (!$handle) {
                if (preg_match('/\(([^)]+)\)$/', $r->product, $matches)) {
                    $handle = $matches[1];
                } elseif (preg_match('/-([A-Z0-9]+)$/', $r->product_code ?? '', $matches)) {
                    $handle = $matches[1];
                } else {
                    $handle = $r->product_code ?: 'MAIN';
                }
            }

            $cTo = $conversionsTo[$pid] ?? [];
            $cFrom = $conversionsFrom[$pid] ?? [];

            $category = 'standard';
            $statusBadge = 'In Godown Ready';
            $statusClass = 'badge-primary';

            if (!empty($cTo)) {
                $category = 'converted_parent';
                $statusBadge = 'Converted ➔ ' . $cTo[0]['to_name'];
                $statusClass = 'badge-success';
            } elseif (!empty($cFrom)) {
                $category = 'converted_child';
                $statusBadge = 'Produced from ➔ ' . $cFrom[0]['from_name'];
                $statusClass = 'badge-info';
            } elseif ($r->at_job_workers_qty > 0) {
                $category = 'wip_processing';
                $statusBadge = 'In Job Work (WIP)';
                $statusClass = 'badge-warning';
            }

            // Build branches
            $branches = [];

            // Branch 1: Inward Purchases
            $pList = $purchasesByProduct->get($pid, collect());
            if ($pList->isNotEmpty() || $r->purchased_qty > 0) {
                $firstP = $pList->first();
                $branches[] = [
                    'type' => 'inward',
                    'icon' => 'dripicons-download',
                    'tone' => 'z-tone-primary',
                    'title' => 'Purchased Inward',
                    'qty' => $r->purchased_qty,
                    'summary' => $firstP ? "Purchased {$firstP->qty} {$r->base_unit} from " . ($firstP->supplier_name ?: 'Supplier') . " ({$firstP->ref_no}) on " . substr($firstP->movement_date, 0, 10) : "Total Purchased: {$r->purchased_qty} {$r->base_unit}",
                    'items' => $pList->map(fn($p) => [
                        'ref' => $p->ref_no,
                        'party' => $p->supplier_name ?: 'Supplier',
                        'date' => substr($p->movement_date, 0, 10),
                        'qty' => (float) $p->qty,
                    ])->all(),
                ];
            }

            // Branch 2: Dispatched to Job Work
            $dList = $dispatchesByProduct->get($pid, collect());
            if ($dList->isNotEmpty() || $r->given_to_job_work_qty > 0) {
                $firstD = $dList->first();
                $branches[] = [
                    'type' => 'dispatch',
                    'icon' => 'dripicons-export',
                    'tone' => 'z-tone-warning',
                    'title' => 'Dispatched to Processor (DC)',
                    'qty' => $r->given_to_job_work_qty,
                    'summary' => $firstD ? "Dispatched {$firstD->qty} {$r->base_unit} to " . ($firstD->processor_name ?: 'Job Worker') . " via {$firstD->dispatch_ref} ({$firstD->order_ref}) on {$firstD->business_date}" : "Total Sent to Processors: {$r->given_to_job_work_qty} {$r->base_unit}",
                    'items' => $dList->map(fn($d) => [
                        'ref' => $d->dispatch_ref,
                        'order' => $d->order_ref,
                        'party' => $d->processor_name ?: 'Job Worker',
                        'date' => $d->business_date,
                        'qty' => (float) $d->qty,
                    ])->all(),
                ];
            }

            // Branch 3: Conversion Transformation
            if (!empty($cTo)) {
                $firstC = $cTo[0];
                $branches[] = [
                    'type' => 'conversion_out',
                    'icon' => 'dripicons-swap',
                    'tone' => 'z-tone-success',
                    'title' => 'Converted to Finished Good',
                    'qty' => $firstC['qty'],
                    'summary' => "Transformed into {$firstC['to_name']} ({$firstC['qty']} {$r->base_unit}) at {$firstC['processor']} via {$firstC['ref']} on {$firstC['date']}",
                    'target_id' => $firstC['to_id'],
                    'target_name' => $firstC['to_name'],
                    'items' => $cTo,
                ];
            } elseif (!empty($cFrom)) {
                $firstC = $cFrom[0];
                $branches[] = [
                    'type' => 'conversion_in',
                    'icon' => 'dripicons-swap',
                    'tone' => 'z-tone-info',
                    'title' => 'Produced from Raw Material',
                    'qty' => $firstC['qty'],
                    'summary' => "Received from raw material {$firstC['from_name']} ({$firstC['qty']} {$r->base_unit}) via {$firstC['ref']} on {$firstC['date']}",
                    'target_id' => $firstC['from_id'],
                    'target_name' => $firstC['from_name'],
                    'items' => $cFrom,
                ];
            }

            // Branch 4: Live Godown Stock & Sales
            $branches[] = [
                'type' => 'balance_sales',
                'icon' => 'dripicons-home',
                'tone' => 'z-tone-info',
                'title' => 'Current Status & Output',
                'qty' => $r->final_stock_qty,
                'summary' => "Godown In-Hand: {$r->in_godown_qty} {$r->base_unit} · Processor WIP: {$r->at_job_workers_qty} {$r->base_unit} · Sold: {$r->sold_qty} {$r->base_unit}",
                'in_godown' => $r->in_godown_qty,
                'at_processors' => $r->at_job_workers_qty,
                'sold' => $r->sold_qty,
            ];

            $nodes[] = [
                'product_id' => $pid,
                'product_name' => $r->product,
                'product_code' => $r->product_code,
                'plc' => $handle,
                'handle' => $handle,
                'base_unit' => $r->base_unit,
                'category' => $category,
                'status_badge' => $statusBadge,
                'status_class' => $statusClass,
                'purchased_qty' => $r->purchased_qty,
                'dispatched_qty' => $r->given_to_job_work_qty,
                'returned_qty' => $r->returned_after_job_work_qty,
                'in_godown_qty' => $r->in_godown_qty,
                'at_job_workers_qty' => $r->at_job_workers_qty,
                'sold_qty' => $r->sold_qty,
                'final_stock_qty' => $r->final_stock_qty,
                'conversions_to' => $cTo,
                'conversions_from' => $cFrom,
                'branches' => $branches,
            ];
        }

        // Build distinct connected transformation flows (Raw Material ➔ Job Work Process ➔ Converted Good)
        $flowsByPlc = [];
        $nodesByPlc = collect($nodes)->groupBy('plc');

        foreach ($nodesByPlc as $plc => $groupNodes) {
            $rawNode = $groupNodes->first(fn ($n) => !empty($n['conversions_to']) || $n['purchased_qty'] > 0 || $n['dispatched_qty'] > 0) ?: $groupNodes->first();
            $convertedNode = $groupNodes->first(fn ($n) => !empty($n['conversions_from']) || $n['category'] === 'converted_child');

            $convDetails = !empty($rawNode['conversions_to']) ? $rawNode['conversions_to'][0] : (!empty($convertedNode['conversions_from']) ? $convertedNode['conversions_from'][0] : null);

            $inwardBranch = collect($rawNode['branches'] ?? [])->firstWhere('type', 'inward');
            $dispatchBranch = collect($rawNode['branches'] ?? [])->firstWhere('type', 'dispatch');

            $firstInwardItem = !empty($inwardBranch['items']) ? $inwardBranch['items'][0] : null;
            $firstDispatchItem = !empty($dispatchBranch['items']) ? $dispatchBranch['items'][0] : null;

            $dispatchedQty = (float) ($rawNode['dispatched_qty'] ?? 0);
            $convertedQty = $convDetails ? (float) $convDetails['qty'] : 0.0;
            $lossQty = max(0.0, $dispatchedQty - $convertedQty);
            $lossPct = $dispatchedQty > 0 ? round(($lossQty / $dispatchedQty) * 100, 1) : 0.0;

            $isConverted = $convertedNode !== null || $convDetails !== null;
            $isWip = !$isConverted && ($rawNode['at_job_workers_qty'] ?? 0) > 0;

            $category = $isConverted ? 'converted' : ($isWip ? 'wip' : 'ready');

            $flowsByPlc[] = [
                'id' => 'flow-' . preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($plc)),
                'plc' => $plc,
                'category' => $category,
                'title' => $isConverted ? ($rawNode['product_name'] . ' ➔ ' . ($convertedNode ? $convertedNode['product_name'] : $convDetails['to_name'])) : $rawNode['product_name'],
                'unit' => $rawNode['base_unit'],
                'is_converted' => $isConverted,
                'is_wip' => $isWip,
                // Node 1: Input / Raw Material
                'raw_id' => $rawNode['product_id'],
                'raw_name' => $rawNode['product_name'],
                'raw_code' => $rawNode['product_code'],
                'raw_inward_qty' => (float) ($rawNode['purchased_qty'] ?? 0),
                'raw_godown_qty' => (float) ($rawNode['in_godown_qty'] ?? 0),
                'raw_doc' => $firstInwardItem['ref'] ?? 'Direct Inward',
                'raw_date' => $firstInwardItem['date'] ?? null,
                'raw_supplier' => $firstInwardItem['party'] ?? 'Supplier',
                // Node 2: Transformation / Job Work Process
                'process_party' => $convDetails['processor'] ?? ($firstDispatchItem['party'] ?? ($isWip ? 'Cauvery Dyeing & Sizing Works' : 'In-House Warehouse')),
                'process_dispatch_ref' => $firstDispatchItem['ref'] ?? ($convDetails['ref'] ?? 'JWDC-00000'),
                'process_receipt_ref' => $convDetails['ref'] ?? null,
                'process_date' => $convDetails['date'] ?? ($firstDispatchItem['date'] ?? null),
                'dispatched_qty' => $dispatchedQty,
                'converted_qty' => $convertedQty,
                'loss_qty' => $lossQty,
                'loss_pct' => $lossPct,
                // Node 3: Output / Converted Finished Good
                'converted_id' => $convertedNode['product_id'] ?? ($convDetails['to_id'] ?? null),
                'converted_name' => $convertedNode['product_name'] ?? ($convDetails['to_name'] ?? 'Ready Stock'),
                'converted_code' => $convertedNode['product_code'] ?? null,
                'converted_inward_qty' => $convertedQty,
                'converted_godown_qty' => (float) ($convertedNode['in_godown_qty'] ?? $convertedQty),
                'converted_sold_qty' => (float) ($convertedNode['sold_qty'] ?? 0),
            ];
        }

        // Sort: Converted parents and WIP first, then by activity volume descending
        usort($nodes, function ($a, $b) {
            $scoreA = ($a['category'] === 'converted_parent' ? 1000 : ($a['category'] === 'wip_processing' ? 500 : 0)) + $a['purchased_qty'] + $a['dispatched_qty'] + $a['final_stock_qty'];
            $scoreB = ($b['category'] === 'converted_parent' ? 1000 : ($b['category'] === 'wip_processing' ? 500 : 0)) + $b['purchased_qty'] + $b['dispatched_qty'] + $b['final_stock_qty'];
            return $scoreB <=> $scoreA;
        });

        // Sort flows: Converted first, then WIP, then volume
        usort($flowsByPlc, function ($a, $b) {
            $scoreA = ($a['is_converted'] ? 1000 : ($a['is_wip'] ? 500 : 0)) + $a['raw_inward_qty'] + $a['dispatched_qty'] + $a['converted_godown_qty'];
            $scoreB = ($b['is_converted'] ? 1000 : ($b['is_wip'] ? 500 : 0)) + $b['raw_inward_qty'] + $b['dispatched_qty'] + $b['converted_godown_qty'];
            return $scoreB <=> $scoreA;
        });

        $recentNodes = array_slice($nodes, 0, 10);
        $recentFlows = array_slice($flowsByPlc, 0, 10);
        $distinctHandles = array_values(array_unique(array_filter(array_column($nodes, 'handle'))));

        return [
            'nodes' => $nodes,
            'recent_nodes' => $recentNodes,
            'transformation_flows' => $flowsByPlc,
            'recent_flows' => $recentFlows,
            'total_count' => count($nodes),
            'categories' => [
                'all' => count($flowsByPlc),
                'converted' => count(array_filter($flowsByPlc, fn ($f) => $f['is_converted'])),
                'wip' => count(array_filter($flowsByPlc, fn ($f) => $f['is_wip'])),
                'ready' => count(array_filter($flowsByPlc, fn ($f) => !$f['is_converted'] && !$f['is_wip'])),
            ],
            'distinct_plcs' => $distinctHandles,
            'distinct_handles' => $distinctHandles,
            'filters' => $filters,
        ];
    }

    /**
     * Get list of products with active lifecycle in the company.
     */
    public function productsWithLifecycle(array $filters, CompanyContext $context, int $actor): array
    {
        $this->posting->authorize('core.inventory', 'products-index', $context, $actor);

        $stockData = $this->simplifiedStock($filters, $context, $actor);
        $rows = $stockData['rows'];

        // Conversions from job_work_receipts details_json (stock_name_change)
        $convJsonReceipts = DB::table('job_work_receipts as r')
            ->leftJoin('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
            ->leftJoin('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
            ->where('r.company_id', $context->companyId)
            ->whereNull('r.reversed_at')
            ->where('r.details_json', 'like', '%"stock_name_change"%')
            ->get(['r.id', 'r.reference_no', 'r.details_json', 'r.business_date', 's.name as supplier_name']);

        $conversionsTo = [];
        $conversionsFrom = [];

        foreach ($convJsonReceipts as $cr) {
            $change = json_decode($cr->details_json ?? '{}', true)['stock_name_change'] ?? null;
            if (!$change) continue;
            foreach ($change['items'] ?? [] as $item) {
                $fromPid = (int) $item['from_product_id'];
                $toPid = (int) $item['to_product_id'];
                $qty = (float) $item['qty'];
                $date = $change['date'] ?? $cr->business_date;
                $ref = $cr->reference_no . (!empty($change['purchase_no']) ? ' / ' . $change['purchase_no'] : '');
                $processor = $cr->supplier_name ?: 'Cauvery Dyeing & Sizing Works';

                $conversionsTo[$fromPid][] = [
                    'to_id' => $toPid,
                    'to_name' => $item['to_name'],
                    'qty' => $qty,
                    'ref' => $ref,
                    'date' => $date,
                    'processor' => $processor,
                ];

                $conversionsFrom[$toPid][] = [
                    'from_id' => $fromPid,
                    'from_name' => $item['from_name'],
                    'qty' => $qty,
                    'ref' => $ref,
                    'date' => $date,
                    'processor' => $processor,
                ];
            }
        }

        // Conversions from paired stock movements (source_type = 'job_work_receipts')
        $consumed = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('job_work_receipts as r', 'r.id', '=', 'm.source_id')
            ->leftJoin('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
            ->leftJoin('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('l.qty_base', '<', 0)
            ->select('m.source_id as receipt_id', 'l.product_id', 'p.name', 'l.qty_base', 'm.source_no', 'm.movement_date', 's.name as supplier_name')
            ->get()
            ->groupBy('receipt_id');

        $produced = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('l.qty_base', '>', 0)
            ->select('m.source_id as receipt_id', 'l.product_id', 'p.name', 'l.qty_base', 'm.source_no', 'm.movement_date')
            ->get()
            ->groupBy('receipt_id');

        foreach ($consumed as $receiptId => $rawList) {
            $convList = $produced->get($receiptId);
            if (!$convList) continue;
            foreach ($rawList as $raw) {
                foreach ($convList as $conv) {
                    if ($raw->product_id == $conv->product_id) continue;
                    $fromPid = (int) $raw->product_id;
                    $toPid = (int) $conv->product_id;
                    $qty = abs((float) $conv->qty_base);

                    if (!collect($conversionsTo[$fromPid] ?? [])->firstWhere('to_id', $toPid)) {
                        $conversionsTo[$fromPid][] = [
                            'to_id' => $toPid,
                            'to_name' => $conv->name,
                            'qty' => $qty,
                            'ref' => $raw->source_no,
                            'date' => substr($raw->movement_date, 0, 10),
                            'processor' => $raw->supplier_name ?: 'Cauvery Dyeing & Sizing Works',
                        ];
                    }
                    if (!collect($conversionsFrom[$toPid] ?? [])->firstWhere('from_id', $fromPid)) {
                        $conversionsFrom[$toPid][] = [
                            'from_id' => $fromPid,
                            'from_name' => $raw->name,
                            'qty' => $qty,
                            'ref' => $raw->source_no,
                            'date' => substr($raw->movement_date, 0, 10),
                            'processor' => $raw->supplier_name ?: 'Cauvery Dyeing & Sizing Works',
                        ];
                    }
                }
            }
        }

        $catalog = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $handle = $r->handle ?? '';
            if (!$handle) {
                if (preg_match('/\(([^)]+)\)$/', $r->product, $matches)) {
                    $handle = $matches[1];
                } elseif (preg_match('/-([A-Z0-9]+)$/', $r->product_code ?? '', $matches)) {
                    $handle = $matches[1];
                } else {
                    $handle = $r->product_code ?: 'MAIN';
                }
            }

            $cTo = $conversionsTo[$pid] ?? [];
            $cFrom = $conversionsFrom[$pid] ?? [];

            $isConvertedParent = !empty($cTo);
            $isConvertedChild = !empty($cFrom);
            $isWip = !$isConvertedParent && !$isConvertedChild && ($r->at_job_workers_qty > 0 || $r->given_to_job_work_qty > 0);

            // Keep products with movements, purchases, dispatches, or conversions
            if ($r->purchased_qty <= 0 && $r->given_to_job_work_qty <= 0 && $r->final_stock_qty <= 0 && !$isConvertedParent && !$isConvertedChild) {
                continue;
            }

            $category = $isConvertedParent ? 'converted' : ($isWip ? 'wip' : ($isConvertedChild ? 'converted_child' : 'ready'));
            $statusLabel = $isConvertedParent ? ('Converted ➔ ' . $cTo[0]['to_name'])
                : ($isConvertedChild ? ('Produced from ➔ ' . $cFrom[0]['from_name'])
                : ($isWip ? 'In Job Work (WIP)' : 'Ready Godown Stock'));

            $catalog[] = [
                'product_id' => $pid,
                'product_name' => $r->product,
                'product_code' => $r->product_code,
                'plc' => $handle,
                'base_unit' => $r->base_unit,
                'purchased_qty' => (float) $r->purchased_qty,
                'dispatched_qty' => (float) $r->given_to_job_work_qty,
                'returned_qty' => (float) $r->returned_after_job_work_qty,
                'in_godown_qty' => (float) $r->in_godown_qty,
                'at_job_workers_qty' => (float) $r->at_job_workers_qty,
                'sold_qty' => (float) ($r->sold_qty ?? 0),
                'final_stock_qty' => (float) $r->final_stock_qty,
                'category' => $category,
                'status_label' => $statusLabel,
                'has_conversions' => $isConvertedParent || $isConvertedChild,
                'conversions_to' => $cTo,
                'conversions_from' => $cFrom,
            ];
        }

        // Sort: Converted parents first (root products), then WIP, then volume descending
        usort($catalog, function ($a, $b) {
            $scoreA = ($a['category'] === 'converted' ? 2000 : ($a['category'] === 'wip' ? 1000 : 0)) + $a['purchased_qty'] + $a['dispatched_qty'] + $a['final_stock_qty'];
            $scoreB = ($b['category'] === 'converted' ? 2000 : ($b['category'] === 'wip' ? 1000 : 0)) + $b['purchased_qty'] + $b['dispatched_qty'] + $b['final_stock_qty'];
            return $scoreB <=> $scoreA;
        });

        $distinctPlcs = array_values(array_unique(array_filter(array_column($catalog, 'plc'))));

        return [
            'products' => $catalog,
            'distinct_plcs' => $distinctPlcs,
            'total_count' => count($catalog),
        ];
    }

    /**
     * Build the 3-level branching lifecycle tree for a single root product.
     */
    public function productLifecycleTree(int $productId, array $filters, CompanyContext $context, int $actor): array
    {
        $this->posting->authorize('core.inventory', 'products-index', $context, $actor);

        $product = Product::forCompany($context)->findOrFail($productId);
        $baseUnit = DB::table('units')->where('id', $product->unit_id)->value('unit_name') ?: 'MTR';

        $plc = '';
        if (preg_match('/\(([^)]+)\)$/', $product->name, $matches)) {
            $plc = $matches[1];
        } elseif (preg_match('/-([A-Z0-9]+)$/', $product->code ?? '', $matches)) {
            $plc = $matches[1];
        } else {
            $plc = $product->code ?: 'MAIN';
        }

        // 0. Warehouses (Internal Godowns for accurate stock)
        $whSchema = Schema::getColumnListing('warehouses');
        $hasQuarantine = in_array('is_quarantine', $whSchema);
        $hasExtJwo = in_array('external_job_order_id', $whSchema);
        $whColumns = ['id', 'name'];
        if ($hasQuarantine) $whColumns[] = 'is_quarantine';
        if ($hasExtJwo) $whColumns[] = 'external_job_order_id';

        $warehouses = DB::table('warehouses')
            ->where('company_id', $context->companyId);
        if ($context->branchId) {
            $warehouses->where(function ($q) use ($context) {
                $q->where('branch_id', $context->branchId)
                  ->orWhereNull('branch_id');
            });
        }
        if (in_array('is_active', $whSchema)) {
            $warehouses->where(fn ($q) => $q->where('is_active', 1)->orWhereNull('is_active'));
        }
        $warehouses = $warehouses->get($whColumns);

        $internalWhIds = $warehouses->filter(fn ($w) => empty($w->is_quarantine ?? null) && empty($w->external_job_order_id ?? null))->pluck('id')->all();
        $defaultWarehouseObj = $warehouses->first(fn ($w) => empty($w->is_quarantine ?? null) && empty($w->external_job_order_id ?? null));
        $defaultWarehouse = $defaultWarehouseObj ? $defaultWarehouseObj->name : 'Main Godown';

        // 1. Inward Purchases / Inward Opening
        $purchases = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->leftJoin('purchases as p', function ($join) {
                $join->on('p.id', '=', 'm.source_id')->where('m.source_type', '=', 'purchase');
            })
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('l.company_id', $context->companyId)
            ->where('m.company_id', $context->companyId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('m.movement_type', '!=', 'reversal')
            ->whereNull('m.reversal_of_id')
            ->where('l.product_id', $productId)
            ->where('l.qty_base', '>', 0)
            ->when(!empty($internalWhIds), function ($q) use ($internalWhIds) {
                $q->whereIn('l.warehouse_id', $internalWhIds);
            })
            ->where(function ($q) {
                $q->where('m.movement_type', 'purchase')
                  ->orWhere('m.source_type', 'purchase')
                  ->orWhereIn('m.movement_type', ['opening', 'initial_stock']);
            })
            ->orderBy('m.movement_date')
            ->select([
                'm.source_no as ref_no',
                'm.movement_date',
                'l.qty_base as qty',
                's.name as supplier_name',
            ])
            ->get();

        $totalPurchased = (float) $purchases->sum('qty');
        if ($totalPurchased <= 0) {
            $openingStock = (float) DB::table('stock_movement_lines as l')
                ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
                ->where('l.company_id', $context->companyId)
                ->where('m.company_id', $context->companyId)
                ->where('m.projection_mode', 'applied')
                ->where('m.status', 'posted')
                ->where('m.movement_type', '!=', 'reversal')
                ->whereNull('m.reversal_of_id')
                ->where('l.product_id', $productId)
                ->where('l.qty_base', '>', 0)
                ->when(!empty($internalWhIds), function ($q) use ($internalWhIds) {
                    $q->whereIn('l.warehouse_id', $internalWhIds);
                })
                ->where(function ($q) {
                    $q->whereNull('m.source_type')
                      ->orWhereNotIn('m.source_type', ['job_work_receipts', 'job_work_dispatches']);
                })
                ->whereNotIn('m.movement_type', ['transfer', 'job_work_receipt', 'job_work_dispatch'])
                ->sum('l.qty_base');
            $totalPurchased = max(0.0, $openingStock);
        }

        // 2. Dispatches to Job Workers
        $dispatches = DB::table('job_work_dispatch_lines as dl')
            ->join('job_work_dispatches as d', 'd.id', '=', 'dl.dispatch_id')
            ->leftJoin('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
            ->where('d.company_id', $context->companyId)
            ->where('dl.product_id', $productId)
            ->whereNull('d.reversed_at')
            ->orderBy('d.business_date')
            ->select([
                'd.id as dispatch_id',
                'd.reference_no as dispatch_ref',
                'o.reference_no as order_ref',
                'd.business_date',
                'dl.qty_base as dispatched_qty',
                's.name as processor_name',
            ])
            ->get();

        // 3. Conversions from job_work_receipts
        $convReceipts = DB::table('job_work_receipts as r')
            ->where('r.company_id', $context->companyId)
            ->whereNull('r.reversed_at')
            ->where(function ($q) {
                $q->where('r.details_json', 'like', '%"stock_name_change"%')
                  ->orWhereNotNull('r.dispatch_id');
            })
            ->get(['r.id', 'r.dispatch_id', 'r.reference_no', 'r.details_json', 'r.business_date', 'r.service_purchase_id']);

        // Conversions from paired stock movements
        $pairedConv = DB::table('stock_movements as m')
            ->join('stock_movement_lines as l', 'l.stock_movement_id', '=', 'm.id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('m.company_id', $context->companyId)
            ->where('m.source_type', 'job_work_receipts')
            ->where('l.qty_base', '>', 0)
            ->select('m.source_id as receipt_id', 'l.product_id', 'p.name', 'p.code', 'l.qty_base', 'm.source_no', 'm.movement_date')
            ->get()
            ->groupBy('receipt_id');

        // 4. Current Godown Stock (Internal Physical Warehouses only)
        $currentGodownStock = (float) DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->where('l.company_id', $context->companyId)
            ->where('m.company_id', $context->companyId)
            ->where('m.projection_mode', 'applied')
            ->where('m.status', 'posted')
            ->where('m.movement_type', '!=', 'reversal')
            ->whereNull('m.reversal_of_id')
            ->where('l.product_id', $productId)
            ->when(!empty($internalWhIds), function ($q) use ($internalWhIds) {
                $q->whereIn('l.warehouse_id', $internalWhIds);
            })
            ->sum('l.qty_base');
        $currentGodownStock = max(0.0, $currentGodownStock);

        $branches = [];
        $totalGivenToJobWork = 0.0;
        $totalReturnedConverted = 0.0;
        $totalProcessLoss = 0.0;
        $convertedGodownStock = 0.0;

        foreach ($dispatches as $idx => $d) {
            $dispQty = (float) $d->dispatched_qty;
            $totalGivenToJobWork += $dispQty;

            $matchingReceipts = $convReceipts->where('dispatch_id', $d->dispatch_id);
            $subBranches = [];

            foreach ($matchingReceipts as $rc) {
                $details = json_decode($rc->details_json ?? '{}', true);
                $change = $details['stock_name_change'] ?? null;

                if ($change) {
                    foreach ($change['items'] ?? [] as $item) {
                        if ((int) $item['from_product_id'] === $productId) {
                            $outPid = (int) $item['to_product_id'];
                            $outQty = (float) $item['qty'];
                            $lossQty = max(0.0, $dispQty - $outQty);
                            $lossPct = $dispQty > 0 ? round(($lossQty / $dispQty) * 100, 1) : 0.0;

                            $totalReturnedConverted += $outQty;
                            $totalProcessLoss += $lossQty;

                            $outStock = (float) DB::table('stock_movement_lines as l')
                                ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
                                ->where('l.company_id', $context->companyId)
                                ->where('m.company_id', $context->companyId)
                                ->where('m.projection_mode', 'applied')
                                ->where('m.status', 'posted')
                                ->where('m.movement_type', '!=', 'reversal')
                                ->whereNull('m.reversal_of_id')
                                ->where('l.product_id', $outPid)
                                ->when(!empty($internalWhIds), function ($q) use ($internalWhIds) {
                                    $q->whereIn('l.warehouse_id', $internalWhIds);
                                })
                                ->sum('l.qty_base');
                            $convertedGodownStock += max(0.0, $outStock);

                            $subBranches[] = [
                                'sub_branch_id' => 'sub-' . $rc->id . '-' . $outPid,
                                'type' => 'converted_product',
                                'output_product_id' => $outPid,
                                'output_product_name' => $item['to_name'],
                                'output_product_code' => DB::table('products')->where('id', $outPid)->value('code') ?: '',
                                'output_qty' => $outQty,
                                'unit' => $baseUnit,
                                'receipt_ref' => $rc->reference_no,
                                'bill_ref' => !empty($change['purchase_no']) ? $change['purchase_no'] : null,
                                'date' => $change['date'] ?? $rc->business_date,
                                'loss_qty' => $lossQty,
                                'loss_pct' => $lossPct,
                                'ready_godown_stock' => max(0.0, $outStock),
                                'timeline_url' => route('inventory-reports.timeline', ['product_id' => $outPid]),
                            ];
                        }
                    }
                } elseif ($pairedConv->has($rc->id)) {
                    foreach ($pairedConv->get($rc->id) as $conv) {
                        if ((int) $conv->product_id === $productId) continue;
                        $outPid = (int) $conv->product_id;
                        $outQty = abs((float) $conv->qty_base);
                        $lossQty = max(0.0, $dispQty - $outQty);
                        $lossPct = $dispQty > 0 ? round(($lossQty / $dispQty) * 100, 1) : 0.0;

                        $totalReturnedConverted += $outQty;
                        $totalProcessLoss += $lossQty;

                        $outStock = (float) DB::table('stock_movement_lines as l')
                            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
                            ->where('l.company_id', $context->companyId)
                            ->where('m.company_id', $context->companyId)
                            ->where('m.projection_mode', 'applied')
                            ->where('m.status', 'posted')
                            ->where('m.movement_type', '!=', 'reversal')
                            ->whereNull('m.reversal_of_id')
                            ->where('l.product_id', $outPid)
                            ->when(!empty($internalWhIds), function ($q) use ($internalWhIds) {
                                $q->whereIn('l.warehouse_id', $internalWhIds);
                            })
                            ->sum('l.qty_base');
                        $convertedGodownStock += max(0.0, $outStock);

                        $subBranches[] = [
                            'sub_branch_id' => 'sub-' . $rc->id . '-' . $outPid,
                            'type' => 'converted_product',
                            'output_product_id' => $outPid,
                            'output_product_name' => $conv->name,
                            'output_product_code' => $conv->code ?? '',
                            'output_qty' => $outQty,
                            'unit' => $baseUnit,
                            'receipt_ref' => $rc->reference_no,
                            'bill_ref' => null,
                            'date' => substr($conv->movement_date, 0, 10),
                            'loss_qty' => $lossQty,
                            'loss_pct' => $lossPct,
                            'ready_godown_stock' => max(0.0, $outStock),
                            'timeline_url' => route('inventory-reports.timeline', ['product_id' => $outPid]),
                        ];
                    }
                }
            }

            // If no receipt yet, mark as WIP pending sub-branch
            if (empty($subBranches)) {
                $subBranches[] = [
                    'sub_branch_id' => 'sub-wip-' . $d->dispatch_id,
                    'type' => 'wip',
                    'status_label' => 'Pending at Processor (WIP)',
                    'pending_qty' => $dispQty,
                    'unit' => $baseUnit,
                    'processor_name' => $d->processor_name ?: 'Cauvery Dyeing & Sizing Works',
                    'date' => $d->business_date,
                    'receipt_ref' => 'Awaiting Return',
                ];
            }

            $branches[] = [
                'branch_id' => 'branch-dispatch-' . $d->dispatch_id,
                'type' => 'dispatch',
                'title' => number_format($dispQty, 0) . ' ' . $baseUnit . ' ' . ($d->processor_name ?: 'Processor'),
                'processor_name' => $d->processor_name ?: 'Cauvery Dyeing & Sizing Works',
                'dispatch_ref' => $d->dispatch_ref,
                'order_ref' => $d->order_ref,
                'business_date' => $d->business_date,
                'dispatched_qty' => $dispQty,
                'unit' => $baseUnit,
                'is_completed' => !empty($subBranches) && $subBranches[0]['type'] === 'converted_product',
                'is_wip' => empty($subBranches) || $subBranches[0]['type'] === 'wip',
                'sub_branches' => $subBranches,
            ];
        }

        // Branch: Remaining Godown Stock
        if ($currentGodownStock > 0 || empty($branches)) {
            $branches[] = [
                'branch_id' => 'branch-godown-stock',
                'type' => 'godown_stock',
                'title' => number_format($currentGodownStock, 0) . ' ' . $baseUnit . ' Remaining in Godown',
                'warehouse_name' => $defaultWarehouse,
                'qty' => $currentGodownStock,
                'unit' => $baseUnit,
                'subtext' => 'Available physical raw fabric in warehouse',
                'timeline_url' => route('inventory-reports.timeline', ['product_id' => $productId]),
                'sub_branches' => [],
            ];
        }

        $root = [
            'product_id' => $productId,
            'product_name' => $product->name,
            'product_code' => $product->code,
            'plc' => $plc,
            'base_unit' => $baseUnit,
            'purchased_qty' => $totalPurchased,
            'purchases' => $purchases->map(fn ($p) => [
                'ref_no' => $p->ref_no,
                'date' => substr($p->movement_date, 0, 10),
                'qty' => (float) $p->qty,
                'supplier_name' => $p->supplier_name ?: 'Supplier',
            ])->all(),
            'first_purchase' => $purchases->first() ? [
                'ref_no' => $purchases->first()->ref_no,
                'date' => substr($purchases->first()->movement_date, 0, 10),
                'qty' => (float) $purchases->first()->qty,
                'supplier_name' => $purchases->first()->supplier_name ?: 'Supplier',
            ] : null,
        ];

        return [
            'root' => $root,
            'branches' => $branches,
            'totals' => [
                'purchased_inward' => $totalPurchased,
                'given_to_jobwork' => $totalGivenToJobWork,
                'returned_converted' => $totalReturnedConverted,
                'process_shrinkage' => $totalProcessLoss,
                'current_godown_stock' => $currentGodownStock,
                'converted_godown_stock' => $convertedGodownStock,
                'total_owned_stock' => $currentGodownStock + $convertedGodownStock,
                'unit' => $baseUnit,
            ],
        ];
    }
}
