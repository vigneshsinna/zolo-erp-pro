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
        \App\Ai\Schemas\OpticalSchemaFragment::assertAllowedForProfile($context, $slots);
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
        $previewItems = [];
        $availability = app(\App\Services\Inventory\InventoryAvailabilityService::class);
        $inventoryPolicy = app(\App\Services\Inventory\InventoryPolicy::class);
        $uomService = app(\App\Services\Inventory\UomConversionService::class);
        foreach ($priced['items'] as $index => $line) {
            $product = Product::where('company_id', $context->companyId)->where('is_active', true)->findOrFail($line['product_id']);
            if ($definition->key === 'delivery_challan.create.v1' && ($product->type !== 'standard' || $product->is_imei)) {
                throw ValidationException::withMessages(['items' => 'Delivery challans support non-serialized stock products.']);
            }
            if ($definition->key === 'sales_invoice.create.v1' && empty($slots['delivery_challan_id']) && $product->type === 'standard') {
                $policy = $inventoryPolicy->negativeStock($context->companyId, $product);
                if ($policy === 'block') {
                    $saleUnitId = (int) $line['sale_unit_id'];
                    $requiredBase = $uomService->toBase($product, (float) $line['qty'], $saleUnitId);
                    $stock = $availability->forProduct($product->id, $warehouse->id);
                    if (($stock['available'] ?? 0.0) + 0.00005 < $requiredBase) {
                        throw ValidationException::withMessages([
                            "items.{$index}.qty" => "Insufficient available stock for '{$product->name}' in warehouse '{$warehouse->name}' (available: {$stock['available']}).",
                        ]);
                    }
                }
            }
            $optLine = \App\Ai\Schemas\OpticalSchemaFragment::normalizeLineOpticalFields($slots['items'][$index] ?? []);
            $itemPayload = [
                'product_id' => (int) $product->id, 'qty' => (string) $line['qty'],
                'sale_unit_id' => (int) $line['sale_unit_id'], 'net_unit_price' => (string) $line['net_unit_price'],
                'discount' => (string) ($line['discount'] ?? 0), 'tax_rate' => (string) $line['tax_rate'],
                ...array_intersect_key($slots['items'][$index], array_flip(['order_item_id', 'batch_no', 'note', 'tax_rate_override'])),
            ] + $optLine;
            if (isset($itemPayload['tax_rate_override'])) $itemPayload['tax_rate_override'] = (string) $itemPayload['tax_rate_override'];
            $items[] = $itemPayload;
            $previewItems[] = $itemPayload + [
                'product_name' => $product->name,
                'product_code' => $product->code,
                'tax_amount' => (string) ($line['tax'] ?? '0.00'),
                'line_total' => (string) ($line['total'] ?? '0.00'),
            ];
        }
        $payload = array_intersect_key($slots, array_flip([
            'note', 'order_discount', 'shipping_cost', 'valid_until', 'due_date', 'sales_order_id', 'delivery_challan_id', 'purpose',
            ...\App\Ai\Schemas\OpticalSchemaFragment::SALES_HEADER_FIELDS,
        ]));
        $payload += ['customer_id' => (int) $customer->id, 'warehouse_id' => (int) $warehouse->id, 'date' => $date, 'items' => $items];
        foreach (['order_discount', 'shipping_cost', 'fitting_charge', 'tinting_cost'] as $field) {
            if (isset($payload[$field])) $payload[$field] = (string) $payload[$field];
        }
        if ($definition->key === 'quotation.create.v1') {
            if (!empty($payload['sales_order_id']) || !empty($payload['delivery_challan_id'])) {
                throw ValidationException::withMessages(['quotation' => 'A quotation cannot consume an order or delivery challan.']);
            }
            $billers = Biller::where('company_id', $context->companyId)->where('is_active', true)
                ->when(!empty($slots['biller_id']), fn ($q) => $q->whereKey($slots['biller_id']))->limit(2)->get();
            if ($billers->count() !== 1) throw ValidationException::withMessages(['biller_id' => 'Select an active quotation biller.']);
            $payload['biller_id'] = (int) $billers->sole()->id;
            $payload['valid_until'] ??= $date;
        }
        if ($definition->key === 'delivery_challan.create.v1' && empty($payload['purpose'])) {
            throw ValidationException::withMessages(['purpose' => 'Select the statutory purpose of this delivery challan.']);
        }
        if ($definition->key === 'delivery_challan.create.v1' && !empty($payload['delivery_challan_id'])) {
            throw ValidationException::withMessages(['delivery_challan_id' => 'A dispatch cannot settle another delivery challan.']);
        }
        if (!empty($payload['delivery_challan_id'])) {
            app(MaterialDocumentService::class)->sources('sale', $payload + ['sale_status' => 1], $context, (int) auth()->id(), $date);
        }
        if (!empty($payload['sales_order_id'])) {
            DB::transaction(fn () => app(OrderLifecycleService::class)->prepareConsumer($definition->key === 'delivery_challan.create.v1' ? 'dc' : 'sale',
                $payload + ['business_date' => $date], $context, (int) auth()->id()));
        }
        $payload['expected_totals'] = ['grand_total' => (string) $priced['grand_total'], 'tax_total' => (string) $priced['total_tax']];
        if ($definition->key === 'delivery_challan.create.v1') {
            // A challan does not invoice the customer. MaterialDocumentService values its inventory accrual at posting.
            $payload['expected_totals'] = ['grand_total' => '0', 'tax_total' => '0'];
        }
        $routeKind = !empty($payload['delivery_challan_id'])
            ? 'dc_linked'
            : (!empty($payload['sales_order_id']) ? 'so_linked' : 'direct_invoice');
        $subtotalStr = CommercialDecimal::format((float) ($priced['total_price'] ?? 0) - (float) ($priced['total_tax'] ?? 0));
        $taxStr = CommercialDecimal::format((float) ($priced['total_tax'] ?? 0));
        $grandStr = CommercialDecimal::format((float) ($priced['grand_total'] ?? 0));
        $previewTotals = $definition->key === 'delivery_challan.create.v1'
            ? $payload['expected_totals']
            : [
                'subtotal' => $subtotalStr,
                'tax_total' => $taxStr,
                'grand_total' => $grandStr,
                'tax_breakdown' => $priced['tax_breakdown'] ?? null,
            ];
        $preview = [
            'document_type' => match ($definition->key) {
                'quotation.create.v1' => 'Customer Quotation', 'sales_invoice.create.v1' => 'Sales Tax Invoice',
                'delivery_challan.create.v1' => 'Delivery Challan', default => throw new \InvalidArgumentException('Unsupported sales action.'),
            },
            'action_key' => $definition->key,
            'route_kind' => $routeKind,
            'customer_name' => $customer->name,
            'customer' => ['id' => (int) $customer->id, 'name' => $customer->name, 'tax_no' => $customer->tax_no],
            'warehouse' => ['id' => (int) $warehouse->id, 'name' => $warehouse->name],
            'date' => $date,
            'items' => $previewItems,
            'totals' => $previewTotals,
            'policy' => ['requires_approval' => true, 'external_sending' => 'none', 'inventory_impact' => match ($definition->key) {
                'quotation.create.v1' => 'none', 'delivery_challan.create.v1' => 'physical_dispatch', default => empty($payload['delivery_challan_id']) ? 'invoice_stock_issue' : 'settle_existing_dispatch',
            }, 'ledger_impact' => match ($definition->key) {
                'quotation.create.v1' => 'none',
                'delivery_challan.create.v1' => 'Dr Goods Delivered Not Invoiced / Cr Inventory at authoritative ERP valuation. No accounts receivable or sales tax is posted.',
                default => 'authoritative_domain_posting',
            }],
        ];
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
        $hasOpticalCol = \Illuminate\Support\Facades\Schema::hasColumn('delivery_challan_items', 'optical_spec_json');
        foreach ($payload['items'] as $line) {
            $optFields = \App\Ai\Schemas\OpticalSchemaFragment::normalizeLineOpticalFields($line);
            DeliveryChallanItem::create([
                'company_id' => $context->companyId, 'delivery_challan_id' => $challan->id, 'product_id' => $line['product_id'],
                'unit_id' => $line['sale_unit_id'], 'qty' => $line['qty'], 'rate' => 0, 'amount' => 0,
                'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0, 'batch_no' => $line['batch_no'] ?? null,
            ] + ($hasOpticalCol && $optFields !== [] ? ['optical_spec_json' => json_encode($optFields, JSON_THROW_ON_ERROR)] : []));
        }
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
        if (!empty($payload['biller_id'])) {
            $query = DB::table('billers')->where('company_id', $context->companyId)->where('id', $payload['biller_id']);
            if ($lock) $query->lockForUpdate();
            $state['biller'] = (array) $query->first();
        }
        foreach (['sales_order_id' => 'sales_orders', 'delivery_challan_id' => 'delivery_challans'] as $field => $table) {
            if (!empty($payload[$field])) {
                $q = DB::table($table)->where('company_id', $context->companyId)->where('id', $payload[$field]);
                if ($lock) $q->lockForUpdate();
                $state[$field] = (array) $q->first();
            }
        }
        if (!empty($payload['sales_order_id'])) {
            $query = DB::table('commercial_order_items')->where('company_id', $context->companyId)
                ->where('order_kind', 'sale')->where('order_id', $payload['sales_order_id'])->orderBy('id');
            if ($lock) $query->lockForUpdate();
            $items = $query->get();
            $allocations = DB::table('commercial_order_allocations')->where('company_id', $context->companyId)
                ->whereIn('order_item_id', $items->pluck('id'))->orderBy('id');
            if ($lock) $allocations->lockForUpdate();
            $state['sales_order_items'] = $items->all();
            $state['sales_order_allocations'] = $allocations->get()->all();
        }
        if (!empty($payload['delivery_challan_id'])) {
            $query = DB::table('delivery_challan_items')->where('company_id', $context->companyId)
                ->where('delivery_challan_id', $payload['delivery_challan_id'])->orderBy('id');
            if ($lock) $query->lockForUpdate();
            $state['delivery_items'] = $query->get()->all();
            $settlements = DB::table('material_settlements')->where('company_id', $context->companyId)
                ->where('document_type', 'dc')->where('document_id', $payload['delivery_challan_id'])->orderBy('id');
            if ($lock) $settlements->lockForUpdate();
            $state['delivery_settlements'] = $settlements->get()->all();
        }
        if (empty($payload['biller_id'])) {
            // Products are locked above before these aggregates, using the inventory owner's lock order.
            $state['stock_ledger'] = DB::table('stock_movement_lines')->where('company_id', $context->companyId)
                ->whereIn('product_id', array_column($payload['items'], 'product_id'))->groupBy('product_id')->orderBy('product_id')
                ->selectRaw('product_id, SUM(qty_base) AS qty, SUM(value) AS value, MAX(id) AS last_line_id')->get()->all();
            $state['warehouse_stock'] = DB::table('product_warehouse')->where('company_id', $context->companyId)
                ->where('warehouse_id', $payload['warehouse_id'])->whereIn('product_id', array_column($payload['items'], 'product_id'))
                ->orderBy('id')->get()->all();
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('company_industry_settings')) {
            $state['industry_setting'] = (array) DB::table('company_industry_settings')
                ->where('company_id', $context->companyId)->first(['profile_key', 'subtype', 'updated_at']);
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
