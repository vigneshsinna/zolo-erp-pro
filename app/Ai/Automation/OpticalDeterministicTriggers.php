<?php

declare(strict_types=1);

namespace App\Ai\Automation;

use App\Ai\Knowledge\IndustryContextResolver;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zero-LLM deterministic optical exception detector.
 * Evaluates company/branch scoped SQL facts and returns draft suggestions only.
 */
final class OpticalDeterministicTriggers
{
    public function __construct(
        private readonly IndustryContextResolver $industryResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(CompanyContext $context, int $actorId): array
    {
        if (!config('ai_business_knowledge.opticals_enabled', false)) {
            return [
                'enabled' => false,
                'profile' => 'disabled',
                'triggers' => [],
            ];
        }

        $resolved = $this->industryResolver->resolve($context);
        if (!$resolved['is_optical']) {
            return [
                'enabled' => false,
                'profile' => $resolved['profile'],
                'triggers' => [],
            ];
        }

        $branchId = $context->branchId;

        return [
            'enabled' => true,
            'profile' => $resolved['profile'],
            'subtype' => $resolved['subtype'],
            'actor_id' => $actorId,
            'company_id' => $context->companyId,
            'branch_id' => $branchId,
            'model_calls' => 0,
            'triggers' => [
                'low_exact_power_stock' => $this->lowExactPowerStock($context->companyId, $branchId),
                'unfilled_dealer_orders' => $this->unfilledDealerOrders($context->companyId, $branchId),
                'grn_awaiting_bill' => $this->grnAwaitingBill($context->companyId, $branchId),
                'dispatched_unbilled_lenses' => $this->dispatchedUnbilledLenses($context->companyId, $branchId),
                'overdue_rx_lab_jobs' => $resolved['has_lab_capability']
                    ? $this->overdueRxLabJobs($context->companyId, $branchId)
                    : [],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lowExactPowerStock(int $companyId, ?int $branchId): array
    {
        if (!Schema::hasTable('optical_lens_identities') || !Schema::hasTable('products')) {
            return [];
        }

        $query = DB::table('optical_lens_identities as oli')
            ->join('products as p', 'p.id', '=', 'oli.product_id')
            ->where('oli.company_id', $companyId)
            ->where('p.company_id', $companyId)
            ->where('p.is_active', true)
            ->where('p.alert_quantity', '>', 0)
            ->whereColumn('p.qty', '<=', 'p.alert_quantity');

        if ($branchId !== null && Schema::hasTable('product_warehouse')) {
            $query->leftJoin('product_warehouse as pw', 'pw.product_id', '=', 'p.id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
                ->where(function ($q) use ($branchId): void {
                    $q->where('w.branch_id', $branchId)->orWhereNull('pw.id');
                });
        }

        return $query->select([
            'oli.id as optical_lens_identity_id',
            'oli.product_id',
            'p.name as product_name',
            'oli.lens_family',
            'oli.sph',
            'oli.cyl',
            'oli.lr',
            'p.qty',
            'p.alert_quantity',
        ])
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'optical_lens_identity_id' => (int) $row->optical_lens_identity_id,
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'lens_family' => $row->lens_family,
                'sph' => $row->sph,
                'cyl' => $row->cyl,
                'lr' => $row->lr,
                'qty' => (float) $row->qty,
                'alert_quantity' => (float) $row->alert_quantity,
                'suggested_action' => 'purchase_order.create.v1',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unfilledDealerOrders(int $companyId, ?int $branchId): array
    {
        if (!Schema::hasTable('sales_orders')) {
            return [];
        }

        $query = DB::table('sales_orders')
            ->where('company_id', $companyId)
            ->whereIn('status', ['confirmed', 'partially_fulfilled'])
            ->orderBy('business_date');

        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId): void {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->select(['id', 'reference_no', 'customer_id', 'status', 'business_date'])
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'sales_order_id' => (int) $row->id,
                'reference_no' => (string) ($row->reference_no ?? ''),
                'customer_id' => (int) $row->customer_id,
                'status' => (string) $row->status,
                'business_date' => $row->business_date,
                'suggested_action' => 'delivery_challan.create.v1',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function grnAwaitingBill(int $companyId, ?int $branchId): array
    {
        if (!Schema::hasTable('goods_received_notes')) {
            return [];
        }

        $query = DB::table('goods_received_notes')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'reversed'])
            ->whereNull('purchase_id');

        if ($branchId !== null && Schema::hasColumn('goods_received_notes', 'branch_id')) {
            $query->where(function ($q) use ($branchId): void {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->select(['id', 'grn_no', 'supplier_id', 'grn_date'])
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'grn_id' => (int) $row->id,
                'grn_no' => (string) ($row->grn_no ?? ''),
                'supplier_id' => (int) ($row->supplier_id ?? 0),
                'grn_date' => $row->grn_date,
                'suggested_action' => 'purchase_bill.create.v1',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dispatchedUnbilledLenses(int $companyId, ?int $branchId): array
    {
        if (!Schema::hasTable('delivery_challans')) {
            return [];
        }

        $query = DB::table('delivery_challans')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'reversed'])
            ->whereNull('sale_id');

        if ($branchId !== null && Schema::hasColumn('delivery_challans', 'branch_id')) {
            $query->where(function ($q) use ($branchId): void {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->select(['id', 'challan_no', 'customer_id', 'challan_date'])
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'delivery_challan_id' => (int) $row->id,
                'challan_no' => (string) ($row->challan_no ?? ''),
                'customer_id' => (int) ($row->customer_id ?? 0),
                'challan_date' => $row->challan_date,
                'suggested_action' => 'sales_invoice.create.v1',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overdueRxLabJobs(int $companyId, ?int $branchId): array
    {
        if (!Schema::hasTable('optical_rx_jobs') || !config('ai_business_knowledge.opticals_lab_enabled', false)) {
            return [];
        }

        $query = DB::table('optical_rx_jobs')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['dispatched', 'delivered', 'reconciled', 'cancelled'])
            ->whereNotNull('promised_date')
            ->where('promised_date', '<', now('UTC')->toDateString());

        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId): void {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->select(['id', 'job_no', 'status', 'promised_date'])
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'rx_job_id' => (string) $row->id,
                'job_no' => (string) $row->job_no,
                'status' => (string) $row->status,
                'promised_date' => $row->promised_date,
                'suggested_action' => 'optical.rx_job.review',
            ])
            ->all();
    }
}
