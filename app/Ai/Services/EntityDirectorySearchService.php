<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Task T03: Read-only, multi-tenant scoped ERP entity directory search service.
 * Supports browsing, querying, and ranking warehouses, suppliers, products,
 * customers, and units for AI clarification and selection pickers.
 */
class EntityDirectorySearchService
{
    public const ALLOWED_TYPES = ['warehouse', 'supplier', 'product', 'customer', 'unit'];

    private const PERMISSIONS = [
        'warehouse' => 'warehouse',
        'supplier' => 'suppliers-add',
        'product' => 'products-add',
        'customer' => 'customers-add',
        'unit' => 'unit',
    ];

    public function __construct(
        private readonly RecordResolutionService $resolutionService,
        private readonly CommercialPermission $permissions,
    ) {
    }

    /**
     * Perform read-only, scoped entity search for warehouses, suppliers, products, customers, and units.
     *
     * @return array{
     *     entity_type: string,
     *     field: ?string,
     *     task_id: ?string,
     *     task_revision: ?int,
     *     status: string,
     *     recommended_id: ?int,
     *     recommendation_reason: ?string,
     *     items: array<int, array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     has_more: bool,
     *     can_create: bool,
     *     create_disabled_reason: ?string,
     * }
     */
    public function search(
        CompanyContext $context,
        int $actorId,
        string $entityType,
        ?string $query = null,
        int $page = 1,
        int $perPage = 20,
        ?string $taskId = null,
        ?string $fieldPath = null,
    ): array {
        if (!in_array($entityType, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Invalid entity type: {$entityType}");
        }

        $taskRevision = null;
        $taskBranchId = null;
        if ($taskId !== null) {
            $task = DB::table('ai_workflow_tasks')
                ->where('id', $taskId)
                ->first();

            if (!$task || (int) $task->company_id !== $context->companyId) {
                abort(404, 'Workflow task not found or belongs to another company.');
            }

            $taskRevision = (int) $task->revision;
            $taskBranchId = $task->branch_id ? (int) $task->branch_id : null;
        }

        $effectiveBranchId = $taskBranchId ?? $context->branchId;

        $query = trim((string) $query);
        if ($query === '') {
            $query = null;
        } elseif (mb_strlen($query) > 100) {
            $query = mb_substr($query, 0, 100);
        }

        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));

        $requiredPerm = self::PERMISSIONS[$entityType] ?? 'warehouse';
        $canCreate = $this->permissions->allows($requiredPerm, $context, $actorId);
        $createDisabledReason = $canCreate ? null : "Missing permission: {$requiredPerm}";

        $recommendedId = null;
        $recommendationReason = null;
        $status = 'selection_needed';

        switch ($entityType) {
            case 'warehouse':
                $queryBuilder = Warehouse::withoutGlobalScopes()
                    ->with('branch');
                $queryBuilder->getQuery()->withoutCompanyScope();
                $queryBuilder->where('warehouses.company_id', $context->companyId);

                if ($query !== null) {
                    $escaped = addcslashes($query, '%_');
                    $queryBuilder->where(function ($qb) use ($query, $escaped) {
                        if (is_numeric($query)) {
                            $qb->orWhere('id', (int) $query);
                        }
                        $qb->orWhere('name', 'LIKE', "%{$escaped}%")
                            ->orWhere('address', 'LIKE', "%{$escaped}%");
                    });
                }

                if ($effectiveBranchId) {
                    $queryBuilder->orderByRaw("CASE WHEN branch_id = ? OR branch_id IS NULL THEN 0 ELSE 1 END", [$effectiveBranchId]);
                }
                $queryBuilder->orderByDesc('is_active')
                    ->orderBy('name');

                $paginator = $queryBuilder->paginate($perPage, ['*'], 'page', $page);
                $total = $paginator->total();
                $hasMore = $paginator->hasMorePages();

                $items = [];
                foreach ($paginator->items() as $wh) {
                    $isBranchMatch = !$effectiveBranchId || $wh->branch_id === null || (int) $wh->branch_id === (int) $effectiveBranchId;
                    $isActive = (bool) $wh->is_active;

                    $eligible = $isActive && $isBranchMatch;
                    $disabledReason = null;
                    if (!$isActive) {
                        $disabledReason = 'inactive';
                    } elseif (!$isBranchMatch) {
                        $disabledReason = 'wrong_branch';
                    }

                    $matchKind = 'catalog';
                    if ($query !== null) {
                        if (is_numeric($query) && (int) $query === (int) $wh->id) {
                            $matchKind = 'exact_id';
                        } elseif (strcasecmp((string) $wh->name, $query) === 0) {
                            $matchKind = 'exact_name';
                        } elseif (stripos((string) $wh->name, $query) !== false) {
                            $matchKind = 'partial_name';
                        }
                    }

                    $branchName = $wh->branch?->name;
                    $items[] = [
                        'id' => (int) $wh->id,
                        'name' => (string) $wh->name,
                        'display' => $branchName ? "{$wh->name} ({$branchName})" : (string) $wh->name,
                        'code' => null,
                        'branch_id' => $wh->branch_id ? (int) $wh->branch_id : null,
                        'branch_name' => $branchName,
                        'branch_label' => $branchName ? "Branch: {$branchName}" : 'All Branches',
                        'location' => (string) ($wh->address ?? ''),
                        'is_active' => $isActive,
                        'eligible' => $eligible,
                        'disabled_reason' => $disabledReason,
                        'match_kind' => $matchKind,
                    ];
                }

                $effectiveContext = $effectiveBranchId ? new CompanyContext($context->companyId, $effectiveBranchId, $context->financialYearId) : $context;
                $resolution = $this->resolutionService->resolveWarehouse($effectiveContext, $query);

                if ($resolution['status'] === 'exact' && $resolution['warehouse']) {
                    $recommendedId = (int) $resolution['warehouse']->id;
                    $recommendationReason = $resolution['reason'] ?? 'exact_match';
                    $status = 'suggested';
                } elseif ($resolution['status'] === 'candidates') {
                    $recommendedId = null;
                    $recommendationReason = $resolution['reason'] ?? 'ambiguous_candidates';
                    $status = 'selection_needed';
                } else {
                    $recommendedId = null;
                    $recommendationReason = $query ? 'not_found' : null;
                    $status = ($total === 0) ? 'no_matches' : 'selection_needed';
                }
                break;

            case 'supplier':
                $queryBuilder = Supplier::withoutGlobalScopes();
                $queryBuilder->getQuery()->withoutCompanyScope();
                $queryBuilder->where('suppliers.company_id', $context->companyId);

                if ($query !== null) {
                    $escaped = addcslashes($query, '%_');
                    $queryBuilder->where(function ($qb) use ($query, $escaped) {
                        if (is_numeric($query)) {
                            $qb->orWhere('id', (int) $query);
                        }
                        $qb->orWhere('name', 'LIKE', "%{$escaped}%")
                            ->orWhere('company_name', 'LIKE', "%{$escaped}%")
                            ->orWhere('vat_number', 'LIKE', "%{$escaped}%")
                            ->orWhere('phone_number', 'LIKE', "%{$escaped}%");
                    });
                }

                $queryBuilder->orderByDesc('is_active')->orderBy('name');
                $paginator = $queryBuilder->paginate($perPage, ['*'], 'page', $page);
                $total = $paginator->total();
                $hasMore = $paginator->hasMorePages();

                $items = [];
                foreach ($paginator->items() as $sup) {
                    $isActive = (bool) ($sup->is_active ?? true);
                    $matchKind = 'catalog';
                    if ($query !== null) {
                        if (is_numeric($query) && (int) $query === (int) $sup->id) {
                            $matchKind = 'exact_id';
                        } elseif (strcasecmp((string) $sup->name, $query) === 0 || strcasecmp((string) $sup->company_name, $query) === 0) {
                            $matchKind = 'exact_name';
                        } else {
                            $matchKind = 'partial_name';
                        }
                    }

                    $items[] = [
                        'id' => (int) $sup->id,
                        'name' => (string) $sup->name,
                        'display' => (string) ($sup->company_name ?: $sup->name),
                        'code' => (string) ($sup->vat_number ?? ''),
                        'branch_id' => null,
                        'branch_name' => null,
                        'branch_label' => null,
                        'location' => (string) ($sup->city ? "{$sup->city}, {$sup->state}" : ''),
                        'is_active' => $isActive,
                        'eligible' => $isActive,
                        'disabled_reason' => $isActive ? null : 'inactive',
                        'match_kind' => $matchKind,
                        'metadata' => [
                            'gstin' => $sup->vat_number,
                            'company_name' => $sup->company_name,
                            'phone' => $sup->phone_number,
                        ],
                    ];
                }

                $resolution = $query !== null ? $this->resolutionService->resolveSupplier($context, $query) : ['status' => 'none', 'supplier' => null];
                if ($resolution['status'] === 'exact' && $resolution['supplier']) {
                    $recommendedId = (int) $resolution['supplier']->id;
                    $recommendationReason = 'exact_match';
                    $status = 'suggested';
                } else {
                    $recommendedId = null;
                    $recommendationReason = null;
                    $status = ($total === 0) ? 'no_matches' : 'selection_needed';
                }
                break;

            case 'product':
                $queryBuilder = Product::withoutGlobalScopes();
                $queryBuilder->getQuery()->withoutCompanyScope();
                $queryBuilder->where('products.company_id', $context->companyId);

                if ($query !== null) {
                    $escaped = addcslashes($query, '%_');
                    $queryBuilder->where(function ($qb) use ($query, $escaped) {
                        if (is_numeric($query)) {
                            $qb->orWhere('id', (int) $query);
                        }
                        $qb->orWhere('name', 'LIKE', "%{$escaped}%")
                            ->orWhere('code', 'LIKE', "%{$escaped}%")
                            ->orWhere('hsn_code', 'LIKE', "%{$escaped}%");
                    });
                }

                $queryBuilder->orderByDesc('is_active')->orderBy('name');
                $paginator = $queryBuilder->paginate($perPage, ['*'], 'page', $page);
                $total = $paginator->total();
                $hasMore = $paginator->hasMorePages();

                $items = [];
                foreach ($paginator->items() as $prod) {
                    $isActive = (bool) ($prod->is_active ?? true);
                    $matchKind = 'catalog';
                    if ($query !== null) {
                        if (is_numeric($query) && (int) $query === (int) $prod->id) {
                            $matchKind = 'exact_id';
                        } elseif (strcasecmp((string) $prod->code, $query) === 0) {
                            $matchKind = 'exact_code';
                        } elseif (strcasecmp((string) $prod->name, $query) === 0) {
                            $matchKind = 'exact_name';
                        } else {
                            $matchKind = 'partial_name';
                        }
                    }

                    $items[] = [
                        'id' => (int) $prod->id,
                        'name' => (string) $prod->name,
                        'display' => $prod->code ? "[{$prod->code}] {$prod->name}" : (string) $prod->name,
                        'code' => (string) ($prod->code ?? ''),
                        'branch_id' => null,
                        'branch_name' => null,
                        'branch_label' => null,
                        'location' => null,
                        'is_active' => $isActive,
                        'eligible' => $isActive,
                        'disabled_reason' => $isActive ? null : 'inactive',
                        'match_kind' => $matchKind,
                        'metadata' => [
                            'cost' => $prod->cost,
                            'price' => $prod->price,
                            'hsn_code' => $prod->hsn_code,
                            'unit_id' => $prod->unit_id,
                        ],
                    ];
                }

                $resolution = $query !== null ? $this->resolutionService->resolveProduct($context, $query) : ['status' => 'none', 'product' => null];
                if ($resolution['status'] === 'exact' && $resolution['product']) {
                    $recommendedId = (int) $resolution['product']->id;
                    $recommendationReason = 'exact_match';
                    $status = 'suggested';
                } else {
                    $recommendedId = null;
                    $recommendationReason = null;
                    $status = ($total === 0) ? 'no_matches' : 'selection_needed';
                }
                break;

            case 'customer':
                $queryBuilder = Customer::withoutGlobalScopes();
                $queryBuilder->getQuery()->withoutCompanyScope();
                $queryBuilder->where('customers.company_id', $context->companyId);

                if ($query !== null) {
                    $escaped = addcslashes($query, '%_');
                    $queryBuilder->where(function ($qb) use ($query, $escaped) {
                        if (is_numeric($query)) {
                            $qb->orWhere('id', (int) $query);
                        }
                        $qb->orWhere('name', 'LIKE', "%{$escaped}%")
                            ->orWhere('company_name', 'LIKE', "%{$escaped}%")
                            ->orWhere('tax_no', 'LIKE', "%{$escaped}%")
                            ->orWhere('phone_number', 'LIKE', "%{$escaped}%");
                    });
                }

                $queryBuilder->orderByDesc('is_active')->orderBy('name');
                $paginator = $queryBuilder->paginate($perPage, ['*'], 'page', $page);
                $total = $paginator->total();
                $hasMore = $paginator->hasMorePages();

                $items = [];
                foreach ($paginator->items() as $cust) {
                    $isActive = (bool) ($cust->is_active ?? true);
                    $matchKind = 'catalog';
                    if ($query !== null) {
                        if (is_numeric($query) && (int) $query === (int) $cust->id) {
                            $matchKind = 'exact_id';
                        } elseif (strcasecmp((string) $cust->name, $query) === 0) {
                            $matchKind = 'exact_name';
                        } else {
                            $matchKind = 'partial_name';
                        }
                    }

                    $items[] = [
                        'id' => (int) $cust->id,
                        'name' => (string) $cust->name,
                        'display' => (string) ($cust->company_name ?: $cust->name),
                        'code' => (string) ($cust->tax_no ?? ''),
                        'branch_id' => null,
                        'branch_name' => null,
                        'branch_label' => null,
                        'location' => (string) ($cust->city ? "{$cust->city}, {$cust->state}" : ''),
                        'is_active' => $isActive,
                        'eligible' => $isActive,
                        'disabled_reason' => $isActive ? null : 'inactive',
                        'match_kind' => $matchKind,
                        'metadata' => [
                            'tax_no' => $cust->tax_no,
                            'phone' => $cust->phone_number,
                        ],
                    ];
                }

                $resolution = $query !== null ? $this->resolutionService->resolveCustomer($context, $query) : ['status' => 'none', 'customer' => null];
                if ($resolution['status'] === 'exact' && $resolution['customer']) {
                    $recommendedId = (int) $resolution['customer']->id;
                    $recommendationReason = 'exact_match';
                    $status = 'suggested';
                } else {
                    $recommendedId = null;
                    $recommendationReason = null;
                    $status = ($total === 0) ? 'no_matches' : 'selection_needed';
                }
                break;

            case 'unit':
                $queryBuilder = Unit::withoutGlobalScopes();
                $queryBuilder->getQuery()->withoutCompanyScope();
                $queryBuilder->where('units.company_id', $context->companyId);

                if ($query !== null) {
                    $escaped = addcslashes($query, '%_');
                    $queryBuilder->where(function ($qb) use ($query, $escaped) {
                        if (is_numeric($query)) {
                            $qb->orWhere('id', (int) $query);
                        }
                        $qb->orWhere('unit_code', 'LIKE', "%{$escaped}%")
                            ->orWhere('unit_name', 'LIKE', "%{$escaped}%");
                    });
                }

                $queryBuilder->orderByDesc('is_active')->orderBy('unit_name');
                $paginator = $queryBuilder->paginate($perPage, ['*'], 'page', $page);
                $total = $paginator->total();
                $hasMore = $paginator->hasMorePages();

                $items = [];
                foreach ($paginator->items() as $unit) {
                    $isActive = (bool) ($unit->is_active ?? true);
                    $matchKind = 'catalog';
                    if ($query !== null) {
                        if (is_numeric($query) && (int) $query === (int) $unit->id) {
                            $matchKind = 'exact_id';
                        } elseif (strcasecmp((string) $unit->unit_code, $query) === 0) {
                            $matchKind = 'exact_code';
                        } else {
                            $matchKind = 'partial_name';
                        }
                    }

                    $items[] = [
                        'id' => (int) $unit->id,
                        'name' => (string) $unit->unit_name,
                        'display' => "{$unit->unit_name} ({$unit->unit_code})",
                        'code' => (string) ($unit->unit_code ?? ''),
                        'branch_id' => null,
                        'branch_name' => null,
                        'branch_label' => null,
                        'location' => null,
                        'is_active' => $isActive,
                        'eligible' => $isActive,
                        'disabled_reason' => $isActive ? null : 'inactive',
                        'match_kind' => $matchKind,
                        'metadata' => [
                            'unit_code' => $unit->unit_code,
                            'base_unit' => $unit->base_unit,
                        ],
                    ];
                }

                if ($query !== null) {
                    $exactUnit = Unit::withoutGlobalScopes()
                        ->where('company_id', $context->companyId)
                        ->where('is_active', true)
                        ->where(function ($q) use ($query) {
                            $q->where('unit_code', $query)
                                ->orWhere('unit_name', $query);
                        })
                        ->first();
                    if ($exactUnit) {
                        $recommendedId = (int) $exactUnit->id;
                        $recommendationReason = 'exact_match';
                        $status = 'suggested';
                    } else {
                        $recommendedId = null;
                        $recommendationReason = null;
                        $status = ($total === 0) ? 'no_matches' : 'selection_needed';
                    }
                } else {
                    $recommendedId = null;
                    $recommendationReason = null;
                    $status = ($total === 0) ? 'no_matches' : 'selection_needed';
                }
                break;
        }

        return [
            'entity_type' => $entityType,
            'field' => $fieldPath,
            'task_id' => $taskId,
            'task_revision' => $taskRevision,
            'status' => $status,
            'recommended_id' => $recommendedId,
            'recommendation_reason' => $recommendationReason,
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $hasMore,
            'can_create' => $canCreate,
            'create_disabled_reason' => $createDisabledReason,
        ];
    }
}
