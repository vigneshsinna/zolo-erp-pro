<?php

namespace App\Http\Controllers;

use App\Models\{Product, Warehouse};
use App\Services\Industry\{IndustryStockQueries, SimplifiedStockService};
use App\Services\Platform\{CompanyContext, CompanyContextResolver};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryLotReportController extends Controller
{
    public function index(Request $request, IndustryStockQueries $queries, SimplifiedStockService $simplifiedService)
    {
        $context = $request->attributes->get(CompanyContext::class) ?? app(CompanyContextResolver::class)->forActor();
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'product_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'lot' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'export' => 'nullable|in:csv',
            'group_by' => 'nullable|in:simplified,product,lot',
            'search' => 'nullable|string|max:100',
        ]);

        $filters['group_by'] = $filters['group_by'] ?? 'product';
        $today = now(\App\Models\Company::findOrFail($context->companyId)->timezone ?: 'Asia/Kolkata');
        $filters['from'] = $filters['from'] ?? $today->copy()->subMonths(3)->startOfMonth()->toDateString();
        $filters['to'] = $filters['to'] ?? $today->toDateString();
        validator($filters, ['to' => 'after_or_equal:from'])->validate();

        // 1. Simplified Stock View (Purchased -> Given to Job Work -> Returned -> Final Stock)
        if ($filters['group_by'] === 'simplified') {
            $data = $simplifiedService->simplifiedStock($filters, $context, (int) auth()->id());
            $allRows = $data['rows'];

            if (($filters['export'] ?? null) === 'csv') {
                return response()->streamDownload(function () use ($allRows, $filters) {
                    $file = fopen('php://output', 'w');
                    fputcsv($file, [
                        'Product', 'Code', 'Lot', 'Unit', 'Purchased Inward', 'Given to Job Work',
                        'Returned after Job Work', 'In Godown', 'In Quarantine', 'At Processors (WIP)',
                        'Final Total Stock', 'From Date', 'To Date', 'Traceability / Converted Stock',
                    ]);
                    foreach ($allRows as $row) {
                        $name = static fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value;
                        fputcsv($file, [
                            $name($row->product),
                            $name($row->product_code),
                            $name($row->lot),
                            $name($row->base_unit),
                            $row->purchased_qty,
                            $row->given_to_job_work_qty,
                            $row->returned_after_job_work_qty,
                            $row->in_godown_qty,
                            $row->in_quarantine_qty,
                            $row->at_job_workers_qty,
                            $row->final_stock_qty,
                            $filters['from'],
                            $filters['to'],
                            $name(implode('; ', $row->conversion_notes ?? [])),
                        ]);
                    }
                    fclose($file);
                }, 'simplified-stock-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            }

            $page = (int) ($filters['page'] ?? 1);
            $perPage = 50;
            $sliced = array_slice($allRows, ($page - 1) * $perPage, $perPage);
            $paginated = new LengthAwarePaginator($sliced, count($allRows), $perPage, $page, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);

            $topProducts = array_slice(
                collect($allRows)->sortByDesc(fn ($r) => ($r->purchased_qty + $r->final_stock_qty + ($r->sold_qty ?? 0)))->values()->all(),
                0, 8
            );

            $stockGraphData = [
                'labels' => array_map(fn ($r) => $this->formatChartLabel($r->product, $r->product_code ?? '', $r->handle ?? ''), $topProducts),
                'purchased' => array_map(fn ($r) => round($r->purchased_qty, 2), $topProducts),
                'sold' => array_map(fn ($r) => round($r->sold_qty ?? 0, 2), $topProducts),
                'dispatched' => array_map(fn ($r) => round($r->given_to_job_work_qty, 2), $topProducts),
                'returned' => array_map(fn ($r) => round($r->returned_after_job_work_qty, 2), $topProducts),
                'final_stock' => array_map(fn ($r) => round($r->final_stock_qty, 2), $topProducts),
                'doughnut' => [
                    'labels' => ['Purchased Inward', 'Sold Out (Sales)', 'At Processors (WIP)', 'In Godown'],
                    'values' => [
                        round($data['totals']['total_purchased'] ?? 0, 2),
                        round($data['totals']['total_sold'] ?? 0, 2),
                        round($data['totals']['total_at_job_workers'] ?? 0, 2),
                        round($data['totals']['total_in_godown'] ?? 0, 2),
                    ],
                ],
            ];

            return view('backend.reports.stock-by-lot', [
                'isSimplified' => true,
                'rows' => $paginated,
                'totals' => $data['totals'],
                'stockGraphData' => $stockGraphData,
                'filters' => $filters,
                'stockNameNotes' => [],
                'products' => Product::forCompany($context)->orderBy('name')->get(['id', 'name']),
                'warehouses' => Warehouse::forCompany($context)->where('branch_id', $context->branchId)->orderBy('name')->get(['id', 'name']),
            ]);
        }

        // 2. Standard Detailed Warehouse Ledger / Lot View
        $query = $queries->stockByLot($filters, $context, (int) auth()->id());
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('p.name', 'like', $term)
                  ->orWhere('p.code', 'like', $term)
                  ->orWhere('b.batch_no', 'like', $term);
            });
        }
        if (($filters['export'] ?? null) === 'csv') {
            $stockNameNotes = $this->stockNameNotes($context, $filters);
            return response()->streamDownload(function () use ($query, $filters, $stockNameNotes) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Product', 'Code', 'Lot', 'Warehouse', 'Base unit', 'Opening', 'Inward', 'Outward', 'Closing',
                    'Warehouse current', 'Customer custody current', 'GDNI current value', 'Owned current', 'From', 'To', 'Returned stock link']);
                foreach ($query->cursor() as $row) {
                    $name = static fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value;
                    fputcsv($file, [$name($row->product), $name($row->product_code), $name($row->lot ?: 'Unbatched'), $name($row->warehouse),
                        $name($row->base_unit), $row->opening_qty, $row->inward_qty, $row->outward_qty, $row->closing_qty,
                        $row->current_qty, $row->custody_qty, $row->custody_value, $row->owned_current_qty, $filters['from'], $filters['to'],
                        $name(implode('; ', $stockNameNotes[$row->product_id.'|'.$row->warehouse_id.'|'.$row->lot] ?? []))]);
                }
                fclose($file);
            }, 'stock-by-lot.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $rows = $query->paginate(50)->withQueryString();

        $topRows = array_slice(collect($rows->items())->sortByDesc(fn ($r) => (($r->inward_qty ?? 0) + ($r->closing_qty ?? 0)))->values()->all(), 0, 8);
        $topProductIds = array_unique(array_filter(array_map(fn ($r) => (int) ($r->product_id ?? 0), $topRows)));

        $soldByProduct = [];
        if (!empty($topProductIds)) {
            $toPlusOne = CarbonImmutable::parse($filters['to'])->addDay()->toDateString();
            $soldRows = DB::table('stock_movement_lines as l')
                ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
                ->where('l.company_id', $context->companyId)
                ->where('m.company_id', $context->companyId)
                ->where('m.projection_mode', 'applied')
                ->whereIn('m.status', ['posted', 'reversed'])
                ->where('m.movement_date', '>=', $filters['from'])
                ->where('m.movement_date', '<', $toPlusOne)
                ->whereIn('l.product_id', $topProductIds)
                ->where(function ($q) {
                    $q->where('m.movement_type', 'sale')->orWhere('m.source_type', 'sale');
                })
                ->where('l.qty_base', '<', 0)
                ->groupBy('l.product_id')
                ->selectRaw('l.product_id, SUM(-l.qty_base) as sold_qty')
                ->pluck('sold_qty', 'product_id')
                ->all();
            $soldByProduct = $soldRows;
        }

        $inwardVals = array_map(fn ($r) => round($r->inward_qty ?? 0, 2), $topRows);
        $soldVals = array_map(fn ($r) => round((float) ($soldByProduct[$r->product_id] ?? 0), 2), $topRows);
        $outwardVals = array_map(fn ($r) => round($r->outward_qty ?? 0, 2), $topRows);
        $closingVals = array_map(fn ($r) => round($r->closing_qty ?? 0, 2), $topRows);

        $stockGraphData = [
            'labels' => array_map(fn ($r) => $this->formatChartLabel($r->product, $r->code ?? '', ''), $topRows),
            'purchased' => $inwardVals,
            'sold' => $soldVals,
            'dispatched' => $outwardVals,
            'returned' => array_fill(0, count($topRows), 0),
            'final_stock' => $closingVals,
            'doughnut' => [
                'labels' => ['Total Inward (Come)', 'Sold Out (Sales)', 'Total Outward (Gone)', 'Closing (In Hand)'],
                'values' => [
                    round(array_sum($inwardVals), 2),
                    round(array_sum($soldVals), 2),
                    round(array_sum($outwardVals), 2),
                    round(array_sum($closingVals), 2),
                ],
            ],
        ];

        return view('backend.reports.stock-by-lot', [
            'isSimplified' => false,
            'rows' => $rows,
            'stockGraphData' => $stockGraphData,
            'filters' => $filters,
            'stockNameNotes' => $this->stockNameNotes($context, $filters),
            'products' => Product::forCompany($context)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::forCompany($context)->where('branch_id', $context->branchId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function lifecycle(Request $request, SimplifiedStockService $simplifiedService)
    {
        $context = $request->attributes->get(CompanyContext::class) ?? app(CompanyContextResolver::class)->forActor();
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'product_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'plc' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $today = now(\App\Models\Company::findOrFail($context->companyId)->timezone ?: 'Asia/Kolkata');
        $filters['from'] = $filters['from'] ?? $today->copy()->subMonths(3)->startOfMonth()->toDateString();
        $filters['to'] = $filters['to'] ?? $today->toDateString();

        $catalogData = $simplifiedService->productsWithLifecycle($filters, $context, (int) auth()->id());
        $products = $catalogData['products'];
        $distinctPlcs = $catalogData['distinct_plcs'];

        // Determine active product
        $activeProductId = null;
        if (!empty($filters['product_id'])) {
            $activeProductId = (int) $filters['product_id'];
        } elseif (!empty($filters['plc'])) {
            $targetPlc = strtoupper(trim($filters['plc']));
            foreach ($products as $p) {
                if (strtoupper($p['plc']) === $targetPlc) {
                    $activeProductId = $p['product_id'];
                    break;
                }
            }
        }

        if (!$activeProductId && !empty($products)) {
            $activeProductId = $products[0]['product_id'];
        }

        $allProducts = Product::forCompany($context)->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = Warehouse::forCompany($context)->where('branch_id', $context->branchId)->orderBy('name')->get(['id', 'name']);

        if (!$activeProductId) {
            return view('backend.reports.product-lifecycle', [
                'hasProduct' => false,
                'catalog' => $products,
                'distinctPlcs' => $distinctPlcs,
                'activeProduct' => null,
                'tree' => null,
                'filters' => $filters,
                'products' => $allProducts,
                'warehouses' => $warehouses,
            ]);
        }

        $treeData = $simplifiedService->productLifecycleTree($activeProductId, $filters, $context, (int) auth()->id());

        return view('backend.reports.product-lifecycle', [
            'hasProduct' => true,
            'catalog' => $products,
            'distinctPlcs' => $distinctPlcs,
            'activeProduct' => $treeData['root'],
            'tree' => $treeData,
            'filters' => $filters,
            'products' => $allProducts,
            'warehouses' => $warehouses,
        ]);
    }

    public function timeline(Request $request, SimplifiedStockService $simplifiedService)
    {
        $context = $request->attributes->get(CompanyContext::class) ?? app(CompanyContextResolver::class)->forActor();
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'product_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'lot' => 'nullable|string|max:100',
        ]);

        $today = now(\App\Models\Company::findOrFail($context->companyId)->timezone ?: 'Asia/Kolkata');
        $filters['from'] = $filters['from'] ?? $today->copy()->subMonths(3)->startOfMonth()->toDateString();
        $filters['to'] = $filters['to'] ?? $today->toDateString();

        $allProducts = Product::forCompany($context)->orderBy('name')->get(['id', 'name', 'code']);
        $warehouses = Warehouse::forCompany($context)->where('branch_id', $context->branchId)->orderBy('name')->get(['id', 'name']);

        if (empty($filters['product_id'])) {
            $activeId = \Illuminate\Support\Facades\DB::table('stock_movement_lines')
                ->where('company_id', $context->companyId)
                ->orderByDesc('id')
                ->value('product_id');
            if (!$activeId) {
                $activeId = \Illuminate\Support\Facades\DB::table('job_work_dispatch_lines as dl')
                    ->join('job_work_dispatches as d', 'd.id', '=', 'dl.dispatch_id')
                    ->where('d.company_id', $context->companyId)
                    ->orderByDesc('dl.id')
                    ->value('dl.product_id');
            }
            $productId = $activeId ?: $allProducts->first()?->id;
        } else {
            $productId = (int) $filters['product_id'];
        }

        if (!$productId) {
            return view('backend.reports.inventory-timeline', [
                'hasProduct' => false,
                'product' => null,
                'events' => [],
                'summary' => [],
                'chart' => [],
                'dailySummary' => [],
                'movementSummary' => [],
                'lifecycleStages' => [],
                'convertedToProducts' => [],
                'convertedFromProducts' => [],
                'products' => $allProducts,
                'warehouses' => $warehouses,
                'filters' => $filters,
            ]);
        }

        $timelineData = $simplifiedService->inventoryTimeline((int) $productId, $filters['lot'] ?? null, $filters, $context, (int) auth()->id());

        return view('backend.reports.inventory-timeline', [
            'hasProduct' => true,
            'product' => $timelineData['product'],
            'events' => $timelineData['events'],
            'summary' => $timelineData['summary'],
            'chart' => $timelineData['chart'],
            'dailySummary' => $timelineData['dailySummary'],
            'movementSummary' => $timelineData['movementSummary'],
            'lifecycleStages' => $timelineData['lifecycleStages'],
            'convertedToProducts' => $timelineData['convertedToProducts'],
            'convertedFromProducts' => $timelineData['convertedFromProducts'],
            'products' => $allProducts,
            'warehouses' => $warehouses,
            'filters' => $filters,
        ]);
    }

    private function stockNameNotes(CompanyContext $context, array $filters): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('job_work_receipts')) return [];
        $notes = [];
        $combined = ($filters['group_by'] ?? 'product') === 'product' && empty($filters['lot']);
        $receipts = \Illuminate\Support\Facades\DB::table('job_work_receipts')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)->whereNull('reversed_at')->whereNotNull('service_purchase_id')
            ->where('details_json', 'like', '%"stock_name_change"%')->get(['reference_no', 'details_json']);
        foreach ($receipts as $receipt) {
            $change = json_decode($receipt->details_json ?? '{}', true)['stock_name_change'] ?? null;
            if (!$change || $change['date'] > $filters['to']) continue;
            foreach ($change['items'] as $item) {
                $suffix = '|'.$item['warehouse_id'].'|'.($combined ? 'Combined lots' : ($item['lot'] ?? ''));
                $ref = ' ('.$receipt->reference_no.' / '.$change['purchase_no'].')';
                $qty = rtrim(rtrim(number_format($item['qty'], 4, '.', ''), '0'), '.');
                $notes[$item['from_product_id'].$suffix][] = $qty.' returned as '.$item['to_name'].$ref;
                $notes[$item['to_product_id'].$suffix][] = $qty.' received from '.$item['from_name'].$ref;
            }
        }
        return $notes;
    }

    private function formatChartLabel(string $productName, string $code = '', string $plc = ''): string
    {
        if (!$plc) {
            if (preg_match('/\(([^)]+)\)$/', $productName, $m)) {
                $plc = $m[1];
            } elseif ($code && preg_match('/-([A-Z0-9]+)$/', $code, $m)) {
                $plc = $m[1];
            }
        }
        $base = trim(preg_replace('/\s*\([^)]*\)$/', '', $productName));
        if (mb_strlen($base) > 15) {
            $base = mb_substr($base, 0, 15);
        }
        return $plc ? "{$base} ({$plc})" : $base;
    }
}
