<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiEntityOptionsController extends Controller
{
    public function __construct(
        private readonly CompanyContextResolver $contextResolver,
        private readonly CommercialPermission $permissions,
    ) {
    }

    /**
     * GET /api/v1/ai/entity-options
     * Scoped, multi-tenant directory options for AI entity selection and disambiguation.
     */
    public function index(Request $request): JsonResponse
    {
        if (!config('ai_actions.foundation_enabled', false)) {
            abort(403, 'AI action foundation is disabled.');
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:warehouse,supplier,product,customer,unit'],
            'q' => ['nullable', 'string', 'max:120'],
            'task_id' => ['nullable', 'string', 'max:120'],
            'field' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        $actorId = (int) $user->id;
        $context = $this->contextResolver->forActor(userId: $actorId);
        $this->permissions->assert('ai.use', $context, $actorId);

        $type = $validated['type'];
        $rawQuery = trim((string) ($validated['q'] ?? ''));
        $taskId = $validated['task_id'] ?? null;
        $field = $validated['field'] ?? ($type === 'unit' ? 'unit_id' : $type . '_id');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        // Sanitize LIKE query
        $q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $rawQuery);

        [$perm, $capabilityKey] = match ($type) {
            'warehouse' => ['warehouse', 'setup.warehouse.create.v1'],
            'supplier' => ['suppliers-add', 'setup.supplier.create.v1'],
            'product' => ['products-add', 'setup.product.create.v1'],
            'customer' => ['customers-add', 'setup.customer.create.v1'],
            'unit' => ['unit', 'setup.unit.create.v1'],
        };

        $canCreate = $this->permissions->allows($perm, $context, $actorId);
        $createDisabledReason = $canCreate ? null : "Requires {$perm} permission";

        $recommendedId = null;
        $recommendationReason = null;

        if ($type === 'warehouse') {
            $builder = Warehouse::with('branch')
                ->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('is_active', true);

            if ($q !== '') {
                $builder->where(function ($sub) use ($q, $rawQuery) {
                    $sub->where('name', 'LIKE', "%{$q}%")
                        ->orWhere('address', 'LIKE', "%{$q}%");
                });
            }

            $total = $builder->count();
            $records = $builder->orderBy('name', 'asc')
                ->forPage($page, $perPage)
                ->get();

            $items = $records->map(function (Warehouse $wh) use ($rawQuery): array {
                $matchKind = 'directory';
                if ($rawQuery !== '') {
                    $matchKind = (stripos((string) $wh->name, $rawQuery) !== false) ? 'name_contains' : 'address_contains';
                }

                return [
                    'id' => (int) $wh->id,
                    'name' => (string) $wh->name,
                    'code' => (string) ($wh->code ?? ''),
                    'branch_id' => (int) $wh->branch_id,
                    'branch_name' => $wh->branch ? (string) $wh->branch->name : null,
                    'location' => (string) ($wh->address ?? ''),
                    'is_active' => (bool) $wh->is_active,
                    'match_kind' => $matchKind,
                ];
            })->all();

            if ($rawQuery === '' && $total === 1 && !empty($items)) {
                $recommendedId = (int) $items[0]['id'];
                $recommendationReason = 'Only active warehouse in this branch';
            } elseif ($rawQuery !== '' && $total === 1 && !empty($items)) {
                $recommendedId = (int) $items[0]['id'];
                $recommendationReason = 'Single matching warehouse in active branch';
            }
        } elseif ($type === 'supplier') {
            $builder = Supplier::where('company_id', $context->companyId)
                ->where('is_active', true);

            if ($q !== '') {
                $builder->where(function ($sub) use ($q) {
                    $sub->where('name', 'LIKE', "%{$q}%")
                        ->orWhere('company_name', 'LIKE', "%{$q}%")
                        ->orWhere('vat_number', 'LIKE', "%{$q}%")
                        ->orWhere('phone_number', 'LIKE', "%{$q}%");
                });
            }

            $total = $builder->count();
            $records = $builder->orderBy('name', 'asc')
                ->forPage($page, $perPage)
                ->get();

            $items = $records->map(function (Supplier $s) use ($rawQuery): array {
                return [
                    'id' => (int) $s->id,
                    'name' => (string) ($s->company_name ?: $s->name),
                    'code' => (string) ($s->vat_number ?? ''),
                    'gstin' => (string) ($s->vat_number ?? ''),
                    'phone' => (string) ($s->phone_number ?? ''),
                    'email' => (string) ($s->email ?? ''),
                    'is_active' => (bool) $s->is_active,
                    'match_kind' => $rawQuery !== '' ? 'query_match' : 'directory',
                ];
            })->all();

            if ($total === 1 && !empty($items)) {
                $recommendedId = (int) $items[0]['id'];
                $recommendationReason = 'Single matching supplier';
            }
        } elseif ($type === 'product') {
            $builder = Product::where('company_id', $context->companyId)
                ->where('is_active', true);

            if ($q !== '') {
                $builder->where(function ($sub) use ($q) {
                    $sub->where('name', 'LIKE', "%{$q}%")
                        ->orWhere('code', 'LIKE', "%{$q}%");
                });
            }

            $total = $builder->count();
            $records = $builder->orderBy('name', 'asc')
                ->forPage($page, $perPage)
                ->get();

            $items = $records->map(function (Product $p) use ($rawQuery): array {
                return [
                    'id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'code' => (string) $p->code,
                    'cost' => (float) ($p->cost ?? 0),
                    'price' => (float) ($p->price ?? 0),
                    'unit_id' => (int) ($p->unit_id ?? 1),
                    'is_active' => (bool) $p->is_active,
                    'match_kind' => $rawQuery !== '' ? 'query_match' : 'directory',
                ];
            })->all();

            if ($total === 1 && !empty($items)) {
                $recommendedId = (int) $items[0]['id'];
                $recommendationReason = 'Single matching product';
            }
        } elseif ($type === 'customer') {
            $builder = Customer::where('company_id', $context->companyId)
                ->where('is_active', true);

            if ($q !== '') {
                $builder->where(function ($sub) use ($q) {
                    $sub->where('name', 'LIKE', "%{$q}%")
                        ->orWhere('company_name', 'LIKE', "%{$q}%")
                        ->orWhere('tax_no', 'LIKE', "%{$q}%")
                        ->orWhere('phone_number', 'LIKE', "%{$q}%");
                });
            }

            $total = $builder->count();
            $records = $builder->orderBy('name', 'asc')
                ->forPage($page, $perPage)
                ->get();

            $items = $records->map(function (Customer $c) use ($rawQuery): array {
                return [
                    'id' => (int) $c->id,
                    'name' => (string) ($c->name ?: $c->company_name),
                    'code' => (string) ($c->tax_no ?? ''),
                    'phone' => (string) ($c->phone_number ?? ''),
                    'is_active' => (bool) $c->is_active,
                    'match_kind' => $rawQuery !== '' ? 'query_match' : 'directory',
                ];
            })->all();

            if ($total === 1 && !empty($items)) {
                $recommendedId = (int) $items[0]['id'];
                $recommendationReason = 'Single matching customer';
            }
        } else {
            // unit
            $builder = Unit::where('company_id', $context->companyId)
                ->where('is_active', true);

            if ($q !== '') {
                $builder->where(function ($sub) use ($q) {
                    $sub->where('unit_name', 'LIKE', "%{$q}%")
                        ->orWhere('unit_code', 'LIKE', "%{$q}%");
                });
            }

            $total = $builder->count();
            $records = $builder->orderBy('unit_name', 'asc')
                ->forPage($page, $perPage)
                ->get();

            $items = $records->map(function (Unit $u) use ($rawQuery): array {
                return [
                    'id' => (int) $u->id,
                    'name' => (string) $u->unit_name,
                    'code' => (string) $u->unit_code,
                    'is_active' => (bool) $u->is_active,
                    'match_kind' => $rawQuery !== '' ? 'query_match' : 'directory',
                ];
            })->all();

            if ($total === 1 && !empty($items)) {
                $recommendedId = (int) $items[0]['id'];
                $recommendationReason = 'Single matching unit';
            }
        }

        $status = 'selection_needed';
        if ($recommendedId !== null) {
            $status = 'recommended';
        } elseif ($total === 0) {
            $status = 'none';
        }

        return response()->json([
            'entity_type' => $type,
            'field' => $field,
            'task_id' => $taskId,
            'task_revision' => 1,
            'status' => $status,
            'recommended_id' => $recommendedId,
            'recommendation_reason' => $recommendationReason,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
            'can_create' => $canCreate,
            'create_disabled_reason' => $createDisabledReason,
        ]);
    }
}
