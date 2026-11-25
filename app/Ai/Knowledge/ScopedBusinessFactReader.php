<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionSecurity;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\GoodsReceivedNote;
use App\Models\Operations\JobWorkDispatch;
use App\Models\Operations\JobWorkOrder;
use App\Models\Operations\JobWorkReceipt;
use App\Models\OpticalRxJob;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Narrow, permission-checked, company-scoped read-only fact projection.
 * Accepts ONLY a literal fact type + positive numeric ID (or UUID for optical_rx_job), never SQL or model names.
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
        'sales_order' => ['sales-index', 'sales-add'],
        'sales_invoice' => ['sales-index', 'sales-add'],
        'quotation' => ['quotes-index', 'sales-index'],
        'delivery_challan' => ['sales-index', 'sales-add'],
        'optical_lens_identity' => ['products-index', 'products-add'],
        'optical_rx_job' => ['sales-index', 'sales-add'],
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

    public static function assertRequest(string $kind, int|string $id): void
    {
        if (!array_key_exists($kind, self::READ_PERMISSIONS)) {
            throw new InvalidArgumentException('Unsupported business fact request.');
        }
        if ($kind === 'optical_rx_job') {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                throw new InvalidArgumentException('Unsupported business fact request.');
            }
            return;
        }
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new InvalidArgumentException('Unsupported business fact request.');
        }
        if ((int) $id <= 0) {
            throw new InvalidArgumentException('Unsupported business fact request.');
        }
    }

    /** @return array<string, mixed> */
    public function find(AiPrincipal $principal, string $kind, int|string $id): array
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

        if ($kind === 'optical_lens_identity') {
            $industry = app(IndustryContextResolver::class);
            if (!config('ai_business_knowledge.opticals_enabled', false) || !$industry->isOptical($context)) {
                throw new AuthorizationException('Optical lens identity facts require an active Opticals company profile.');
            }
            $row = DB::table('optical_lens_identities')
                ->where('company_id', $context->companyId)
                ->where('id', (int) $id)
                ->first();
            if (!$row) {
                throw new ModelNotFoundException;
            }
            return [
                'kind' => $kind,
                'source' => 'tenant_fact',
                'as_of' => now('UTC')->toIso8601String(),
                'scope' => [
                    'company_id' => $context->companyId,
                    'branch_id' => $context->branchId,
                    'financial_year_id' => $context->financialYearId,
                ],
                'facts' => [
                    'id' => (int) $row->id,
                    'product_id' => (int) $row->product_id,
                    'lens_family' => $row->lens_family,
                    'lens_material' => $row->lens_material,
                    'refractive_index' => $row->refractive_index,
                    'coating' => $row->coating,
                    'lens_form' => $row->lens_form,
                    'sph' => $row->sph,
                    'cyl' => $row->cyl,
                    'axis' => $row->axis,
                    'addition' => $row->addition,
                    'base_curve' => $row->base_curve,
                    'dia' => $row->dia,
                    'lr' => $row->lr,
                    'spec_signature' => $row->spec_signature,
                    'is_active' => (bool) $row->is_active,
                ],
                'can_create' => false,
                'writes_executed' => false,
            ];
        }

        if ($kind === 'optical_rx_job') {
            $industry = app(IndustryContextResolver::class);
            if (!$industry->hasLabCapability($context)) {
                throw new AuthorizationException('Optical Rx lab job facts require an active Opticals Rx Lab profile.');
            }
            $job = OpticalRxJob::where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)
                ->where('id', (string) $id)
                ->first();
            if (!$job) {
                throw new ModelNotFoundException;
            }
            return [
                'kind' => $kind,
                'source' => 'tenant_fact',
                'as_of' => now('UTC')->toIso8601String(),
                'scope' => [
                    'company_id' => $context->companyId,
                    'branch_id' => $context->branchId,
                    'financial_year_id' => $context->financialYearId,
                ],
                'facts' => [
                    'id' => (string) $job->id,
                    'job_no' => $job->job_no,
                    'customer_id' => (int) $job->customer_id,
                    'customer_order_no' => $job->customer_order_no,
                    'fulfillment_route' => $job->fulfillment_route,
                    'status' => $job->status,
                    'revision' => (int) $job->revision,
                    'qty_requested' => (string) $job->qty_requested,
                    'qty_accepted' => (string) $job->qty_accepted,
                    'qty_failed' => (string) $job->qty_failed,
                    'qty_packed' => (string) $job->qty_packed,
                    'qty_dispatched' => (string) $job->qty_dispatched,
                    'promised_date' => $job->promised_date?->toDateString(),
                ],
                'can_create' => false,
                'writes_executed' => false,
            ];
        }

        $query = match ($kind) {
            'supplier' => Supplier::forCompany($context),
            'customer' => Customer::forCompany($context),
            'product' => Product::forCompany($context),
            'warehouse' => Warehouse::forCompany($context)->where(fn (Builder $q) =>
                $q->where('branch_id', $context->branchId)->orWhereNull('branch_id')),
            'purchase_order', 'purchase_bill' => Purchase::visibleIn($context),
            'grn' => GoodsReceivedNote::forCompany($context)->where('branch_id', $context->branchId),
            'sales_order' => SalesOrder::visibleIn($context),
            'sales_invoice' => Sale::visibleIn($context),
            'quotation' => Quotation::forCompany($context),
            'delivery_challan' => DeliveryChallan::forCompany($context)->where('branch_id', $context->branchId),
            'job_work_order' => JobWorkOrder::visibleIn($context),
            'job_work_dispatch' => JobWorkDispatch::visibleIn($context),
            'job_work_receipt' => JobWorkReceipt::visibleIn($context),
        };
        if (in_array($kind, ['purchase_order', 'purchase_bill', 'grn', 'sales_order', 'sales_invoice', 'delivery_challan', 'job_work_order', 'job_work_dispatch', 'job_work_receipt'], true)) {
            // Legacy tables without FY ownership are deliberately NOT exposed by this new reader.
            $table = $query->getModel()->getTable();
            if (!Schema::hasColumn($table, 'financial_year_id')) {
                throw new AuthorizationException('This document type does not provide verified financial-year isolation.');
            }
            $query->where($table.'.financial_year_id', $context->financialYearId);
        }
        if ($kind === 'purchase_order') $query->where('status', 4);
        if ($kind === 'purchase_bill') $query->where('status', '!=', 4);
        $record = $query->whereKey((int) $id)->first();
        if (!$record) {
            // Identical response for another tenant, another year, or an unknown ID.
            throw new ModelNotFoundException;
        }

        $fields = match ($kind) {
            'supplier', 'customer' => ['id', 'name', 'is_active', 'city', 'state'],
            'product' => ['id', 'name', 'code', 'type', 'is_active'],
            'warehouse' => ['id', 'name', 'branch_id', 'is_active'],
            'purchase_order', 'purchase_bill' => ['id', 'reference_no', 'status', 'supplier_id', 'warehouse_id', 'posted_at'],
            'grn' => ['id', 'purchase_id', 'supplier_id', 'warehouse_id', 'posted_at'],
            'sales_order' => ['id', 'reference_no', 'status', 'customer_id', 'warehouse_id', 'business_date', 'promised_date'],
            'sales_invoice' => ['id', 'reference_no', 'sale_status', 'payment_status', 'customer_id', 'warehouse_id', 'customer_order_no', 'posted_at'],
            'quotation' => ['id', 'reference_no', 'quotation_status', 'customer_id', 'warehouse_id'],
            'delivery_challan' => ['id', 'challan_no', 'purpose', 'status', 'customer_id', 'warehouse_id', 'posted_at'],
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
