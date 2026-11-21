<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionSecurity;
use App\Models\Customer;
use App\Models\GoodsReceivedNote;
use App\Models\Operations\JobWorkDispatch;
use App\Models\Operations\JobWorkOrder;
use App\Models\Operations\JobWorkReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Narrow, permission-checked, company-scoped read-only fact projection.
 * Accepts ONLY a literal fact type + positive numeric ID, never SQL or model names.
 */
final class ScopedBusinessFactReader
{
    private const READ_PERMISSIONS = [
        'supplier' => ['suppliers-index', 'suppliers-add'],
        'customer' => ['customers-index', 'customers-add'],
        'product' => ['products-index', 'products-add'],
        'warehouse' => ['warehouse', 'warehouse-index'],
        'purchase_order' => ['purchases-index', 'purchases-add'],
        'purchase_bill' => ['purchases-index', 'purchases-add'],
        'grn' => ['purchases-index', 'purchases-add'],
        'job_work_order' => ['job_work.view', 'job_work.dispatch'],
        'job_work_dispatch' => ['job_work.view', 'job_work.dispatch'],
        'job_work_receipt' => ['job_work.view', 'job_work.receive'],
    ];

    public function __construct(
        private readonly ActionSecurity $security,
        private readonly CommercialPermission $permissions,
    ) {}

    /** @return list<string> */
    public static function supportedKinds(): array
    {
        return array_keys(self::READ_PERMISSIONS);
    }

    public static function assertRequest(string $kind, int $id): void
    {
        if (!array_key_exists($kind, self::READ_PERMISSIONS) || $id <= 0) {
            throw new InvalidArgumentException('Unsupported business fact request.');
        }
    }

    /** @return array<string, mixed> */
    public function find(AiPrincipal $principal, string $kind, int $id): array
    {
        self::assertRequest($kind, $id);
        if (!config('ai_business_knowledge.enabled', false)) {
            throw new AuthorizationException('Business knowledge retrieval is disabled.');
        }
        $this->security->assertEnabled($principal);
        $context = $this->security->context($principal);
        $candidates = self::READ_PERMISSIONS[$kind];
        $allowed = $this->permissions->allowed($candidates, $context, $principal->actorId);
        if ($allowed === []) {
            throw new AuthorizationException("Read permission required for fact kind {$kind}: one of [".implode(', ', $candidates).'].');
        }
        $query = match ($kind) {
            'supplier' => Supplier::forCompany($context),
            'customer' => Customer::forCompany($context),
            'product' => Product::forCompany($context),
            'warehouse' => Warehouse::forCompany($context)->where(fn (Builder $q) =>
                $q->where('branch_id', $context->branchId)->orWhereNull('branch_id')),
            'purchase_order', 'purchase_bill' => Purchase::visibleIn($context),
            'grn' => GoodsReceivedNote::forCompany($context)->where('branch_id', $context->branchId),
            'job_work_order' => JobWorkOrder::visibleIn($context),
            'job_work_dispatch' => JobWorkDispatch::visibleIn($context),
            'job_work_receipt' => JobWorkReceipt::visibleIn($context),
        };
        if (in_array($kind, ['purchase_order', 'purchase_bill', 'grn', 'job_work_order', 'job_work_dispatch', 'job_work_receipt'], true)) {
            // Legacy tables without FY ownership are deliberately NOT exposed by this new reader.
            $table = $query->getModel()->getTable();
            if (!Schema::hasColumn($table, 'financial_year_id')) {
                throw new AuthorizationException('This document type does not provide verified financial-year isolation.');
            }
            $query->where($table.'.financial_year_id', $context->financialYearId);
        }
        if ($kind === 'purchase_order') $query->where('status', 4);
        if ($kind === 'purchase_bill') $query->where('status', '!=', 4);
        $record = $query->whereKey($id)->first();
        if (!$record) {
            // Identical response for another tenant, another year, or an unknown ID.
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        }

        $fields = match ($kind) {
            'supplier', 'customer' => ['id', 'name', 'is_active', 'city', 'state'],
            'product' => ['id', 'name', 'code', 'type', 'is_active'],
            'warehouse' => ['id', 'name', 'branch_id', 'is_active'],
            'purchase_order', 'purchase_bill' => ['id', 'reference_no', 'status', 'supplier_id', 'warehouse_id', 'posted_at'],
            'grn' => ['id', 'purchase_id', 'supplier_id', 'warehouse_id', 'posted_at'],
            'job_work_order' => ['id', 'reference_no', 'job_worker_party_id', 'posted_at'],
            'job_work_dispatch' => ['id', 'job_work_order_id', 'posted_at', 'reversed_at'],
            'job_work_receipt' => ['id', 'dispatch_id', 'posted_at', 'service_purchase_id'],
        };
        $facts = [];
        foreach ($fields as $field) {
            $value = $record->getAttribute($field);
            if ($value instanceof \DateTimeInterface) $value = $value->format(DATE_ATOM);
            $facts[$field] = is_scalar($value) || $value === null ? $value : null;
        }
        return [
            'kind' => $kind, 'source' => 'tenant_fact', 'as_of' => now('UTC')->toIso8601String(),
            'scope' => ['company_id' => $context->companyId, 'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId],
            'facts' => $facts,
            'can_create' => false,
            'writes_executed' => false,
        ];
    }
}
