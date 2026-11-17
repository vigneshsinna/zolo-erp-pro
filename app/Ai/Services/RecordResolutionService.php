<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Models\Customer;
use App\Models\GoodsReceivedNote;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Purchase;
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
     * Enforces branch-level scoping and safe uniqueness (never silent first() fallback).
     *
     * @return array{
     *     status: 'exact'|'candidates'|'none',
     *     warehouse: ?Warehouse,
     *     candidates: \Illuminate\Support\Collection<int, Warehouse>,
     *     recommendation_reason?: ?string,
     *     match_kind?: ?string
     * }
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
                    return [
                        'status' => 'exact',
                        'warehouse' => $warehouse,
                        'candidates' => collect([$warehouse]),
                        'recommendation_reason' => 'Exact ID match',
                        'match_kind' => 'id',
                    ];
                }
            }

            // Check exact name or code in active branch
            $exactBranch = Warehouse::where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('is_active', true)
                ->where(function ($q) use ($trimmed) {
                    $q->where('name', $trimmed)
                        ->orWhere('address', $trimmed);
                })
                ->get();

            if ($exactBranch->count() === 1) {
                return [
                    'status' => 'exact',
                    'warehouse' => $exactBranch->first(),
                    'candidates' => $exactBranch,
                    'recommendation_reason' => 'Exact match in active branch',
                    'match_kind' => 'exact_name',
                ];
            }

            if ($exactBranch->count() > 1) {
                return [
                    'status' => 'candidates',
                    'warehouse' => null,
                    'candidates' => $exactBranch,
                    'recommendation_reason' => 'Multiple warehouses matched query in active branch',
                    'match_kind' => 'multiple_exact',
                ];
            }

            // Prefix search in branch (to leverage B-Tree indexes)
            $prefixBranch = Warehouse::where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('is_active', true)
                ->where(function ($q) use ($trimmed) {
                    $q->where('name', 'LIKE', "{$trimmed}%")
                        ->orWhere('address', 'LIKE', "{$trimmed}%");
                })
                ->get();

            if ($prefixBranch->count() === 1) {
                return [
                    'status' => 'exact',
                    'warehouse' => $prefixBranch->first(),
                    'candidates' => $prefixBranch,
                    'recommendation_reason' => 'Unique prefix match in active branch',
                    'match_kind' => 'prefix_name',
                ];
            }

            if ($prefixBranch->count() > 1) {
                return [
                    'status' => 'candidates',
                    'warehouse' => null,
                    'candidates' => $prefixBranch,
                    'recommendation_reason' => 'Multiple warehouses matched prefix in active branch',
                    'match_kind' => 'multiple_prefix',
                ];
            }

            // Substring search in branch
            $subBranch = Warehouse::where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('is_active', true)
                ->where(function ($q) use ($trimmed) {
                    $q->where('name', 'LIKE', "%{$trimmed}%")
                        ->orWhere('address', 'LIKE', "%{$trimmed}%");
                })
                ->get();

            if ($subBranch->isEmpty()) {
                $cleaned = trim((string) preg_replace('/\b(warehouse|wh|godown|store|depot)\b/i', '', $trimmed));
                if ($cleaned !== '' && mb_strlen($cleaned) >= 2) {
                    $subBranch = Warehouse::where('company_id', $context->companyId)
                        ->where('branch_id', $context->branchId)
                        ->where('is_active', true)
                        ->where(function ($q) use ($cleaned) {
                            $q->where('name', 'LIKE', "%{$cleaned}%")
                                ->orWhere('address', 'LIKE', "%{$cleaned}%");
                        })
                        ->get();
                }
            }

            if ($subBranch->count() === 1) {
                return [
                    'status' => 'exact',
                    'warehouse' => $subBranch->first(),
                    'candidates' => $subBranch,
                    'recommendation_reason' => 'Single matching warehouse in active branch',
                    'match_kind' => 'substring_name',
                ];
            }

            if ($subBranch->count() > 1) {
                return [
                    'status' => 'candidates',
                    'warehouse' => null,
                    'candidates' => $subBranch,
                    'recommendation_reason' => 'Multiple branch warehouses matched query',
                    'match_kind' => 'multiple_candidates',
                ];
            }

            return [
                'status' => 'none',
                'warehouse' => null,
                'candidates' => collect(),
            ];
        }

        // Query is omitted:
        // Check all active warehouses in active branch
        $branchWarehouses = Warehouse::where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('is_active', true)
            ->get();

        if ($branchWarehouses->count() === 1) {
            return [
                'status' => 'exact',
                'warehouse' => $branchWarehouses->first(),
                'candidates' => $branchWarehouses,
                'recommendation_reason' => 'Only active warehouse in this branch',
                'match_kind' => 'single_branch_warehouse',
            ];
        }

        if ($branchWarehouses->count() > 1) {
            return [
                'status' => 'candidates',
                'warehouse' => null,
                'candidates' => $branchWarehouses,
                'recommendation_reason' => 'Multiple active warehouses in branch; selection required',
                'match_kind' => 'multiple_branch_warehouses',
            ];
        }

        return [
            'status' => 'none',
            'warehouse' => null,
            'candidates' => collect(),
        ];
    }

    /**
     * Resolve the appropriate unit ID for a product (purchase_unit_id or sale_unit_id).
     */
    public function resolveUnit(Product $product, ?string $uomQuery = null, string $mode = 'purchase'): int
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

        if ($mode === 'sale') {
            return (int) ($product->sale_unit_id ?: $product->unit_id ?: 1);
        }

        return (int) ($product->purchase_unit_id ?: $product->unit_id ?: 1);
    }

    /**
     * Resolve a customer from a user query (name, company name, GSTIN/tax_no, phone, or ID).
     *
     * @return array{status: 'exact'|'candidates'|'none', customer: ?Customer, candidates: Collection<int, Customer>}
     */
    public function resolveCustomer(CompanyContext $context, string $query): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return ['status' => 'none', 'customer' => null, 'candidates' => collect()];
        }

        // 1. Numeric ID lookup
        if (is_numeric($trimmed)) {
            $byPk = Customer::where('company_id', $context->companyId)
                ->where('is_active', true)
                ->find((int) $trimmed);
            if ($byPk) {
                return ['status' => 'exact', 'customer' => $byPk, 'candidates' => collect([$byPk])];
            }
        }

        // 2. Exact match on tax_no, name, or company_name
        $exact = Customer::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($trimmed) {
                $q->where('tax_no', $trimmed)
                    ->orWhere('name', $trimmed)
                    ->orWhere('company_name', $trimmed);
            })
            ->first();

        if ($exact) {
            return ['status' => 'exact', 'customer' => $exact, 'candidates' => collect([$exact])];
        }

        // 3. Substring matching across names, tax_no, and phone
        $candidates = Customer::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($trimmed) {
                $q->where('name', 'LIKE', "%{$trimmed}%")
                    ->orWhere('company_name', 'LIKE', "%{$trimmed}%")
                    ->orWhere('tax_no', 'LIKE', "%{$trimmed}%")
                    ->orWhere('phone_number', 'LIKE', "%{$trimmed}%");
            })
            ->limit(5)
            ->get();

        if ($candidates->count() === 1) {
            return ['status' => 'exact', 'customer' => $candidates->first(), 'candidates' => $candidates];
        }

        if ($candidates->count() > 1) {
            return ['status' => 'candidates', 'customer' => null, 'candidates' => $candidates];
        }

        return ['status' => 'none', 'customer' => null, 'candidates' => collect()];
    }

    /**
     * Resolve an open Purchase Order from user query (reference_no or ID).
     *
     * @return array{status: 'exact'|'candidates'|'none', purchase_order: ?Purchase, candidates: Collection<int, Purchase>}
     */
    public function resolvePurchaseOrder(CompanyContext $context, string $query, ?int $supplierId = null): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return ['status' => 'none', 'purchase_order' => null, 'candidates' => collect()];
        }

        // 1. Numeric ID lookup
        if (is_numeric($trimmed)) {
            $byPk = Purchase::where('company_id', $context->companyId)
                ->where('status', 4)
                ->whereNull('reversed_at')
                ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
                ->find((int) $trimmed);
            if ($byPk) {
                return ['status' => 'exact', 'purchase_order' => $byPk, 'candidates' => collect([$byPk])];
            }
        }

        // 2. Exact match on reference_no
        $exact = Purchase::where('company_id', $context->companyId)
            ->where('status', 4)
            ->whereNull('reversed_at')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->where('reference_no', $trimmed)
            ->first();

        if ($exact) {
            return ['status' => 'exact', 'purchase_order' => $exact, 'candidates' => collect([$exact])];
        }

        // 3. Substring matching across reference_no
        $candidates = Purchase::where('company_id', $context->companyId)
            ->where('status', 4)
            ->whereNull('reversed_at')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->where('reference_no', 'LIKE', "%{$trimmed}%")
            ->limit(5)
            ->get();

        if ($candidates->count() === 1) {
            return ['status' => 'exact', 'purchase_order' => $candidates->first(), 'candidates' => $candidates];
        }

        if ($candidates->count() > 1) {
            return ['status' => 'candidates', 'purchase_order' => null, 'candidates' => $candidates];
        }

        return ['status' => 'none', 'purchase_order' => null, 'candidates' => collect()];
    }

    /**
     * Resolve a posted Goods Received Note from user query (grn_no or ID).
     *
     * @return array{status: 'exact'|'candidates'|'none', grn: ?GoodsReceivedNote, candidates: Collection<int, GoodsReceivedNote>}
     */
    public function resolveGoodsReceivedNote(CompanyContext $context, string $query, ?int $supplierId = null): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return ['status' => 'none', 'grn' => null, 'candidates' => collect()];
        }

        // 1. Numeric ID lookup
        if (is_numeric($trimmed)) {
            $byPk = GoodsReceivedNote::where('company_id', $context->companyId)
                ->whereNotNull('posted_at')
                ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
                ->find((int) $trimmed);
            if ($byPk) {
                return ['status' => 'exact', 'grn' => $byPk, 'candidates' => collect([$byPk])];
            }
        }

        // 2. Exact match on grn_no
        $exact = GoodsReceivedNote::where('company_id', $context->companyId)
            ->whereNotNull('posted_at')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->where('grn_no', $trimmed)
            ->first();

        if ($exact) {
            return ['status' => 'exact', 'grn' => $exact, 'candidates' => collect([$exact])];
        }

        // 3. Substring matching across grn_no
        $candidates = GoodsReceivedNote::where('company_id', $context->companyId)
            ->whereNotNull('posted_at')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->where('grn_no', 'LIKE', "%{$trimmed}%")
            ->limit(5)
            ->get();

        if ($candidates->count() === 1) {
            return ['status' => 'exact', 'grn' => $candidates->first(), 'candidates' => $candidates];
        }

        if ($candidates->count() > 1) {
            return ['status' => 'candidates', 'grn' => null, 'candidates' => $candidates];
        }

        return ['status' => 'none', 'grn' => null, 'candidates' => collect()];
    }
}
