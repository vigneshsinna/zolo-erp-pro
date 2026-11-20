<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Ai\Services\CanonicalActionPayload;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Commercial\CommercialPricing;
use App\Services\JobWork\PrincipalJobService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Customer-owned material: invoice only documented returned-but-unbilled quantities. */
final class PrincipalJobInvoiceActionAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(
        ActionDefinition $definition, array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $context = $this->context($context);
        app(CompanyContextResolver::class)->assertPostingDate($context, $slots['business_date']);
        $order = DB::table('principal_job_orders')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)->where('id', $slots['order_id'])->first();
        if (!$order) {
            throw ValidationException::withMessages(['order_id' => 'Customer-owned job order is not in this branch.']);
        }
        // Legacy principal-job orders have no FY key. Prevent cross-FY use until an explicit backfill policy exists.
        if (!Schema::hasColumn('principal_job_orders', 'financial_year_id')) {
            throw ValidationException::withMessages(['order_id' => 'Principal job invoices require reviewed financial-year ownership migration.']);
        }
        $ownFY = DB::table('principal_job_orders')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)->where('id', $order->id)
            ->where('financial_year_id', $context->financialYearId)->exists();
        if (!$ownFY) {
            throw ValidationException::withMessages(['order_id' => 'Job order belongs to a different financial year.']);
        }
        $customer = Customer::forCompany($context)->where('is_active', true)->find($order->customer_id);
        $service = Product::forCompany($context)->where('type', 'service')->where('is_active', true)
            ->find($order->service_product_id);
        if (!$customer || !$service) {
            throw ValidationException::withMessages(['job_order' => 'Customer and service product must be active in the current company.']);
        }
        $balance = app(PrincipalJobService::class)->balance((int) $order->id);
        $qty = (float) $slots['qty'];
        if ($qty <= 0 || $qty > (float) $balance['unbilled'] + 0.00005) {
            throw ValidationException::withMessages(['qty' => 'Can bill only actually returned and not-yet-invoiced material.']);
        }
        $warehouseId = DB::table('warehouses')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)->where('is_active', true)
            ->when(Schema::hasColumn('warehouses', 'external_job_order_id'),
                static fn ($q) => $q->whereNull('external_job_order_id'))->orderBy('id')->value('id');
        if (!$warehouseId) {
            throw ValidationException::withMessages(['warehouse' => 'No authorised branch accounting location for service invoicing.']);
        }
        $priced = app(CommercialPricing::class)->preview([
            'business_date' => $slots['business_date'], 'customer_id' => (int) $customer->id,
            'warehouse_id' => (int) $warehouseId,
            'items' => [[
                'product_id' => (int) $service->id,
                'qty' => $qty, 'net_unit_price' => (float) $slots['rate'],
            ]],
        ], purchase: false, context: $context);
        $state = [
            'order' => (array) $order,
            'movements' => DB::table('principal_job_movements')->where('company_id', $context->companyId)
                ->where('principal_job_order_id', $order->id)->orderBy('id')->get()->toArray(),
            'customer' => $customer->only(['id', 'is_active', 'updated_at']),
            'service' => $service->only(['id', 'type', 'is_active', 'tax_category_id', 'updated_at']),
            'priced' => $priced,
        ];
        return new ActionPreviewResult($slots, [
            'document_type' => 'Customer Job Work Service Invoice',
            'customer_id' => (int) $customer->id,
            'order_id' => (int) $order->id,
            'service_product_id' => (int) $service->id,
            'invoiced_qty' => $qty, 'remaining_unbilled_qty' => $balance['unbilled'],
            'grand_total' => $priced['grand_total'], 'tax_total' => $priced['total_tax'],
            'inventory_impact' => 'Customer-owned material is not company stock.',
            'approval_required' => true, 'posted' => false,
        ], hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    public function execute(
        ActionDefinition $definition, array $payload,
        CompanyContext|BootstrapSetupPrincipal $context, int $actorId,
        string $idempotencyKey, string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        $context = $this->context($context);
        return DB::transaction(function () use ($definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash): ActionExecutionReceipt {
            DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->firstOrFail();
            if ($prior = $this->recover($idempotencyKey, $context)) return $prior;
            DB::table('principal_job_orders')->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)->where('id', $payload['order_id'])
                ->lockForUpdate()->firstOrFail();
            $preview = $this->preview($definition, $payload, $context);
            if (!hash_equals($expectedSourceStateHash, $preview->sourceStateHash)) {
                throw ValidationException::withMessages(['source_state' => 'Principal job return or tax context changed. Rebuild approval.']);
            }
            $sale = app(PrincipalJobService::class)->bill(
                (int) $payload['order_id'],
                ['business_date' => $payload['business_date'], 'qty' => $payload['qty'], 'rate' => $payload['rate']],
                $idempotencyKey, $context, $actorId);
            return $this->saleReceipt($sale);
        }, 3);
    }

    public function recover(
        string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        if (!$context instanceof CompanyContext) return null;
        $prior = DB::table('idempotency_keys')->where('company_id', $context->companyId)
            ->where('key', 'principal-job-bill:'.$idempotencyKey)->where('response_type', 'sale')->first();
        if (!$prior) return null;
        $sale = Sale::visibleIn($context)->find((int) $prior->response_ref);
        return $sale ? $this->saleReceipt($sale) : null;
    }

    private function saleReceipt(Sale $sale): ActionExecutionReceipt
    {
        return new ActionExecutionReceipt('sales_invoice', (int) $sale->id, 'completed', [
            'reference_no' => $sale->reference_no, 'inventory_impact' => 'none_customer_owned',
            'source' => 'PrincipalJobService::bill',
        ]);
    }

    private function context(CompanyContext|BootstrapSetupPrincipal $context): CompanyContext
    {
        if (!config('ai_business_knowledge.job_work_enabled', false)) {
            throw ValidationException::withMessages(['action' => 'AI principal-job billing is disabled.']);
        }
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('Company context required.');
        return $context;
    }
}
