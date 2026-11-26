<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Commercial\CommercialPermission;
use App\Services\Inventory\InventoryPolicy;
use App\Services\Platform\CompanyContext;
use App\Support\LedgerAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Authoritative, multi-tenant inventory stock summary facade.
 * Strict company isolation: validates product and warehouse boundaries against the actor principal.
 */
class ReadInventoryStockSummary
{
    public function __construct(
        protected InventoryAvailabilityService $availabilityService,
        protected InventoryPolicy $inventoryPolicy
    ) {}

    public function execute(
        AiPrincipal $principal,
        ?string $productQuery = null,
        ?int $warehouseId = null,
        int $limit = 10
    ): ErpToolResult {
        $limit = max(1, min(50, $limit));
        $companyId = $principal->companyId;
        $warnings = [];

        // 1. Authoritative Warehouse Scope Resolution (SEC-01 & SEC-02)
        $warehouseName = null;
        if ($warehouseId !== null) {
            $warehouse = Warehouse::where('company_id', $companyId)
                ->where('branch_id', $principal->branchId)->where('is_active', true)->find($warehouseId);
            if (!$warehouse) {
                return ErpToolResult::error(
                    toolKey: 'inventory.stock_summary.v1',
                    errorCode: 'AI_TOOL_FORBIDDEN',
                    message: "Warehouse ID {$warehouseId} does not exist or is not accessible.",
                    scope: [
                        'company_id' => $companyId,
                        'branch_id' => $principal->branchId,
                        'warehouse_id' => $warehouseId,
                    ]
                );
            }
            $warehouseName = $warehouse->name;
            $targetWarehouses = collect([$warehouse]);
        } else {
            // Default to warehouses authorized for the actor's branch (fail-closed against cross-branch aggregation)
            $targetWarehouses = Warehouse::where('company_id', $companyId)
                ->where('is_active', true)
                ->where('branch_id', $principal->branchId)
                ->get();

            if ($targetWarehouses->isEmpty()) {
                return ErpToolResult::denied('inventory.stock_summary.v1', 'No active warehouses are assigned to the selected branch.', 'AI_TOOL_FORBIDDEN');
            }
        }

        // 2. Query products for company (physical products tracked in inventory)
        $productsQuery = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('type')->orWhereNotIn('type', ['service', 'digital']);
            });

        if ($productQuery !== null && trim($productQuery) !== '') {
            $term = trim($productQuery);
            $productsQuery->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('code', 'like', "%{$term}%");
            });
        }

        $context = new CompanyContext($companyId, $principal->branchId, $principal->financialYearId);
        $allowed = app(CommercialPermission::class)->allowed(['purchases-index', 'warehouse-stock-report'], $context, $principal->actorId);
        $canSeeCost = in_array('purchases-index', $allowed, true);
        $valuation = $this->inventoryPolicy->valuation($companyId);
        $warehouseIds = $targetWarehouses->pluck('id')->all();
        $company = Company::findOrFail($companyId);
        $currency = Currency::find($company->base_currency_id)?->code ?? 'INR';

        $totals = ['tracked' => 0, 'in_stock' => 0, 'value' => BigDecimal::zero(), 'unreconciled' => false];
        $units = $top = [];
        $productsQuery->with(['unit' => fn ($q) => $q->where('company_id', $companyId)])
            ->chunkById(250, function ($products) use ($context, $warehouseIds, $canSeeCost, $valuation, $limit, &$totals, &$units, &$top): void {
                $stock = $this->availabilityService->forCatalogBatch($context, $products, $warehouseIds);
                foreach ($products as $product) {
                    $balance = $stock[$product->id] ?? [];
                    $onHand = $balance['on_hand'] ?? 0;
                    $reserved = $balance['reserved'] ?? 0;
                    $available = $onHand - $reserved;
                    $unreconciled = $balance['unreconciled'] ?? false;
                    $totals['tracked']++;
                    $totals['in_stock'] += (int) ($onHand > 0);
                    $totals['unreconciled'] = $totals['unreconciled'] || $unreconciled;

                    $unitCode = $product->unit?->unit_code ?: ($product->unit?->unit_name ?: 'Unknown unit');
                    $unitKey = $product->unit ? 'unit:' . $product->unit_id : 'product:' . $product->id;
                    $units[$unitKey] ??= ['unit' => $unitCode, 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'product_count' => 0];
                    $units[$unitKey]['on_hand'] += $onHand;
                    $units[$unitKey]['reserved'] += $reserved;
                    $units[$unitKey]['available'] += $available;
                    $units[$unitKey]['product_count']++;

                    $value = $unitCost = null;
                    if ($canSeeCost && !$unreconciled) {
                        if ($valuation === 'standard') {
                            $unitCost = BigDecimal::of((string) ($product->cost ?? '0'));
                            $value = $unitCost->multipliedBy(LedgerAmount::decimal($onHand));
                        } else {
                            $value = BigDecimal::of(LedgerAmount::decimal($balance['value'] ?? 0));
                            $unitCost = $onHand > 0 ? $value->dividedBy(LedgerAmount::decimal($onHand), 6, RoundingMode::HalfUp) : null;
                        }
                        $totals['value'] = $totals['value']->plus($value);
                    }

                    $top[] = [
                        'product_id' => $product->id,
                        'code' => $product->code,
                        'name' => $product->name,
                        'unit' => $unitCode,
                        'on_hand' => $onHand / 10000,
                        'reserved' => $reserved / 10000,
                        'available' => $available / 10000,
                        'unit_cost' => $unitCost === null ? null : (string) $unitCost,
                        'stock_value' => $value === null ? null : (string) $value->toScale(2, RoundingMode::HalfUp),
                    ];
                }
                usort($top, static fn ($a, $b) => ($b['available'] <=> $a['available']) ?: ($a['product_id'] <=> $b['product_id']));
                $top = array_slice($top, 0, $limit);
            });

        if ($totals['tracked'] === 0) {
            $warnings[] = 'No active inventory products matched this request.';
        }
        if ($totals['unreconciled']) {
            $warnings[] = 'Stock projections differ from the movement ledger. The total valuation is unavailable until stock reconciles.';
        }

        return ErpToolResult::ok(
            toolKey: 'inventory.stock_summary.v1',
            scope: [
                'company_id' => $companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
                'warehouse_id' => $warehouseId,
            ],
            data: [
                'total_products_tracked' => $totals['tracked'],
                'in_stock_count' => $totals['in_stock'],
                'out_of_stock_count' => $totals['tracked'] - $totals['in_stock'],
                'total_stock_value' => $canSeeCost && !$totals['unreconciled'] ? (string) $totals['value']->toScale(2, RoundingMode::HalfUp) : null,
                'valuation_visible' => $canSeeCost,
                'currency' => $currency,
                'warehouse' => $warehouseName ?? 'Active Branch Warehouses',
                'units_summary' => array_values(array_map(static function (array $unit): array {
                    foreach (['on_hand', 'reserved', 'available'] as $key) {
                        $unit[$key] /= 10000;
                    }
                    return $unit;
                }, array_slice($units, 0, 50))),
                'top_products' => $top,
            ],
            sourceRefs: in_array('warehouse-stock-report', $allowed, true) ? [route('report.warehouseStock', absolute: false)] : [],
            warnings: $warnings,
            asOf: now($company->timezone)->toDateString(),
            reconciled: !$totals['unreconciled'],
            rowCount: count($top),
        );
    }
}
