<?php

namespace App\Services\Commercial;

use App\Models\CommercialOrderItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesOrder;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\UomConversionService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\DocumentNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Orders own promises and allocations; commercial/inventory services alone own posting. */
class OrderLifecycleService
{
    private const EPSILON = 0.00005;

    public function createSale(array $data, string $key, CompanyContext $context, int $actor): SalesOrder
    {
        $guard = app(CompanyWriteGuard::class);
        $context = $guard->context($context, $actor);
        app(CommercialPermission::class)->assert('sales-add', $context, $actor);
        validator(['key' => $key] + $data, ['key' => 'required|string|max:150', 'items' => 'required|array|min:1|max:500',
            'items.*.qty' => 'required|numeric|gt:0|max:999999999', 'items.*.net_unit_price' => 'required|numeric|min:0|max:999999999',
            'note' => 'nullable|string|max:10000', 'promised_date' => 'nullable|date_format:Y-m-d', 'paid_amount' => 'nullable|numeric|in:0'])->validate();
        unset($data['_token'], $data['company_id'], $data['branch_id'], $data['financial_year_id'], $data['reference_no'], $data['idempotency_key']);
        $hash = hash('sha256', json_encode([$context->branchId, $context->financialYearId, $this->canonical($data)], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($data, $key, $context, $actor, $guard, $hash) {
            $date = $guard->begin($context, $guard->businessDate($data));
            $retry = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $key)->first();
            if ($retry) {
                if ($retry->request_hash !== $hash || $retry->response_type !== 'sales_order') $this->fail('idempotency_key', 'Key already used for a different request.');
                return SalesOrder::visibleIn($context)->findOrFail($retry->response_ref);
            }
            $party = $guard->owned(Customer::class, $data['customer_id'] ?? null, $context, 'customer_id');
            if (isset($party->is_active) && !$party->is_active) $this->fail('customer_id', 'Select an active customer.');
            $guard->warehouse($data['warehouse_id'] ?? null, $context, $actor);
            $lines = $data['items'];
            $products = $guard->products($lines, $context, 'sale_unit_id');
            if (!empty($data['promised_date']) && $data['promised_date'] < $date) $this->fail('promised_date', 'Promised date cannot precede the order date.');
            $reservation = app(DocumentNumberService::class)->reserve('sales_order', $context, $date, $actor);
            $order = SalesOrder::create(['company_id' => $context->companyId, 'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId, 'customer_id' => $party->id, 'warehouse_id' => $data['warehouse_id'],
                'user_id' => $actor, 'business_date' => $date, 'reference_no' => $reservation->formatted_number,
                'promised_date' => $data['promised_date'] ?? null, 'note' => $data['note'] ?? null, 'status' => 'open']);
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                if (isset($product->is_active) && !$product->is_active) $this->fail('items', 'Select active items.');
                $this->createItem('sale', $order->id, $line, $product, $context);
            }
            DB::table('commercial_order_events')->insert(['company_id' => $context->companyId, 'order_kind' => 'sale', 'order_id' => $order->id,
                'user_id' => $actor, 'event' => 'created', 'reason' => 'Sales order created', 'details_json' => json_encode(['date' => $date], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            app(DocumentNumberService::class)->assign($reservation, $order);
            DB::table('idempotency_keys')->insert(['company_id' => $context->companyId, 'key' => $key, 'request_hash' => $hash,
                'response_type' => 'sales_order', 'response_ref' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
            return $order->load('items');
        });
    }

    /** Freeze order units and lot identity when the status-4 purchase is first saved. */
    public function initializePurchase(Purchase $order, array $data, CompanyContext $context): void
    {
        $this->requireTransaction();
        if ((int) $order->status !== 4 || !Schema::hasTable('commercial_order_items')) return;
        if ($this->items('purchase', $order->id, $context)->exists()) return;
        foreach ($order->productPurchases()->orderBy('id')->get() as $i => $stored) {
            $line = ($data['items'][$i] ?? []) + ($stored->stock_details_json ?? []) + $stored->toArray();
            $this->createItem('purchase', $order->id, $line, Product::forCompany($context)->findOrFail($stored->product_id), $context, $stored->id);
        }
    }

    /** Upgrade only provable direct legacy fulfilments. Ambiguous history is retained for review, never guessed. */
    public function backfillPurchase(Purchase $order, CompanyContext $context): void
    {
        $this->requireTransaction();
        \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
        if ($this->items('purchase', $order->id, $context)->exists()) return;
        $this->initializePurchase($order, [], $context);
        $items = $this->items('purchase', $order->id, $context)->orderBy('id')->get();
        if ($items->isEmpty()) $this->fail('order', 'Legacy order has no source lines.');
        $bills = Purchase::visibleIn($context)->where('supplier_id', $order->supplier_id)->where('status', '<>', 4)
            ->whereNull('reversed_at')->orderBy('id')->get()->filter(fn ($bill) => (int) ($bill->attributes_json['purchase_order_id'] ?? 0) === (int) $order->id);
        foreach ($bills as $bill) {
            if ((int) $bill->warehouse_id !== (int) $order->warehouse_id) $this->fail('order', 'Legacy order and fulfilment warehouses differ.');
            if (Schema::hasTable('material_settlements') && DB::table('material_settlements')->where('company_id', $context->companyId)
                ->where('settled_by_type', 'purchase')->where('settled_by_id', $bill->id)->whereNull('released_at')->exists()) {
                $this->fail('order', 'Legacy material-backed fulfilment needs reviewed order-line linkage.');
            }
            foreach ($bill->productPurchases()->orderBy('id')->get() as $line) {
                $identity = ($line->stock_details_json ?? []) + $line->toArray();
                $matches = $items->filter(fn ($item) => $this->identityMatches($item, $identity) && (int) $item->uom_id === (int) $line->purchase_unit_id);
                if ($matches->count() !== 1) $this->fail('order', 'Legacy fulfilment has ambiguous order-line identity.');
                $item = $matches->first(); $factor = $item->qty_base / $item->qty;
                foreach (['billing' => round($line->qty * $factor, 4), 'delivery' => round(($line->recieved ?? 0) * $factor, 4)] as $stage => $qty) {
                    if ($qty <= self::EPSILON) continue;
                    $used = $this->used($item->id, $context);
                    if ($used[$stage] + $qty > $item->qty_base + self::EPSILON) $this->fail('order', 'Legacy fulfilment exceeds its ordered quantity.');
                    DB::table('commercial_order_allocations')->insert(['company_id' => $context->companyId, 'order_item_id' => $item->id,
                        'consumer_type' => 'purchase', 'consumer_id' => $bill->id, 'consumer_item_id' => $line->id, 'stage' => $stage,
                        'qty_base' => $qty, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        $this->refresh('purchase', $order->id, $context);
    }

    /** Strictly scoped header. Fulfilled orders remain readable for history and reversal. */
    public function order(string $kind, int $id, CompanyContext $context, bool $lock = false): Model
    {
        $query = $kind === 'sale' ? SalesOrder::visibleIn($context) : Purchase::visibleIn($context)->where('status', 4);
        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    public function remaining(string $kind, int $id, CompanyContext $context): array
    {
        $order = $this->order($kind, $id, $context);
        $result = [];
        foreach ($this->items($kind, $id, $context)->with(['product:id,name,code,unit_id', 'unit'])->orderBy('id')->get() as $item) {
            $used = $this->used($item->id, $context);
            $available = max(0, $item->qty_base - $item->cancelled_qty_base);
            $factor = $item->qty_base / $item->qty;
            $result[] = $item->toArray() + ['order_item_id' => $item->id, 'delivered_qty_base' => $used['delivery'],
                'billed_qty_base' => $used['billing'], 'remaining_delivery_base' => max(0, $available - $used['delivery']),
                'remaining_billing_base' => max(0, $available - $used['billing']),
                'remaining_qty' => round(max(0, $available - $used['billing']) / $factor, 4),
                'remaining_delivery_qty' => round(max(0, $available - $used['delivery']) / $factor, 4)];
        }
        return ['order' => $order, 'items' => $result, 'events' => DB::table('commercial_order_events')->where('company_id', $context->companyId)
            ->where('order_kind', $kind)->where('order_id', $id)->orderBy('id')->get()->all()];
    }

    /**
     * Call in the consumer transaction after normalization/pricing and before persistence.
     * A material-backed bill consumes billing only; its earlier DC/GRN owns delivery.
     */
    public function prepareConsumer(string $consumer, array $data, CompanyContext $context, int $actor, ?Model $document = null): array
    {
        $this->requireTransaction();
        $data = $this->resolveMaterialReferences($consumer, $data, $context, $document);
        $kind = in_array($consumer, ['sale', 'dc'], true) ? 'sale' : 'purchase';
        $field = $kind === 'sale' ? 'sales_order_id' : 'purchase_order_id';
        if (empty($data[$field])) return [];
        if (!Schema::hasTable('commercial_order_items')) $this->fail($field, 'Apply the order lifecycle migration before linking orders.');
        $guard = app(CompanyWriteGuard::class);
        $context = $guard->context($context, $actor);
        $date = $guard->begin($context, $guard->businessDate($data));
        $order = $this->order($kind, (int) $data[$field], $context, true);
        $partyField = $kind === 'sale' ? 'customer_id' : 'supplier_id';
        $party = $guard->owned($kind === 'sale' ? Customer::class : \App\Models\Supplier::class, $data[$partyField] ?? null, $context, $partyField);
        if (isset($party->is_active) && !$party->is_active) $this->fail($partyField, 'Select an active party.');
        $warehouse = $guard->warehouse($data['warehouse_id'] ?? null, $context, $actor);
        if (isset($warehouse->is_active) && !$warehouse->is_active) $this->fail('warehouse_id', 'Select an active warehouse.');
        if ((int) $order->{$partyField} !== (int) ($data[$partyField] ?? 0) || (int) $order->warehouse_id !== (int) ($data['warehouse_id'] ?? 0)
            || (int) $order->branch_id !== $context->branchId || $order->reversed_at
            || ($kind === 'sale' && $order->status === 'cancelled')) $this->fail($field, 'Link an active order for the same party, branch and warehouse.');
        $orderDate = $kind === 'sale' ? $order->business_date->toDateString() : $order->created_at->toDateString();
        if ($date < $orderDate) $this->fail($field, 'A fulfilment cannot precede its order.');
        if (($consumer === 'sale' && (int) ($data['sale_status'] ?? 1) !== 1)
            || ($consumer === 'purchase' && (int) ($data['status'] ?? 1) === 4)) $this->fail($field, 'Only a completed bill or physical receipt/dispatch can fulfil an order.');
        if (!$this->items($kind, $order->id, $context)->exists()) {
            // Legacy orders need a reviewed snapshot, never a product-only reconstruction of earlier fulfilments.
            $this->fail($field, 'This legacy order needs reviewed line migration before further fulfilment.');
        }
        $items = $this->items($kind, $order->id, $context)->orderBy('id')->lockForUpdate()->get();
        $used = []; foreach ($items as $item) $used[$item->id] = $this->used($item->id, $context);
        $plans = [];
        $inputLines = [];
        foreach (array_values($data['items'] ?? []) as $index => $line) {
            if (!empty($line['_order_parts'])) {
                $sum = 0;
                foreach ($line['_order_parts'] as $part) {
                    $inputLines[] = ['_consumer_index' => $index, 'order_item_id' => $part['order_item_id'], '_base_override' => $part['qty_base'], '_material_base' => $part['qty_base']] + $line;
                    $sum += $part['qty_base'];
                }
                $product = Product::forCompany($context)->findOrFail($line['product_id']);
                $unit = (int) ($line[$kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id'] ?? $product->unit_id);
                $direct = round(app(UomConversionService::class)->toBase($product, (float) $line['qty'], $unit) - $sum, 4);
                if ($direct > self::EPSILON) $inputLines[] = ['_consumer_index' => $index, '_base_override' => $direct, '_material_base' => 0] + $line;
            } else $inputLines[] = ['_consumer_index' => $index] + $line;
        }
        foreach ($inputLines as $line) {
            $index = $line['_consumer_index'];
            $matches = $items->filter(fn ($item) => $this->identityMatches($item, $line));
            $item = !empty($line['order_item_id']) ? $items->firstWhere('id', (int) $line['order_item_id']) : ($matches->count() === 1 ? $matches->first() : null);
            if (!$item || !$this->identityMatches($item, $line)) $this->fail($field, 'Select an exact order line, preserving its product, variant and lot.');
            $product = $guard->owned(Product::class, $line['product_id'], $context, 'items.product_id');
            $unit = (int) ($line[$kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id'] ?? $line['unit_id'] ?? $item->uom_id);
            $quantity = (float) ($line['qty'] ?? 0);
            if (!is_finite($quantity) || $quantity <= 0) $this->fail('items.qty', 'Use a positive fulfilment quantity.');
            $base = $line['_base_override'] ?? app(UomConversionService::class)->toBase($product, $quantity, $unit);
            if ($unit === (int) $item->uom_id && abs(app(UomConversionService::class)->toBase($product, (float) $item->qty, $unit)
                - (float) $item->qty_base) > self::EPSILON) {
                $this->fail('items.unit_id', 'Order unit conversion changed; restore it or review the order before fulfilment.');
            }
            $received = $consumer === 'purchase' ? match ((int) ($data['status'] ?? 1)) {
                1 => $quantity, 2 => (float) ($line['received_qty'] ?? 0), default => 0,
            } : $quantity;
            $delivery = app(UomConversionService::class)->toBase($product, $received, $unit);
            if ($received < 0 || $received > $quantity + self::EPSILON) $this->fail('items.received_qty', 'Received quantity must be within the line quantity.');
            $material = in_array($consumer, ['grn', 'dc'], true);
            $materialBase = (float) ($line['_material_base'] ?? 0);
            if ($document && !$material && !array_key_exists('_base_override', $line)) {
                $stored = ($consumer === 'sale' ? $document->productSales() : $document->productPurchases())->orderBy('id')->get()->get($index);
                $materialBase = $stored ? app(MaterialDocumentService::class)->settledBase($consumer, $stored->id, $context) : 0;
            }
            $delivery = isset($line['_base_override']) ? min($base, $delivery) : $delivery;
            $stages = $material ? ['delivery' => $base] : ['billing' => $base, 'delivery' => max(0, $delivery - $materialBase)];
            foreach ($stages as $stage => $qty) {
                if ($used[$item->id][$stage] + $qty > $item->qty_base - $item->cancelled_qty_base + self::EPSILON) $this->fail($field, 'Fulfilment exceeds the quantity remaining on the selected order line.');
                $used[$item->id][$stage] += $qty;
            }
            $plans[] = ['index' => $index, 'order_item_id' => $item->id, 'stages' => $stages];
        }
        if (!$plans) $this->fail('items', 'Use at least one order line.');
        $combined = [];
        foreach ($plans as $plan) {
            $key = $plan['index'].':'.$plan['order_item_id'];
            $combined[$key] ??= ['index' => $plan['index'], 'order_item_id' => $plan['order_item_id'], 'stages' => []];
            foreach ($plan['stages'] as $stage => $quantity) $combined[$key]['stages'][$stage] = ($combined[$key]['stages'][$stage] ?? 0) + $quantity;
        }
        return ['consumer_type' => $consumer, 'order_kind' => $kind, 'order_id' => $order->id, 'lines' => array_values($combined)];
    }

    /** Carry exact order identities forward through persisted material settlements, including legacy singular picks. */
    public function resolveMaterialReferences(string $consumer, array $data, CompanyContext $context, ?Model $document = null): array
    {
        foreach ($data['items'] ?? [] as $index => $line) {
            unset($data['items'][$index]['_order_parts'], $data['items'][$index]['_base_override'], $data['items'][$index]['_material_base'], $data['items'][$index]['_consumer_index']);
        }
        if (!in_array($consumer, ['sale', 'purchase'], true) || !$document || !Schema::hasTable('commercial_order_allocations') || !Schema::hasTable('material_settlements')) return $data;
        $this->requireTransaction();
        $field = $consumer === 'sale' ? 'sales_order_id' : 'purchase_order_id';
        $lines = ($consumer === 'sale' ? $document->productSales() : $document->productPurchases())->orderBy('id')->get();
        $orderIds = [];
        foreach ($lines as $i => $stored) {
            $settlements = DB::table('material_settlements')->where('company_id', $context->companyId)->where('settled_by_type', $consumer)
                ->where('settled_by_id', $document->id)->where('settled_by_line_id', $stored->id)->whereNull('released_at')->orderBy('id')->get();
            $parts = [];
            foreach ($settlements as $settlement) {
                $allocation = DB::table('commercial_order_allocations as a')->join('commercial_order_items as i', 'i.id', '=', 'a.order_item_id')
                    ->where('a.company_id', $context->companyId)->where('a.consumer_type', $settlement->document_type)
                    ->where('a.consumer_id', $settlement->document_id)->where('a.consumer_item_id', $settlement->document_item_id)
                    ->where('a.stage', 'delivery')->whereNull('a.released_at')->select('a.order_item_id', 'i.order_id')->lockForUpdate()->first();
                if (!$allocation) {
                    if (!empty($data[$field])) $this->fail($field, 'The selected material line is not linked to this order.');
                    continue;
                }
                $orderIds[] = (int) $allocation->order_id;
                $parts[$allocation->order_item_id] = ($parts[$allocation->order_item_id] ?? 0) + (float) $settlement->qty_base;
            }
            if ($parts) $data['items'][$i]['_order_parts'] = collect($parts)->map(fn ($qty, $id) => ['order_item_id' => (int) $id, 'qty_base' => $qty])->values()->all();
        }
        $orderIds = array_values(array_unique($orderIds));
        if (count($orderIds) > 1 || ($orderIds && !empty($data[$field]) && (int) $data[$field] !== $orderIds[0])) $this->fail($field, 'Combine material sources from one matching order.');
        if ($orderIds) $data[$field] = $orderIds[0];
        return $data;
    }

    /** Persist allocations using generated consumer line IDs, inside the same posting transaction. */
    public function consume(Model $document, array $plan, CompanyContext $context): void
    {
        $this->requireTransaction();
        if (!$plan) return;
        $consumer = $plan['consumer_type'];
        $lines = match ($consumer) {
            'sale' => $document->productSales()->orderBy('id')->get(),
            'purchase' => $document->productPurchases()->orderBy('id')->get(),
            default => $document->items()->orderBy('id')->get(),
        };
        foreach ($plan['lines'] as $line) {
            $stored = $lines->get($line['index']);
            if (!$stored) throw new \LogicException('Consumer line order changed before allocation.');
            foreach ($line['stages'] as $stage => $qty) {
                if ($qty <= self::EPSILON) continue;
                $identity = ['company_id' => $context->companyId, 'order_item_id' => $line['order_item_id'],
                    'consumer_type' => $consumer, 'consumer_id' => $document->id, 'consumer_item_id' => $stored->id, 'stage' => $stage];
                $existing = DB::table('commercial_order_allocations')->where($identity)->first();
                if ($existing) {
                    if ($existing->released_at || abs((float) $existing->qty_base - $qty) > self::EPSILON) $this->fail('order', 'A consumer cannot be allocated again with changed quantities.');
                    continue;
                }
                DB::table('commercial_order_allocations')->insert($identity + ['qty_base' => $qty, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->refresh($plan['order_kind'], $plan['order_id'], $context);
    }

    public function release(Model $document, CompanyContext $context, ?string $consumer = null): void
    {
        $this->requireTransaction();
        if (!Schema::hasTable('commercial_order_allocations')) return;
        $consumer ??= $document instanceof \App\Models\Sale ? 'sale' : ($document instanceof Purchase ? 'purchase'
            : ($document instanceof \App\Models\DeliveryChallan ? 'dc' : 'grn'));
        $rows = DB::table('commercial_order_allocations')->where('company_id', $context->companyId)->where('consumer_type', $consumer)
            ->where('consumer_id', $document->id)->whereNull('released_at')->orderBy('order_item_id')->lockForUpdate()->get();
        foreach ($rows as $row) {
            $item = CommercialOrderItem::forCompany($context)->whereKey($row->order_item_id)->lockForUpdate()->firstOrFail();
            DB::table('commercial_order_allocations')->where('id', $row->id)->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);
            $this->refresh($item->order_kind, $item->order_id, $context);
        }
    }

    /** A later receipt against an already billed purchase advances delivery only, never bills the order again. */
    public function receivePurchase(Purchase $purchase, CompanyContext $context): void
    {
        $this->requireTransaction();
        if (!Schema::hasTable('commercial_order_allocations')) return;
        foreach ($purchase->productPurchases()->orderBy('id')->get() as $stored) {
            $billed = DB::table('commercial_order_allocations')->where('company_id', $context->companyId)->where('consumer_type', 'purchase')
                ->where('consumer_id', $purchase->id)->where('consumer_item_id', $stored->id)->where('stage', 'billing')->whereNull('released_at')->get();
            if ($billed->isEmpty()) continue;
            $product = Product::forCompany($context)->findOrFail($stored->product_id);
            $materialBase = app(MaterialDocumentService::class)->settledBase('purchase', $stored->id, $context);
            $received = max(0, app(UomConversionService::class)->toBase($product, (float) $stored->recieved, (int) $stored->purchase_unit_id) - $materialBase);
            if ($billed->count() > 1) $this->fail('purchase_order_id', 'Split later receipts into separate bill lines for their original order identities.');
            $billing = $billed->first();
            $item = CommercialOrderItem::forCompany($context)->whereKey($billing->order_item_id)->lockForUpdate()->firstOrFail();
            $identity = ['company_id' => $context->companyId, 'order_item_id' => $item->id, 'consumer_type' => 'purchase',
                'consumer_id' => $purchase->id, 'consumer_item_id' => $stored->id, 'stage' => 'delivery'];
            $existing = DB::table('commercial_order_allocations')->where($identity)->whereNull('released_at')->first();
            $previous = (float) ($existing->qty_base ?? 0);
            $used = $this->used($item->id, $context);
            if ($received < $previous - self::EPSILON || $used['delivery'] - $previous + $received > $item->qty_base - $item->cancelled_qty_base + self::EPSILON) $this->fail('purchase_order_id', 'Receipt exceeds the remaining order quantity.');
            if ($received > self::EPSILON) DB::table('commercial_order_allocations')->updateOrInsert($identity,
                ['qty_base' => $received, 'released_at' => null, 'created_at' => $existing->created_at ?? now(), 'updated_at' => now()]);
            $this->refresh('purchase', $item->order_id, $context);
        }
    }

    /** Physical material returns reopen delivery only. The material service owns return retry and stock/accrual effects. */
    public function returnMaterial(Model $document, array $returnsBaseByMaterialItemId, CompanyContext $context): void
    {
        $this->requireTransaction();
        if (!Schema::hasTable('commercial_order_allocations')) return;
        $consumer = $document instanceof \App\Models\DeliveryChallan ? 'dc' : 'grn';
        ksort($returnsBaseByMaterialItemId);
        foreach ($returnsBaseByMaterialItemId as $lineId => $qty) {
            $allocation = DB::table('commercial_order_allocations')->where('company_id', $context->companyId)->where('consumer_type', $consumer)
                ->where('consumer_id', $document->id)->where('consumer_item_id', $lineId)->where('stage', 'delivery')->whereNull('released_at')->lockForUpdate()->first();
            if (!$allocation) continue;
            if (!is_numeric($qty) || !is_finite((float) $qty) || $qty <= 0 || $allocation->returned_qty_base + $qty > $allocation->qty_base + self::EPSILON) $this->fail('items', 'Order return exceeds its delivered quantity.');
            $item = CommercialOrderItem::forCompany($context)->whereKey($allocation->order_item_id)->lockForUpdate()->firstOrFail();
            DB::table('commercial_order_allocations')->where('id', $allocation->id)->update(['returned_qty_base' => round($allocation->returned_qty_base + $qty, 4), 'updated_at' => now()]);
            $this->refresh($item->order_kind, $item->order_id, $context);
        }
    }

    public function cancelRemainder(string $kind, int $id, string $reason, string $date, CompanyContext $context, int $actor): Model
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:3|max:500'])->validate();
        $guard = app(CompanyWriteGuard::class); $context = $guard->context($context, $actor);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-edit' : 'purchases-edit', $context, $actor);
        return DB::transaction(function () use ($kind, $id, $reason, $date, $context, $actor, $guard) {
            $guard->begin($context, $date);
            $order = $this->order($kind, $id, $context, true);
            $orderDate = $kind === 'sale' ? $order->business_date->toDateString() : $order->created_at->toDateString();
            if ($date < $orderDate) $this->fail('business_date', 'Cancellation cannot precede the order.');
            $cancelled = [];
            foreach ($this->items($kind, $id, $context)->orderBy('id')->lockForUpdate()->get() as $item) {
                $used = $this->used($item->id, $context);
                $quantity = max(0, round($item->qty_base - $item->cancelled_qty_base - max($used), 4));
                if ($quantity > self::EPSILON) {
                    $item->cancelled_qty_base += $quantity; $item->save(); $cancelled[$item->id] = $quantity;
                }
            }
            if ($cancelled) DB::table('commercial_order_events')->insert(['company_id' => $context->companyId, 'order_kind' => $kind,
                'order_id' => $id, 'user_id' => $actor, 'event' => 'cancel_remainder', 'reason' => $reason,
                'details_json' => json_encode(['date' => $date, 'items' => $cancelled], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->refresh($kind, $id, $context);
            return $order->fresh();
        });
    }

    /** Amend promises in place; fulfilled identity and history remain immutable. */
    public function amend(string $kind, int $id, array $data, string $key, CompanyContext $context, int $actor): Model
    {
        validator(['idempotency_key' => $key] + $data, ['idempotency_key' => 'required|string|max:150', 'version' => 'required|integer|min:1',
            'business_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:500', 'note' => 'nullable|string|max:10000',
            'items' => 'required|array|min:1|max:500', 'items.*.order_item_id' => 'required|integer|min:1|distinct',
            'items.*.qty' => 'required|numeric|gt:0|max:999999999', 'items.*.unit_rate' => 'required|numeric|min:0|max:999999999'])->validate();
        $guard = app(CompanyWriteGuard::class); $context = $guard->context($context, $actor);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-edit' : 'purchases-edit', $context, $actor);
        $hash = hash('sha256', json_encode([$kind, $id, $context->branchId, $context->financialYearId, $this->canonical($data)], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($kind, $id, $data, $key, $hash, $context, $actor, $guard) {
            $guard->begin($context, $data['business_date']);
            $order = $this->order($kind, $id, $context, true);
            $retry = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $key)->first();
            if ($retry) {
                if ($retry->request_hash !== $hash || $retry->response_type !== 'order_amendment' || (int) $retry->response_ref !== $id) $this->fail('idempotency_key', 'Key already used for a different request.');
                return $order;
            }
            $attributes = $order->attributes_json ?? [];
            if ((int) ($attributes['order_version'] ?? 1) !== (int) $data['version']) $this->fail('version', 'Order changed in another window. Reload its current lines before editing.');
            if ($order->reversed_at || ($kind === 'sale' && $order->status === 'cancelled')) $this->fail('order', 'A cancelled order cannot be amended.');
            $orderDate = $kind === 'sale' ? $order->business_date->toDateString() : $order->created_at->toDateString();
            if ($data['business_date'] < $orderDate) $this->fail('business_date', 'Amendment cannot precede the order.');
            $items = $this->items($kind, $id, $context)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== count($data['items'])) $this->fail('items', 'Retain all original line identities. Cancel a remaining quantity instead of deleting history.');
            $before = $items->toArray(); $purchaseLines = [];
            foreach ($data['items'] as $change) {
                $item = $items->get($change['order_item_id']);
                if (!$item) $this->fail('items.order_item_id', 'Select a line belonging to this order.');
                $used = $this->used($item->id, $context);
                $base = round((float) $change['qty'] * $item->qty_base / $item->qty, 4);
                if ($base < max($used) + $item->cancelled_qty_base - self::EPSILON) $this->fail('items.qty', 'Ordered quantity cannot be below fulfilled and cancelled quantities.');
                if (max($used) > self::EPSILON && abs((float) $change['unit_rate'] - $item->unit_rate) > self::EPSILON) $this->fail('items.unit_rate', 'A fulfilled line retains its original order rate. Create a new order for a changed rate.');
                $item->forceFill(['qty' => $change['qty'], 'qty_base' => $base, 'unit_rate' => $change['unit_rate']])->save();
                if ($kind === 'purchase') {
                    $source = $order->productPurchases()->findOrFail($item->source_line_id);
                    $purchaseLines[] = ['product_id' => $item->product_id, 'qty' => $item->qty, 'purchase_unit_id' => $item->uom_id,
                        'net_unit_cost' => $item->unit_rate, 'discount' => $source->discount, 'variant_id' => $item->variant_id,
                        'product_batch_id' => $item->product_batch_id, '_source_line_id' => $source->id];
                }
            }
            if ($kind === 'purchase') {
                $priced = app(CommercialPricing::class)->preview(['supplier_id' => $order->supplier_id, 'business_date' => $orderDate, 'items' => $purchaseLines,
                    'shipping_cost' => $order->shipping_cost, 'order_discount' => $order->order_discount], true, $context);
                foreach ($priced['items'] as $line) $order->productPurchases()->whereKey($line['_source_line_id'])->firstOrFail()
                    ->forceFill(['qty' => $line['qty'], 'net_unit_cost' => $line['net_unit_cost'], 'total' => $line['total'], 'tax' => $line['tax'], 'tax_rate' => $line['tax_rate']])->save();
                $order->forceFill(['total_qty' => array_sum(array_column($priced['items'], 'qty')), 'total_cost' => array_sum(array_column($priced['items'], 'total')),
                    'total_tax' => $priced['total_tax'], 'grand_total' => $priced['grand_total']]);
            }
            $attributes['order_version'] = (int) $data['version'] + 1;
            $order->forceFill(['attributes_json' => $attributes, 'note' => $data['note'] ?? $order->note])->save();
            DB::table('commercial_order_events')->insert(['company_id' => $context->companyId, 'order_kind' => $kind, 'order_id' => $id,
                'user_id' => $actor, 'event' => 'amended', 'reason' => $data['reason'], 'details_json' => json_encode(['date' => $data['business_date'], 'before' => $before, 'after' => $data['items']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            DB::table('idempotency_keys')->insert(['company_id' => $context->companyId, 'key' => $key, 'request_hash' => $hash,
                'response_type' => 'order_amendment', 'response_ref' => $id, 'created_at' => now(), 'updated_at' => now()]);
            $this->refresh($kind, $id, $context);
            return $order->fresh();
        });
    }

    private function createItem(string $kind, int $orderId, array $line, Product $product, CompanyContext $context, ?int $source = null): void
    {
        $unit = (int) ($line[$kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id'] ?? $product->unit_id);
        $qty = (float) $line['qty'];
        $base = app(UomConversionService::class)->toBase($product, $qty, $unit);
        if (!is_finite($base) || $base <= 0) $this->fail('items.qty', 'Order quantity must convert to a positive base quantity.');
        $attrs = [
            'company_id' => $context->companyId, 'order_kind' => $kind, 'order_id' => $orderId,
            'source_line_id' => $source, 'product_id' => $product->id, 'uom_id' => $unit, 'qty' => $qty, 'qty_base' => $base,
            'unit_rate' => $line[$kind === 'sale' ? 'net_unit_price' : 'net_unit_cost'], 'variant_id' => $line['variant_id'] ?? null,
            'product_batch_id' => $line['product_batch_id'] ?? null, 'batch_no' => $line['batch']['batch_no'] ?? $line['batch_no'] ?? null,
        ];
        if (Schema::hasColumn('commercial_order_items', 'optical_spec_json')) {
            $optFields = \App\Ai\Schemas\OpticalSchemaFragment::normalizeLineOpticalFields($line);
            if ($optFields !== []) {
                $attrs['optical_spec_json'] = $optFields;
                $attrs['optical_signature'] = $optFields['optical_spec']['spec_signature'] ?? null;
            }
        }
        CommercialOrderItem::create($attrs);
    }

    private function identityMatches(CommercialOrderItem $item, array $line): bool
    {
        $baseMatch = (int) $item->product_id === (int) ($line['product_id'] ?? 0)
            && (int) $item->variant_id === (int) ($line['variant_id'] ?? 0)
            && (!$item->product_batch_id || (int) $item->product_batch_id === (int) ($line['product_batch_id'] ?? 0))
            && (($item->product_batch_id && !$item->batch_no)
                || (string) $item->batch_no === (string) ($line['batch']['batch_no'] ?? $line['batch_no'] ?? ''));

        if (!$baseMatch) {
            return false;
        }

        $itemOpt = is_array($item->optical_spec_json) ? $item->optical_spec_json : [];
        $lineOpt = \App\Ai\Schemas\OpticalSchemaFragment::normalizeLineOpticalFields($line);
        if ($itemOpt === [] || $lineOpt === []) {
            return true;
        }

        $itemSig = $item->optical_signature ?? ($itemOpt['optical_spec']['spec_signature'] ?? null);
        $lineSig = $lineOpt['optical_spec']['spec_signature'] ?? null;
        if ($itemSig !== null && $lineSig !== null && $itemSig !== $lineSig) {
            return false;
        }

        foreach (['lr', 'sph', 'cyl', 'axis', 'addition', 'base', 'dia'] as $field) {
            $iv = $itemOpt[$field] ?? ($itemOpt['optical_spec'][$field === 'base' ? 'base_curve' : $field] ?? null);
            $lv = $lineOpt[$field] ?? ($lineOpt['optical_spec'][$field === 'base' ? 'base_curve' : $field] ?? null);
            if ($iv !== null && $lv !== null && (string) $iv !== (string) $lv) {
                return false;
            }
        }

        return true;
    }

    private function items(string $kind, int $id, CompanyContext $context)
    {
        return CommercialOrderItem::forCompany($context)->where('order_kind', $kind)->where('order_id', $id);
    }

    private function used(int $id, CompanyContext $context): array
    {
        $used = DB::table('commercial_order_allocations')->where('company_id', $context->companyId)->where('order_item_id', $id)
            ->whereNull('released_at')->groupBy('stage')->selectRaw('stage, SUM(qty_base - returned_qty_base) AS qty')->pluck('qty', 'stage')->all();
        return ['delivery' => (float) ($used['delivery'] ?? 0), 'billing' => (float) ($used['billing'] ?? 0)];
    }

    private function refresh(string $kind, int $id, CompanyContext $context): void
    {
        $order = $this->order($kind, $id, $context, true);
        $open = 0; $used = 0; $cancelled = 0;
        foreach ($this->items($kind, $id, $context)->get() as $item) {
            $quantities = $this->used($item->id, $context);
            $open += max(0, $item->qty_base - $item->cancelled_qty_base - min($quantities));
            $used += max($quantities); $cancelled += $item->cancelled_qty_base;
        }
        $status = $open <= self::EPSILON ? ($used <= self::EPSILON && $cancelled > 0 ? 'cancelled' : 'fulfilled') : ($used > 0 || $cancelled > 0 ? 'partial' : 'open');
        if ($kind === 'sale') $order->forceFill(['status' => $status])->save();
        else {
            $attributes = $order->attributes_json ?? [];
            $attributes['order_status'] = $status;
            if (in_array($status, ['fulfilled', 'cancelled'], true)) $attributes['fulfilled_at'] ??= now()->toDateTimeString();
            else unset($attributes['fulfilled_at'], $attributes['fulfilled_by_purchase_id']);
            $order->forceFill(['attributes_json' => $attributes])->save();
        }
    }

    private function canonical(array $data): array
    {
        if (!array_is_list($data)) ksort($data);
        return array_map(fn ($value) => is_array($value) ? $this->canonical($value) : $value, $data);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Order fulfilment requires the consumer posting transaction.');
    }

    private function fail(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
