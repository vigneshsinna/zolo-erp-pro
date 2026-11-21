<?php

namespace App\Services\JobWork;

use App\Models\Customer;
use App\Models\Product;
use App\Services\Commercial\{SaleApplicationService, SaleCommand};
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Platform\{CapabilityService, CompanyContext};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Job work done FOR a principal (customer-owned material): their grey arrives on their delivery challan, stays theirs
 * (not in our stock or books), goes back processed on our return challan with the process loss recorded, and only the
 * processing charge is invoiced to them (SAC 9988, 5% for textiles).
 */
class PrincipalJobService
{
    public function order(array $data, CompanyContext $context, int $actor): int
    {
        $this->authorize($context, $actor);
        $data = validator($data, ['customer_id' => 'required|integer|min:1', 'business_date' => 'required|date_format:Y-m-d', 'process' => 'required|string|max:100',
            'service_product_id' => 'required|integer|min:1', 'rate' => 'required|numeric|min:0|max:999999999', 'max_loss_percent' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:2000'])->validate();
        $guard = app(CompanyWriteGuard::class);
        $guard->owned(Customer::class, $data['customer_id'], $context, 'customer_id');
        if ($guard->owned(Product::class, $data['service_product_id'], $context, 'service_product_id')->type !== 'service') {
            throw ValidationException::withMessages(['service_product_id' => 'Choose the processing charge service item.']);
        }
        return DB::transaction(function () use ($data, $context, $actor) {
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $next = DB::table('principal_job_orders')->where('company_id', $context->companyId)->count() + 1;
            $row = [
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'customer_id' => $data['customer_id'],
                'reference_no' => 'PJO-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
                'business_date' => $data['business_date'],
                'process' => $data['process'],
                'service_product_id' => $data['service_product_id'],
                'rate' => $data['rate'],
                'max_loss_percent' => $data['max_loss_percent'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('principal_job_orders', 'financial_year_id')) {
                $row['financial_year_id'] = $context->financialYearId;
            }
            return DB::table('principal_job_orders')->insertGetId($row);
        });
    }

    /** The principal's material arrives on their challan. */
    public function receive(int $orderId, array $data, CompanyContext $context, int $actor): void
    {
        $order = $this->find($orderId, $context, $actor);
        $data = validator($data, ['business_date' => 'required|date_format:Y-m-d', 'challan_no' => 'required|string|max:60', 'challan_date' => 'required|date_format:Y-m-d',
            'lines' => 'required|array|min:1|max:100', 'lines.*.description' => 'required|string|max:255', 'lines.*.hsn_code' => 'nullable|digits_between:4,8',
            'lines.*.uqc' => 'required|string|max:10', 'lines.*.qty' => 'required|numeric|gt:0', 'lines.*.value' => 'nullable|numeric|min:0'])->validate();
        if ($data['business_date'] < $order->business_date) throw ValidationException::withMessages(['business_date' => 'Receipt cannot precede the job order.']);
        $this->record($order, 'received', $data, $data['lines'], $actor);
    }

    /** Processed goods go back on our challan; loss is what the process consumed (shrinkage, singeing, waste). */
    public function returnGoods(int $orderId, array $data, CompanyContext $context, int $actor): void
    {
        $order = $this->find($orderId, $context, $actor);
        $data = validator($data, ['business_date' => 'required|date_format:Y-m-d', 'lines' => 'required|array|min:1|max:100',
            'lines.*.description' => 'required|string|max:255', 'lines.*.hsn_code' => 'nullable|digits_between:4,8', 'lines.*.uqc' => 'required|string|max:10',
            'lines.*.qty' => 'required|numeric|min:0', 'lines.*.loss_qty' => 'nullable|numeric|min:0'])->validate();
        DB::transaction(function () use ($order, $data, $actor) {
            DB::table('principal_job_orders')->where('id', $order->id)->lockForUpdate()->first();
            $balance = $this->balance($order->id);
            $returned = array_sum(array_column($data['lines'], 'qty')); $loss = array_sum(array_map(fn ($line) => (float) ($line['loss_qty'] ?? 0), $data['lines']));
            if ($returned + $loss <= 0 || $returned + $loss > $balance['with_us'] + 0.00005) {
                throw ValidationException::withMessages(['lines' => 'Return and loss cannot exceed the '.$balance['with_us'].' still with us.']);
            }
            if ($loss / ($returned + $loss) * 100 > (float) $order->max_loss_percent + 0.00005) {
                throw ValidationException::withMessages(['lines' => 'Loss is above the agreed '.(float) $order->max_loss_percent.'%.']);
            }
            $next = DB::table('principal_job_movements')->where('company_id', $order->company_id)->where('direction', 'returned')->distinct()->count('challan_no') + 1;
            $data['challan_no'] = 'PJR-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT); $data['challan_date'] = $data['business_date'];
            $this->record($order, 'returned', $data, $data['lines'], $actor);
            $losses = array_values(array_filter(array_map(fn ($line) => ['qty' => (float) ($line['loss_qty'] ?? 0)] + $line, $data['lines']), fn ($line) => $line['qty'] > 0));
            if ($losses) $this->record($order, 'loss', $data, $losses, $actor);
        });
    }

    /** Invoices the processing charge on returned quantity not billed yet. */
    public function bill(int $orderId, array $data, string $key, CompanyContext $context, int $actor)
    {
        $order = $this->find($orderId, $context, $actor);
        $data = validator($data, ['business_date' => 'required|date_format:Y-m-d', 'qty' => 'nullable|numeric|gt:0', 'rate' => 'nullable|numeric|min:0'])->validate();
        return DB::transaction(function () use ($order, $data, $key, $context, $actor) {
            DB::table('principal_job_orders')->where('id', $order->id)->lockForUpdate()->first();
            $balance = $this->balance($order->id);
            $qty = (float) ($data['qty'] ?? $balance['unbilled']);
            if ($qty <= 0 || $qty > $balance['unbilled'] + 0.00005) throw ValidationException::withMessages(['qty' => 'Bill at most the '.$balance['unbilled'].' returned and not yet billed.']);
            $sale = app(SaleApplicationService::class)->create(new SaleCommand(['customer_id' => $order->customer_id, 'warehouse_id' => $this->warehouse($context),
                'business_date' => $data['business_date'], 'sale_note' => 'Job work '.$order->reference_no.' · '.$order->process,
                'items' => [['product_id' => $order->service_product_id, 'qty' => $qty, 'net_unit_price' => $data['rate'] ?? (float) $order->rate]]], 'principal-job-bill:'.$key, $actor, $context));
            DB::table('principal_job_movements')->insert(['company_id' => $order->company_id, 'principal_job_order_id' => $order->id, 'direction' => 'billed',
                'business_date' => $data['business_date'], 'challan_no' => $sale->reference_no, 'description' => $order->process, 'uqc' => 'OTH', 'qty' => $qty,
                'value' => $sale->grand_total, 'sale_id' => $sale->id, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            return $sale;
        });
    }

    public function balance(int $orderId): array
    {
        $sum = fn (string $direction) => (float) DB::table('principal_job_movements')->where('principal_job_order_id', $orderId)->where('direction', $direction)->sum('qty');
        [$received, $returned, $loss, $billed] = [$sum('received'), $sum('returned'), $sum('loss'), $sum('billed')];
        return ['received' => $received, 'returned' => $returned, 'loss' => $loss, 'with_us' => round($received - $returned - $loss, 4),
            'billed' => $billed, 'unbilled' => round($returned - $billed, 4)];
    }

    public function register(CompanyContext $context, int $actor): array
    {
        $this->authorize($context, $actor);
        $query = DB::table('principal_job_orders as o')->join('customers as c', 'c.id', '=', 'o.customer_id')->where('o.company_id', $context->companyId);
        if ($context->branchId) {
            $query->where(fn ($q) => $q->where('o.branch_id', $context->branchId)->orWhereNull('o.branch_id'));
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('principal_job_orders', 'financial_year_id') && $context->financialYearId) {
            $query->where(fn ($q) => $q->where('o.financial_year_id', $context->financialYearId)->orWhereNull('o.financial_year_id'));
        }
        return $query->orderByDesc('o.id')->limit(200)->get(['o.*', 'c.name as customer'])->map(fn ($order) => (array) $order + $this->balance($order->id))->all();
    }

    public function movements(int $orderId, CompanyContext $context, int $actor): array
    {
        $this->find($orderId, $context, $actor);
        return DB::table('principal_job_movements')->where('principal_job_order_id', $orderId)->orderBy('id')->get()->all();
    }

    public function find(int $orderId, CompanyContext $context, int $actor): object
    {
        $this->authorize($context, $actor);
        $query = DB::table('principal_job_orders')->where('company_id', $context->companyId);
        if ($context->branchId) {
            $query->where(fn ($q) => $q->where('branch_id', $context->branchId)->orWhereNull('branch_id'));
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('principal_job_orders', 'financial_year_id') && $context->financialYearId) {
            $query->where(fn ($q) => $q->where('financial_year_id', $context->financialYearId)->orWhereNull('financial_year_id'));
        }
        return $query->where('id', $orderId)->first() ?? abort(404);
    }

    private function record(object $order, string $direction, array $data, array $lines, int $actor): void
    {
        DB::table('principal_job_movements')->insert(array_map(fn ($line) => ['company_id' => $order->company_id, 'principal_job_order_id' => $order->id,
            'direction' => $direction, 'business_date' => $data['business_date'], 'challan_no' => $data['challan_no'] ?? null, 'challan_date' => $data['challan_date'] ?? null,
            'description' => $line['description'], 'hsn_code' => $line['hsn_code'] ?? null, 'uqc' => strtoupper($line['uqc']), 'qty' => $line['qty'],
            'value' => $line['value'] ?? 0, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()], $lines));
    }

    private function warehouse(CompanyContext $context): int
    {
        // A service invoice moves no stock; any internal warehouse of the branch satisfies the document.
        return (int) DB::table('warehouses')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('warehouses', 'external_job_order_id'), fn ($q) => $q->whereNull('external_job_order_id'))->orderBy('id')->value('id');
    }

    private function authorize(CompanyContext $context, int $actor): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('principal_job_orders')) throw ValidationException::withMessages(['job' => 'Run the textile trade migration first.']);
        app(CompanyWriteGuard::class)->context($context, $actor);
        app(CapabilityService::class)->assertEnabled('operations.job_work', $context);
    }
}
