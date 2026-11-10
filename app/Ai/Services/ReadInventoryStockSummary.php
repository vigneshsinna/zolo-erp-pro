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
use App\Services\Inventory\UomConversionService;
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
        protected UomConversionService $uom
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
            $warehouse = Warehouse::where('company_id', $companyId)->find($warehouseId);
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
            if ($warehouse->branch_id !== null && (int) $warehouse->branch_id !== (int) $principal->branchId) {
                return ErpToolResult::error(
                    toolKey: 'inventory.stock_summary.v1',
                    errorCode: 'AI_TOOL_FORBIDDEN',
                    message: "Warehouse '{$warehouse->name}' belongs to a different branch and is not accessible.",
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
                ->where(function ($q) use ($principal) {
                    $q->where('branch_id', $principal->branchId)
                      ->orWhereNull('branch_id');
                })
                ->get();

            if ($targetWarehouses->isEmpty()) {
                $warnings[] = "No active warehouses are assigned to the current branch.";
            }
        }

        // 2. Query products for company (physical products tracked in inventory)
        $productsQuery = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('type')->orWhere('type', '!=', 'service');
            });

        if ($productQuery !== null && trim($productQuery) !== '') {
            $term = trim($productQuery);
            $productsQuery->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('code', 'like', "%{$term}%");
            });
        }

        $allProducts = $productsQuery->with('unit')->get();

        // 3. Resolve Company Base Currency
        $company = Company::find($companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        $reportDate = now()->toDateString();
        $sourceRoute = route('report.warehouseStock', absolute: false);

        if ($allProducts->isEmpty()) {
            return ErpToolResult::ok(
                toolKey: 'inventory.stock_summary.v1',
                scope: [
                    'company_id' => $companyId,
                    'branch_id' => $principal->branchId,
                    'warehouse_id' => $warehouseId,
                ],
                data: [
                    'total_products_tracked' => 0,
                    'in_stock_count' => 0,
                    'out_of_stock_count' => 0,
                    'total_stock_value' => '0.00',
                    'currency' => $currencyCode,
                    'warehouse' => $warehouseName ?? 'All Warehouses',
                    'units_summary' => [],
                    'top_products' => [],
                ],
                sourceRefs: [$sourceRoute],
                warnings: $productQuery !== null
                    ? ["No active products matched query '{$productQuery}'."]
                    : ['No active physical products found for this company.'],
                asOf: $reportDate,
                reconciled: true
            );
        }

        // 4. Calculate stock metrics per product
        $productDetails = [];
        $unitsSummary = [];
        $inStockCount = 0;
        $outOfStockCount = 0;
        $totalValuation = BigDecimal::zero();
        $hasLedgerVariance = false;

        foreach ($allProducts as $product) {
            $onHand = 0.0;
            $reserved = 0.0;
            $available = 0.0;
            $ledger = 0.0;

            if ($warehouseId !== null) {
                $avail = $this->availabilityService->forProduct(
                    productId: $product->id,
                    warehouseId: $warehouseId
                );
                $onHand = (float) $avail['on_hand'];
                $reserved = (float) $avail['reserved'];
                $available = (float) $avail['available'];
                $ledger = (float) $avail['ledger_on_hand'];
            } else {
                if ($targetWarehouses->isEmpty()) {
                    $onHand = $reserved = $available = $ledger = 0.0;
                } else {
                    foreach ($targetWarehouses as $targetWh) {
                        $avail = $this->availabilityService->forProduct(
                            productId: $product->id,
                            warehouseId: $targetWh->id
                        );
                        $onHand += (float) $avail['on_hand'];
                        $reserved += (float) $avail['reserved'];
                        $available += (float) $avail['available'];
                        $ledger += (float) $avail['ledger_on_hand'];
                    }
                }
            }

            if ($onHand != $ledger) {
                $hasLedgerVariance = true;
            }

            if ($onHand > 0) {
                $inStockCount++;
            } else {
                $outOfStockCount++;
            }

            $unitCode = $product->unit?->unit_code ?: ($product->unit?->unit_name ?: 'Units');
            $cost = BigDecimal::of((string) ($product->cost ?? '0.00'));
            $val = BigDecimal::of((string) max(0.0, $onHand))->multipliedBy($cost);
            $totalValuation = $totalValuation->plus($val);

            // Group strictly by unit of measure so incompatible units are never added
            if (!isset($unitsSummary[$unitCode])) {
                $unitsSummary[$unitCode] = [
                    'unit' => $unitCode,
                    'on_hand' => 0.0,
                    'reserved' => 0.0,
                    'available' => 0.0,
                    'product_count' => 0,
                ];
            }
            $unitsSummary[$unitCode]['on_hand'] = round($unitsSummary[$unitCode]['on_hand'] + $onHand, 4);
            $unitsSummary[$unitCode]['reserved'] = round($unitsSummary[$unitCode]['reserved'] + $reserved, 4);
            $unitsSummary[$unitCode]['available'] = round($unitsSummary[$unitCode]['available'] + $available, 4);
            $unitsSummary[$unitCode]['product_count']++;

            $productDetails[] = [
                'product_id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'unit' => $unitCode,
                'on_hand' => round($onHand, 4),
                'reserved' => round($reserved, 4),
                'available' => round($available, 4),
                'unit_cost' => (string) $cost,
                'stock_value' => (string) $val->toScale(2, RoundingMode::HalfUp),
            ];
        }

        if ($hasLedgerVariance) {
            $warnings[] = 'Stock movement history differs from current on-hand quantities for some items. Physical stock count recommended.';
        }

        // Sort products by available stock descending
        usort($productDetails, fn ($a, $b) => $b['available'] <=> $a['available']);
        $topProducts = array_slice($productDetails, 0, $limit);

        return ErpToolResult::ok(
            toolKey: 'inventory.stock_summary.v1',
            scope: [
                'company_id' => $companyId,
                'branch_id' => $principal->branchId,
                'warehouse_id' => $warehouseId,
            ],
            data: [
                'total_products_tracked' => count($allProducts),
                'in_stock_count' => $inStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'total_stock_value' => (string) $totalValuation->toScale(2, RoundingMode::HalfUp),
                'currency' => $currencyCode,
                'warehouse' => $warehouseName ?? 'All Warehouses',
                'units_summary' => array_values($unitsSummary),
                'top_products' => $topProducts,
            ],
            sourceRefs: [$sourceRoute],
            warnings: $warnings,
            asOf: $reportDate,
            reconciled: !$hasLedgerVariance
        );
    }
}
