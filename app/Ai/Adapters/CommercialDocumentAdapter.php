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
use App\Models\Biller;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\DeliveryChallanItem;
use App\Models\Product;
use App\Models\ProductQuotation;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialApplicationService;
use App\Services\Commercial\CommercialPricing;
use App\Services\Commercial\MaterialDocumentService;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use App\Services\Platform\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Approved sales commands use the same pricing, numbering and posting services as ERP documents. */
final class CommercialDocumentAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(ActionDefinition $definition, array $slots, CompanyContext|BootstrapSetupPrincipal $context): ActionPreviewResult
    {
        if (!$context instanceof CompanyContext) {
            throw new \InvalidArgumentException('A company context is required.');
        }
        $date = $slots['date'] ?? now()->toDateString();
        app(CompanyContextResolver::class)->assertPostingDate($context, $date);
        $customer = Customer::where('company_id', $context->companyId)->where('is_active', true)->find($slots['customer_id']);
        $warehouse = Warehouse::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('is_active', true)->find($slots['warehouse_id']);
        if (!$customer || !$warehouse) {
            throw ValidationException::withMessages(['customer_id' => 'Select an active customer and warehouse in the selected company and branch.']);
        }
        $input = array_intersect_key($slots, array_flip(['customer_id', 'warehouse_id', 'items', 'order_discount', 'shipping_cost']));
        $input['business_date'] = $date;
        // GST determination may create a missing party profile. A preview must never perform that setup write.
        if (config('compliance.enabled') && !DB::table('party_tax_profiles')->where('company_id', $context->companyId)
            ->where('party_type', 'customer')->where('party_id', $customer->id)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Complete the customer GST profile before preparing this document.']);
        }
        $priced = app(CommercialPricing::class)->preview($input, false, $context);
        $items = [];
        foreach ($priced['items'] as $index => $line) {
            $product = Product::where('company_id', $context->companyId)->where('is_active', true)->findOrFail($line['product_id']);
            if ($definition->key === 'delivery_challan.create.v1' && ($product->type !== 'standard' || $product->is_imei)) {
                throw ValidationException::withMessages(['items' => 'Delivery challans support non-serialized stock products.']);
            }
            $items[] = [
                'product_id' => (int) $product->id, 'qty' => (string) $line['qty'],
                'sale_unit_id' => (int) $line['sale_unit_id'], 'net_unit_price' => (string) $line['net_unit_price'],
                'discount' => (string) ($line['discount'] ?? 0), 'tax_rate' => (string) $line['tax_rate'],
                ...array_intersect_key($slots['items'][$index], array_flip(['order_item_id', 'batch_no', 'note', 'tax_rate_override'])),
            ];
        }
        $payload = array_intersect_key($slots, array_flip(['note', 'order_discount', 'shipping_cost', 'valid_until', 'due_date', 'sales_order_id', 'delivery_challan_id', 'purpose']));
        $payload += ['customer_id' => (int) $customer->id, 'warehouse_id' => (int) $warehouse->id, 'date' => $date, 'items' => $items];
        foreach (['order_discount', 'shipping_cost'] as $field) {
            if (isset($payload[$field])) $payload[$field] = (string) $payload[$field];
        }
        if ($definition->key === 'quotation.create.v1') {
            $billers = Biller::where('company_id', $context->companyId)->where('is_active', true)
                ->when(!empty($slots['biller_id']), fn ($q) => $q->whereKey($slots['biller_id']))->limit(2)->get();
            if ($billers->count() !== 1) throw ValidationException::withMessages(['biller_id' => 'Select an active quotation biller.']);
            $payload['biller_id'] = (int) $billers->sole()->id;
            $payload['valid_until'] ??= $date;
        }
        if ($definition->key === 'delivery_challan.create.v1' && empty($payload['purpose'])) {
            throw ValidationException::withMessages(['purpose' => 'Select the statutory purpose of this delivery challan.']);
        }
        if (!empty($payload['delivery_challan_id'])) {
            app(MaterialDocumentService::class)->sources('sale', $payload + ['sale_status' => 1], $context, (int) auth()->id(), $date);
        }
        if (!empty($payload['sales_order_id'])) {
            app(OrderLifecycleService::class)->prepareConsumer($definition->key === 'delivery_challan.create.v1' ? 'dc' : 'sale',
                $payload + ['business_date' => $date], $context, (int) auth()->id());
        }
        $payload['expected_totals'] = ['grand_total' => (string) $priced['grand_total'], 'tax_total' => (string) $priced['total_tax']];
        $preview = ['document_type' => match ($definition->key) {
            'quotation.create.v1' => 'Customer Quotation', 'sales_invoice.create.v1' => 'Sales Tax Invoice',
            'delivery_challan.create.v1' => 'Delivery Challan', default => throw new \InvalidArgumentException('Unsupported sales action.'),
        }, 'customer_name' => $customer->name, 'date' => $date, 'items' => $items, 'totals' => $payload['expected_totals'],
            'policy' => ['requires_approval' => true, 'external_sending' => 'none', 'inventory_impact' => match ($definition->key) {
                'quotation.create.v1' => 'none', 'delivery_challan.create.v1' => 'physical_dispatch', default => empty($payload['delivery_challan_id']) ? 'invoice_stock_issue' : 'settle_existing_dispatch',
            }, 'ledger_impact' => $definition->key === 'quotation.create.v1' ? 'none' : 'authoritative_domain_posting']];
        return new ActionPreviewResult($payload, $preview, $this->sourceHash($payload, $context));
    }

    public function execute(ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context, int $actorId, string $idempotencyKey, string $expectedSourceStateHash): ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('A company context is required.');
        $context = app(CompanyContextResolver::class)->forActor($context, $actorId);
        return DB::transaction(function () use ($definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash) {
            app(CompanyWriteGuard::class)->begin($context, $payload['date']);
            if ($receipt = $this->recover($idempotencyKey, $context)) return $receipt;
            if (!hash_equals($expectedSourceStateHash, $this->sourceHash($payload, $context, true))) {
                throw ValidationException::withMessages(['source_state' => 'ERP source state changed. Prepare a new proposal.']);
            }
            $fresh = $this->preview($definition, $payload, $context);
            if (CanonicalActionPayload::hash($fresh->payload['expected_totals']) !== CanonicalActionPayload::hash($payload['expected_totals'])) {
                throw ValidationException::withMessages(['source_state' => 'Commercial pricing changed. Prepare a new proposal.']);
            }
            $resource = match ($definition->key) {
                'quotation.create.v1' => $this->quotation($payload, $context, $actorId),
                'sales_invoice.create.v1' => app(CommercialApplicationService::class)->create('sale',
                    array_diff_key($payload, ['expected_totals' => true]) + ['sale_status' => 1, 'business_date' => $payload['date'], 'paid_amount' => 0],
                    $idempotencyKey, $actorId, $context),
                'delivery_challan.create.v1' => $this->dispatch($payload, $context, $actorId),
            };
            if ($resource instanceof Sale && (app(CommercialPricing::class)->units((float) $resource->grand_total)
                !== app(CommercialPricing::class)->units((float) $payload['expected_totals']['grand_total']))) {
                throw ValidationException::withMessages(['totals' => 'Posted total differs from the approved total.']);
            }
            $type = $resource instanceof Sale ? 'sale' : ($resource instanceof Quotation ? 'quotation' : 'delivery_challan');
            if (!$resource instanceof Sale) DB::table('idempotency_keys')->insert([
                'company_id' => $context->companyId, 'key' => $idempotencyKey, 'request_hash' => CanonicalActionPayload::hash($payload),
                'response_type' => $type, 'response_ref' => $resource->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->receipt($resource, $type);
        });
    }

    public function recover(string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context): ?ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) return null;
        $row = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $idempotencyKey)->first();
        if (!$row) return null;
        $class = match ($row->response_type) { 'sale' => Sale::class, 'quotation' => Quotation::class, 'delivery_challan' => DeliveryChallan::class, default => null };
        $resource = $class ? $class::where('company_id', $context->companyId)->find($row->response_ref) : null;
        return $resource ? $this->receipt($resource, $row->response_type) : null;
    }

    private function quotation(array $payload, CompanyContext $context, int $actor): Quotation
    {
        $pricing = app(CommercialPricing::class)->preview($payload + ['business_date' => $payload['date']], false, $context);
        $numbers = app(DocumentNumberService::class);
        $reservation = $numbers->reserve('quotation', $context, $payload['date'], $actor);
        $quote = Quotation::forceCreate([
            'company_id' => $context->companyId, 'user_id' => $actor, 'reference_no' => $reservation->formatted_number,
            'biller_id' => $payload['biller_id'], 'customer_id' => $payload['customer_id'], 'warehouse_id' => $payload['warehouse_id'],
            'item' => count($payload['items']), 'total_qty' => array_sum(array_column($payload['items'], 'qty')),
            'total_discount' => array_sum(array_column($payload['items'], 'discount')), 'total_tax' => $pricing['total_tax'],
            'total_price' => $pricing['total_price'], 'order_tax_rate' => 0, 'order_tax' => 0,
            'order_discount' => $payload['order_discount'] ?? 0, 'shipping_cost' => $payload['shipping_cost'] ?? 0,
            'grand_total' => $pricing['grand_total'], 'quotation_status' => 1,
            'note' => trim(($payload['note'] ?? '').' Valid until '.$payload['valid_until']), 'created_at' => $payload['date'].' 00:00:00',
        ]);
        foreach ($pricing['items'] as $line) ProductQuotation::create(array_intersect_key($line, array_flip([
            'product_id', 'qty', 'sale_unit_id', 'net_unit_price', 'discount', 'tax_rate', 'tax', 'total',
        ])) + ['quotation_id' => $quote->id]);
        $numbers->assign($reservation, $quote);
        return $quote;
    }

    private function dispatch(array $payload, CompanyContext $context, int $actor): DeliveryChallan
    {
        $numbers = app(DocumentNumberService::class);
        $reservation = $numbers->reserve('delivery_challan', $context, $payload['date'], $actor);
        $challan = DeliveryChallan::create([
            'company_id' => $context->companyId, 'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
            'challan_no' => $reservation->formatted_number, 'challan_date' => $payload['date'], 'customer_id' => $payload['customer_id'],
            'warehouse_id' => $payload['warehouse_id'], 'purpose' => $payload['purpose'], 'status' => 'pending',
            'total_qty' => array_sum(array_column($payload['items'], 'qty')), 'total_amount' => 0, 'total_tax' => 0,
            'grand_total' => 0, 'remarks' => $payload['note'] ?? null, 'created_by' => $actor,
        ]);
        foreach ($payload['items'] as $line) DeliveryChallanItem::create([
            'company_id' => $context->companyId, 'delivery_challan_id' => $challan->id, 'product_id' => $line['product_id'],
            'unit_id' => $line['sale_unit_id'], 'qty' => $line['qty'], 'rate' => 0, 'amount' => 0,
            'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0, 'batch_no' => $line['batch_no'] ?? null,
        ]);
        $plan = empty($payload['sales_order_id']) ? [] : app(OrderLifecycleService::class)->prepareConsumer('dc',
            $payload + ['business_date' => $payload['date']], $context, $actor, $challan);
        app(MaterialDocumentService::class)->post($challan, $context, $actor);
        if ($plan) app(OrderLifecycleService::class)->consume($challan, $plan, $context);
        $numbers->assign($reservation, $challan);
        return $challan->fresh();
    }

    private function sourceHash(array $payload, CompanyContext $context, bool $lock = false): string
    {
        $state = [];
        foreach (['customers' => ['id' => $payload['customer_id']], 'warehouses' => ['id' => $payload['warehouse_id']],
            'fiscal_years' => ['id' => $context->financialYearId], 'party_tax_profiles' => ['party_type' => 'customer', 'party_id' => $payload['customer_id']],
            'tax_registrations' => ['branch_id' => $context->branchId], 'tax_categories' => [], 'tax_rates' => [], 'units' => [],
            'products' => [], 'taxes' => []] as $table => $where) {
            $q = DB::table($table)->where('company_id', $context->companyId)->where($where)->orderBy('id');
            if ($table === 'products') $q->whereIn('id', array_column($payload['items'], 'product_id'));
            if ($lock) $q->lockForUpdate();
            $state[$table] = $q->get()->map(fn ($row) => (array) $row)->all();
        }
        if (!empty($payload['biller_id'])) $state['biller'] = (array) DB::table('billers')->where('company_id', $context->companyId)->find($payload['biller_id']);
        foreach (['sales_order_id' => 'sales_orders', 'delivery_challan_id' => 'delivery_challans'] as $field => $table) {
            if (!empty($payload[$field])) {
                $q = DB::table($table)->where('company_id', $context->companyId)->where('id', $payload[$field]);
                if ($lock) $q->lockForUpdate();
                $state[$field] = (array) $q->first();
            }
        }
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function receipt($resource, string $type): ActionExecutionReceipt
    {
        return new ActionExecutionReceipt($type, (int) $resource->id, 'completed', [
            'reference_no' => (string) ($resource->reference_no ?? $resource->challan_no),
            'grand_total' => (string) $resource->grand_total, 'posted_at' => $resource->posted_at?->toDateTimeString(),
            'journal_entry_id' => $resource->journal_entry_id, 'stock_movement_id' => $resource->stock_movement_id,
            'external_sending' => 'none',
        ]);
    }
}
