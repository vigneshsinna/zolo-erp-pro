<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Commercial\ProductAliasService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Collection;

/**
 * Deterministic entity disambiguation and record resolution for natural-language workflows.
 * Scoped strictly by CompanyContext (Tenancy Law 1).
 */
class RecordResolutionService
{
    /**
     * Resolve a supplier from a user query (name, code, GSTIN, phone, or ID).
     *
     * @return array{status: 'exact'|'candidates'|'none', supplier: ?Supplier, candidates: Collection<int, Supplier>}
     */
    public function resolveSupplier(CompanyContext $context, string $query): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return ['status' => 'none', 'supplier' => null, 'candidates' => collect()];
        }

        // 1. Numeric ID lookup
        if (is_numeric($trimmed)) {
            $byPk = Supplier::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->find((int) $trimmed);
            if ($byPk) {
                return ['status' => 'exact', 'supplier' => $byPk, 'candidates' => collect([$byPk])];
            }
        }

        // 2. Exact match on VAT/GSTIN, name, or company_name
        $exact = Supplier::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($trimmed) {
                $q->where('vat_number', $trimmed)
                    ->orWhere('name', $trimmed)
                    ->orWhere('company_name', $trimmed);
            })
            ->first();

        if ($exact) {
            return ['status' => 'exact', 'supplier' => $exact, 'candidates' => collect([$exact])];
        }

        // 3. Substring matching across names, VAT/GSTIN, and phone
        $candidates = Supplier::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($trimmed) {
                $q->where('name', 'LIKE', "%{$trimmed}%")
                    ->orWhere('company_name', 'LIKE', "%{$trimmed}%")
                    ->orWhere('vat_number', 'LIKE', "%{$trimmed}%")
                    ->orWhere('phone_number', 'LIKE', "%{$trimmed}%");
            })
            ->limit(5)
            ->get();

        if ($candidates->count() === 1) {
            return ['status' => 'exact', 'supplier' => $candidates->first(), 'candidates' => $candidates];
        }

        if ($candidates->count() > 1) {
            return ['status' => 'candidates', 'supplier' => null, 'candidates' => $candidates];
        }

        return ['status' => 'none', 'supplier' => null, 'candidates' => collect()];
    }

    /**
     * Resolve a product from a user query (SKU/code, name, alias, or ID).
     *
     * @return array{status: 'exact'|'candidates'|'none', product: ?Product, candidates: Collection<int, Product>}
     */
    public function resolveProduct(CompanyContext $context, string $query): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return ['status' => 'none', 'product' => null, 'candidates' => collect()];
        }

        // 1. Numeric ID lookup
        if (is_numeric($trimmed)) {
            $byPk = Product::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->find((int) $trimmed);
            if ($byPk) {
                return ['status' => 'exact', 'product' => $byPk, 'candidates' => collect([$byPk])];
            }
        }

        // 2. Exact match on SKU / Code
        $byCode = Product::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where('code', $trimmed)
            ->first();

        if ($byCode) {
            return ['status' => 'exact', 'product' => $byCode, 'candidates' => collect([$byCode])];
        }

        // 3. Exact match on Product Alias
        $normalizedAlias = ProductAliasService::normalize($trimmed);
        if ($normalizedAlias !== '') {
            $alias = ProductAlias::where('company_id', $context->companyId)
                ->where('normalized_name', $normalizedAlias)
                ->first();
            if ($alias && $alias->product && $alias->product->is_active) {
                return ['status' => 'exact', 'product' => $alias->product, 'candidates' => collect([$alias->product])];
            }
        }

        // 4. Exact match on Product Name
        $byName = Product::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where('name', $trimmed)
            ->first();

        if ($byName) {
            return ['status' => 'exact', 'product' => $byName, 'candidates' => collect([$byName])];
        }

        // 5. Substring matching across Name and Code
        $candidates = Product::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($trimmed) {
                $q->where('name', 'LIKE', "%{$trimmed}%")
                    ->orWhere('code', 'LIKE', "%{$trimmed}%");
            })
            ->limit(5)
            ->get();

        if ($candidates->count() === 1) {
            return ['status' => 'exact', 'product' => $candidates->first(), 'candidates' => $candidates];
        }

        if ($candidates->count() > 1) {
            return ['status' => 'candidates', 'product' => null, 'candidates' => $candidates];
        }

        // 6. Substring matching on aliases
        $aliasProductIds = ProductAlias::where('company_id', $context->companyId)
            ->where('normalized_name', 'LIKE', "%{$normalizedAlias}%")
            ->pluck('product_id')
            ->unique();

        if ($aliasProductIds->isNotEmpty()) {
            $aliasProducts = Product::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->whereIn('id', $aliasProductIds)
                ->limit(5)
                ->get();

            if ($aliasProducts->count() === 1) {
                return ['status' => 'exact', 'product' => $aliasProducts->first(), 'candidates' => $aliasProducts];
            }
            if ($aliasProducts->count() > 1) {
                return ['status' => 'candidates', 'product' => null, 'candidates' => $aliasProducts];
            }
        }

        return ['status' => 'none', 'product' => null, 'candidates' => collect()];
    }

    /**
     * Resolve a warehouse for the given context.
     * If query is omitted, defaults to the active branch's default warehouse.
     *
     * @return array{status: 'exact'|'none', warehouse: ?Warehouse}
     */
    public function resolveWarehouse(CompanyContext $context, ?string $query = null): array
    {
        $trimmed = $query !== null ? trim($query) : '';

        if ($trimmed !== '') {
            if (is_numeric($trimmed)) {
                $warehouse = Warehouse::where('company_id', $context->companyId)
                    ->where('is_active', true)
                    ->find((int) $trimmed);
                if ($warehouse) {
                    return ['status' => 'exact', 'warehouse' => $warehouse];
                }
            }

            $warehouse = Warehouse::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->where(function ($q) use ($trimmed) {
                    $q->where('name', 'LIKE', "%{$trimmed}%")
                        ->orWhere('address', 'LIKE', "%{$trimmed}%");
                })
                ->first();

            if ($warehouse) {
                return ['status' => 'exact', 'warehouse' => $warehouse];
            }

            return ['status' => 'none', 'warehouse' => null];
        }

        // Default to branch warehouse
        $defaultBranchWarehouse = Warehouse::where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('is_active', true)
            ->first();

        if ($defaultBranchWarehouse) {
            return ['status' => 'exact', 'warehouse' => $defaultBranchWarehouse];
        }

        // Fallback to any active warehouse under company
        $anyWarehouse = Warehouse::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->first();

        return [
            'status' => $anyWarehouse ? 'exact' : 'none',
            'warehouse' => $anyWarehouse,
        ];
    }

    /**
     * Resolve the appropriate purchase unit ID for a product.
     */
    public function resolveUnit(Product $product, ?string $uomQuery = null): int
    {
        if ($uomQuery !== null && trim($uomQuery) !== '') {
            $trimmedUom = trim($uomQuery);
            $matchedUnitId = Unit::where('company_id', $product->company_id)
                ->where('is_active', true)
                ->where(function ($q) use ($trimmedUom) {
                    $q->where('unit_code', $trimmedUom)
                        ->orWhere('unit_name', $trimmedUom);
                })
                ->value('id');

            if ($matchedUnitId) {
                return (int) $matchedUnitId;
            }
        }

        return (int) ($product->purchase_unit_id ?: $product->unit_id ?: 1);
    }
}
