<?php

namespace App\Services\Commercial;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\ERP\SaleService;
use App\Services\ERP\PurchaseService;
use App\Services\Platform\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns the shared transaction and retry boundary for both commercial document types. */
class CommercialApplicationService
{
    public function create(string $kind, array $data, string $key, ?int $actor, ?CompanyContext $context, ?int $replacesId = null): Sale|Purchase
    {
        if (!in_array($kind, ['sale', 'purchase'], true) || trim($key) === '' || strlen($key) > 150) {
            throw ValidationException::withMessages(['idempotency_key' => 'A key of at most 150 characters is required.']);
        }
        $actor ??= auth()->id();
        $guard = app(CompanyWriteGuard::class);
        $context = $guard->context($context, $actor);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-add' : 'purchases-add', $context, $actor);
        // Transport fields never select ownership, numbering, or the posting actor.
        unset($data['_token'], $data['_method'], $data['idempotency_key'], $data['company_id'], $data['branch_id'],
            $data['financial_year_id'], $data['user_id'], $data['reference_no'], $data['replacement_source']);
        $hash = hash('sha256', json_encode([$kind, $context->branchId, $context->financialYearId, $replacesId, $this->canonical($data)], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($kind, $data, $key, $hash, $actor, $context, $guard, $replacesId) {
            // The company lock also serializes first use of a missing idempotency row on MySQL.
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $retry = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $key)->first();
            if ($retry) {
                if ($retry->request_hash !== $hash || $retry->response_type !== $kind) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Key already used for a different request.']);
                }
                return ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->findOrFail($retry->response_ref);
            }
            $date = $guard->begin($context, $guard->businessDate($data));
            $guard->warehouse($data['warehouse_id'] ?? null, $context, $actor);
            $party = $guard->owned($kind === 'sale' ? Customer::class : Supplier::class,
                $data[$kind === 'sale' ? 'customer_id' : 'supplier_id'] ?? null, $context, 'party_id');
            if (isset($party->is_active) && !$party->is_active) {
                throw ValidationException::withMessages(['party_id' => 'Select an active party.']);
            }
            $materials = app(MaterialDocumentService::class)->sources($kind, $data, $context, $actor, $date);
            if ($kind === 'purchase' && (int) ($data['status'] ?? 1) === 4
                && (isset($data['payment_terms_type']) || in_array($data['paying_method'] ?? null, ['Cash', 'Credit'], true))) {
                $data = array_replace($data, self::purchaseOrderTerms($data, $date, (int) ($party->credit_days ?? 0)));
            }
            $quotation = null;
            if (!empty($data['quotation_id'])) {
                $quotation = \App\Models\Quotation::forCompany($context)->whereKey($data['quotation_id'])->lockForUpdate()->firstOrFail();
                $quotedParty = $kind === 'sale' ? $quotation->customer_id : $quotation->supplier_id;
                if (!$quotation->canConvert() || ($quotedParty && (int) $quotedParty !== (int) $party->id)) {
                    throw ValidationException::withMessages(['quotation_id' => 'Choose an unconverted quotation for the same party. Older ambiguous Sent quotations require conversion review.']);
                }
            }
            $products = [];
            validator($data, ['sale_note' => 'nullable|string|max:10000', 'note' => 'nullable|string|max:10000',
                'update_item_cost' => 'sometimes|boolean', 'update_item_hsn' => 'sometimes|boolean'])->validate();
            if (empty($data['items']) || !is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) > 500) {
                throw ValidationException::withMessages(['items' => 'Use between one and 500 lines.']);
            }
            foreach ($data['items'] as $line) {
                if (!is_array($line)) {
                    throw ValidationException::withMessages(['items' => 'Each line must be an object.']);
                }
            }
            // Validate the original contract before any profile expands its lines.
            $unitField = $kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id';
            $products = $guard->products($data['items'], $context, $unitField);
            foreach ($products as $product) {
                $product->assertAllowedOnBill($kind);
            }
            if ($kind === 'sale') {
                $data['items'] = app(\App\Services\Industry\FmcgInventoryService::class)->prepareSale($data['items'], (int) $data['warehouse_id'], $date, $context, $actor);
                if (count($data['items']) > 500) throw ValidationException::withMessages(['items' => 'Expanded invoice exceeds 500 lines.']);
            }
            $products = $guard->products($data['items'], $context, $unitField)->all();
            foreach ($data['items'] as &$line) {
                validator($line, ['attributes' => 'nullable|array', 'serials' => 'nullable|array', 'serials.*' => 'string|max:255',
                    'batch' => 'nullable|array', 'batch.batch_no' => 'required_with:batch|string|max:255',
                    'batch.expired_date' => 'nullable|date_format:Y-m-d',
                    'batch.mfg_date' => 'nullable|date_format:Y-m-d', 'batch.mrp' => 'nullable|numeric|min:0|max:999999999', 'dimensions' => 'nullable|array',
                    'dimensions.identity_no' => 'nullable|string|max:255', 'dimensions.length' => 'nullable|numeric|gt:0',
                    'dimensions.width' => 'nullable|numeric|gt:0', 'dimensions.thickness' => 'nullable|numeric|gt:0',
                    'dimensions.pieces' => 'nullable|integer|min:1', 'stock_identity_id' => 'nullable|integer|min:1'])->validate();
                if (!empty($line['batch']['mfg_date']) && !empty($line['batch']['expired_date']) && $line['batch']['mfg_date'] > $line['batch']['expired_date']) {
                    throw ValidationException::withMessages(['batch.mfg_date' => 'Manufacturing date cannot follow expiry.']);
                }
                $product = $products[$line['product_id']];
                $product->assertAllowedOnBill($kind);
                if ((isset($product->is_active) && !$product->is_active) || !in_array($product->type, ['standard', 'service', 'digital'], true)) {
                    throw ValidationException::withMessages(['items' => 'This product needs its reviewed posting path.']);
                }
                unset($line['attributes']['stock_dimension']);
                if ($kind === 'sale' && !empty($line['stock_identity_id']) && \Illuminate\Support\Facades\Schema::hasColumn('stock_dimensions', 'computed_cbm')) {
                    $identity = \App\Models\Inventory\StockIdentity::forCompany($context)->where('product_id', $product->id)
                        ->where('warehouse_id', $data['warehouse_id'])->where('status', 'in_stock')->lockForUpdate()->findOrFail($line['stock_identity_id']);
                    $dimension = DB::table('stock_dimensions')->where('stock_identity_id', $identity->id)->first();
                    if ($dimension) {
                        $initial = (float) DB::table('stock_movement_lines')->where('company_id', $context->companyId)
                            ->where('stock_identity_id', $identity->id)->where('qty_base', '>', 0)->orderBy('id')->value('qty_base');
                        $quantity = app(\App\Services\Inventory\UomConversionService::class)->toBase($product, (float) $line['qty'], (int) $line['sale_unit_id']);
                        if ($initial <= 0) throw ValidationException::withMessages(['stock_identity_id' => 'Piece needs reviewed opening stock.']);
                        $line['attributes']['stock_dimension'] = (array) $dimension + ['identity_no' => $identity->identity_no,
                            'line_cbm' => round($dimension->computed_cbm * $quantity / $initial, 6),
                            'line_cft' => round($dimension->computed_cft * $quantity / $initial, 6)];
                    }
                }
                if ($kind === 'purchase' && !empty($line['dimensions']) && \Illuminate\Support\Facades\Schema::hasColumn('stock_dimensions', 'computed_cbm')) {
                    $line['attributes']['stock_dimension'] = app(\App\Services\Inventory\DimensionCalculationService::class)->calculate($line['dimensions']);
                }
                $isNonGst = !empty($data['is_non_gst']) || !empty($data['non_gst']) || ($data['bill_type'] ?? '') === 'non_gst'
                    || (!empty($data['attributes']) && (!empty($data['attributes']['is_non_gst']) || ($data['attributes']['bill_type'] ?? '') === 'non_gst'));
                if ($isNonGst) {
                    $rate = 0.0;
                    $line['tax_rate_override'] = 0.0;
                } else {
                    // GST-rated products are priced by TaxDeterminationService below; the legacy VAT rate applies otherwise.
                    $rate = $product->tax_category_id ? 0.0 : app(CommercialPricing::class)->legacyRate($product, $context);
                    // A rate the user changed on the line (tax_rate_override) replaces the product's; GST lines check it in TaxDeterminationService.
                    $override = $line['tax_rate_override'] ?? '';
                    if (!$product->tax_category_id && $override !== '' && $override !== null) {
                        validator(['tax_rate_override' => $override], ['tax_rate_override' => 'numeric|min:0|max:100'])->validate();
                        $rate = (float) $override;
                    } elseif (!$product->tax_category_id && isset($line['tax_rate']) && (float) $line['tax_rate'] !== $rate) {
                        throw ValidationException::withMessages(['items.tax_rate' => 'Tax rate differs from the configured product rate.']);
                    }
                }
                $line['tax_rate'] = $rate;
            }
            unset($line);
            if (!$isNonGst && !empty($data['order_tax_rate'])) {
                throw ValidationException::withMessages(['order_tax_rate' => 'Document tax determination requires the Phase 7 tax service.']);
            }
            $data = app(\App\Services\Tax\TaxDeterminationService::class)->prepare($data, $kind, $products, $party, $context, $date);
            $data = app(CommercialPricing::class)->calculate($data, $kind === 'purchase');
            if (!\Illuminate\Support\Facades\Schema::hasTable('commercial_order_items') && $kind === 'purchase' && !empty($data['purchase_order_id'])) {
                $order = Purchase::visibleIn($context)->whereKey($data['purchase_order_id'])->lockForUpdate()->firstOrFail();
                if ((int) $order->status !== 4 || (int) $order->supplier_id !== (int) $party->id
                    || (int) $order->warehouse_id !== (int) $data['warehouse_id'] || $order->reversed_at
                    || !empty($order->attributes_json['fulfilled_at'])) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'Link an active order for the same supplier and warehouse.']);
                }
                // A receipt can bring in only what is still open on the order, item by item.
                $open = $this->openOrderQuantities($order, $context);
                foreach (collect($data['items'])->groupBy('product_id') as $productId => $lines) {
                    if ($lines->sum(fn ($line) => (float) ($line['received_qty'] ?? $line['qty'])) > ($open[(int) $productId] ?? 0) + 0.00005) {
                        throw ValidationException::withMessages(['purchase_order_id' => 'Receipt exceeds the quantity still open on order '.$order->reference_no.'.']);
                    }
                }
            }
            if ($kind === 'purchase' && (int) ($data['status'] ?? 1) === 4 && ($data['paid_amount'] ?? 0) > 0) {
                throw ValidationException::withMessages(['paid_amount' => 'An order cannot receive payment until it is billed.']);
            }
            if ($kind === 'sale' && !in_array((int) ($data['sale_status'] ?? 1), [1, 2], true)) {
                throw ValidationException::withMessages(['sale_status' => 'Use Completed or Pending.']);
            }
            if ($kind === 'sale' && (int) ($data['sale_status'] ?? 1) !== 1 && $data['paid_amount'] > 0) {
                throw ValidationException::withMessages(['paid_amount' => 'Pending invoices cannot receive payment.']);
            }
            $override = $kind === 'sale' ? app(CreditControlService::class)->validate($party, $data, $context, $actor, $date) : null;
            if ($kind === 'purchase') {
                $data['items'] = app(LandedCostService::class)->allocate($data, $products);
            }
            foreach ($data['items'] as &$line) {
                $line['stock_details_json'] = ['base_factor' => round(app(\App\Services\Inventory\UomConversionService::class)->toBase(
                    $products[$line['product_id']], (float) $line['qty'], (int) $line[$kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id']) / (float) $line['qty'], 8)] + array_intersect_key($line, array_flip([
                    'serials', 'imei_number', 'batch', 'product_batch_id', 'variant_id', 'stock_identity_id', 'dimensions', 'attributes', 'material_sources', 'order_item_id', 'lot_no', 'date',
                    'sph', 'cyl', 'axis', 'base', 'addition', 'lr', 'dia', 'optical_spec', 'prescription_json',
                ]));
            }
            unset($line);
            $data['business_date'] = $date;
            $document = $kind === 'sale'
                ? app(SaleService::class)->createSale($data, $actor, $context, deferPosting: true)
                : app(PurchaseService::class)->createPurchase($data, $actor, $context, deferPosting: true);
            $attributes = $this->attributes($data);
            if ($replacesId) {
                $original = ($kind === 'sale' ? Sale::class : Purchase::class)::visibleIn($context)->findOrFail($replacesId);
                $relation = $kind === 'sale' ? 'productSales' : 'productPurchases';
                $snapshot = fn ($lines) => $lines->map(fn ($line) => ['product_id' => $line->product_id,
                    'name' => $line->product?->name, 'qty' => (float) $line->qty])->all();
                $attributes['item_change_history'] = ['original_bill' => $original->reference_no,
                    'before' => $snapshot($original->$relation), 'after' => $snapshot($document->$relation)];
            }
            // Cash discount terms typed on the bill win; otherwise the party master's standard terms apply.
            if (!isset($attributes['cash_discount_percent']) && (float) ($party->cd_percent ?? 0) > 0) {
                $attributes += ['cash_discount_percent' => (float) $party->cd_percent, 'cash_discount_days' => (int) ($party->cd_days ?? 0)];
            }
            // Textile bills default the bale number to the bill number; a job worker's service charge has no bales.
            if (empty($attributes['bale_no']) && empty($attributes['job_work_receipt_id']) && \Illuminate\Support\Facades\Schema::hasTable('company_industry_settings')
                && DB::table('company_industry_settings')->where('company_id', $context->companyId)->value('profile_key') === 'textile') {
                $attributes['bale_no'] = $document->reference_no;
            }
            if ($kind === 'sale' && \Illuminate\Support\Facades\Schema::hasColumn('warehouses', 'external_job_order_id')
                && ($location = DB::table('warehouses')->find($data['warehouse_id'])) && $location->external_job_order_id) {
                // Supplied straight from the job worker's premises (Sec 143(1)(a)); the invoice and e-way bill show it.
                $worker = DB::table('job_work_orders as o')->join('suppliers as s', 's.id', '=', 'o.job_worker_party_id')
                    ->where('o.id', $location->external_job_order_id)->first(['s.name', 's.company_name']);
                $attributes['dispatched_from'] = trim(($worker->company_name ?? null ?: $worker->name ?? 'Job worker').', '.($location->address ?? ''), ', ');
            }
            if ($materials->isNotEmpty()) $attributes['source_documents'] = $materials->map(fn ($source) => [
                'type' => $kind === 'sale' ? 'dc' : 'grn', 'id' => $source->id,
                'reference_no' => $kind === 'sale' ? $source->challan_no : $source->grn_no])->values()->all();
            if (!empty($data['round_off'])) $attributes['round_off'] = $data['round_off'];
            // Terms typed on the bill win over the party's standard credit days.
            $creditDays = is_numeric($data['credit_days'] ?? null) ? (int) $data['credit_days'] : (int) ($party->credit_days ?? 0);
            $attributes['due_date'] = $data['due_date'] ?? CarbonImmutable::parse($date)->addDays(max(0, $creditDays))->toDateString();
            $dueDate = $attributes['due_date'];
            if (!is_string($dueDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueDate)
                || !checkdate((int) substr($dueDate, 5, 2), (int) substr($dueDate, 8, 2), (int) substr($dueDate, 0, 4)) || $dueDate < $date) {
                throw ValidationException::withMessages(['due_date' => 'Due date must be on or after document date.']);
            }
            $opticalFields = [];
            if ($kind === 'sale') {
                foreach (['end_user_name', 'end_user_phone', 'end_user_email', 'end_user_address', 'fitting_charge', 'tinting_cost', 'customer_order_no'] as $optCol) {
                    if (isset($data[$optCol]) && \Illuminate\Support\Facades\Schema::hasColumn('sales', $optCol)) {
                        $opticalFields[$optCol] = $data[$optCol];
                    }
                }
            }
            $saveFields = [
                'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId,
                'lot_number' => $data['lot_number'] ?? $document->lot_number,
                'attributes_json' => $attributes,
                'replaces_id' => $replacesId
            ] + $opticalFields;
            if ($isNonGst || !empty($attributes['is_non_gst'])) {
                if (\Illuminate\Support\Facades\Schema::hasColumn($document->getTable(), 'is_non_gst')) {
                    $saveFields['is_non_gst'] = true;
                }
            }
            if (isset($data['_tax_snapshot'])) {
                $saveFields['tax_snapshot_json'] = $data['_tax_snapshot'];
            }
            $document->forceFill($saveFields)->save();
            if (!empty($data['project_id'])) {
                app(\App\Services\Industry\ProjectService::class)->link((int) $data['project_id'], $kind, $document->id, $context, $actor);
            }
            if ($kind === 'purchase' && (!empty($data['update_item_cost']) || !empty($data['update_item_hsn']))) {
                app(CommercialPermission::class)->assert('products-edit', $context, $actor);
                foreach ($data['items'] as $line) {
                    if (!empty($line['batch']['mfg_date']) && !empty($line['batch']['expired_date']) && $line['batch']['mfg_date'] > $line['batch']['expired_date']) {
                    throw ValidationException::withMessages(['batch.mfg_date' => 'Manufacturing date cannot follow expiry.']);
                }
                $product = $products[$line['product_id']];
                    if (!empty($data['update_item_cost'])) {
                        $product->cost = $line['net_unit_cost'];
                    }
                    if (!empty($data['update_item_hsn'])) {
                        if (!isset($line['hsn_code']) || !is_string($line['hsn_code']) || !preg_match('/^\d{4}(?:\d{2})?(?:\d{2})?$/D', $line['hsn_code'])) {
                            throw ValidationException::withMessages(['hsn_code' => 'Provide a 4, 6 or 8 digit HSN for each item.']);
                        }
                        $product->hsn_code = $line['hsn_code'];
                    }
                    $product->save();
                }
            }
            $orders = app(OrderLifecycleService::class);
            if ($kind === 'purchase' && (int) $document->status === 4) $orders->initializePurchase($document, $data, $context);
            if ($materials->isNotEmpty()) app(MaterialDocumentService::class)->allocateMany($materials, $document, $data['items'], $context);
            $data = $orders->resolveMaterialReferences($kind, $data, $context, $document);
            if (\Illuminate\Support\Facades\Schema::hasTable('commercial_order_items') && !($kind === 'purchase' && (int) $document->status === 4)) {
                $orders->consume($document, $orders->prepareConsumer($kind, $data, $context, $actor, $document), $context);
                $document->forceFill(['attributes_json' => array_intersect_key($data, array_flip(['sales_order_id', 'purchase_order_id'])) + ($document->attributes_json ?? [])])->save();
            }
            $document = $kind === 'sale' ? app(SalePostingService::class)->post($document, $context, $actor)
                : app(PurchasePostingService::class)->post($document, $context, $actor);
            if (!\Illuminate\Support\Facades\Schema::hasTable('commercial_order_items') && $kind === 'purchase' && !empty($data['purchase_order_id']) && (int) ($data['status'] ?? 1) !== 4) {
                $order = Purchase::visibleIn($context)->whereKey($data['purchase_order_id'])->firstOrFail();
                if (collect($this->openOrderQuantities($order, $context))->every(fn ($qty) => $qty <= 0.00005)) {
                    $order->forceFill(['attributes_json' => ['fulfilled_at' => now()->toDateTimeString(), 'fulfilled_by_purchase_id' => $document->id] + ($order->attributes_json ?? [])])->save();
                }
            }
            if ($quotation) {
                $quotation->recordConversion($kind, $document->id);
            }
            foreach ($materials as $material) if (!$material->posted_at) {
                $material->update(['status' => $kind === 'sale' ? 'converted_to_sale' : 'converted_to_purchase', $kind.'_id' => $document->id]);
            }
            DB::table('idempotency_keys')->insert(['company_id' => $context->companyId, 'key' => $key, 'request_hash' => $hash,
                'response_type' => $kind, 'response_ref' => $document->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($override) {
                $this->audit('credit_override', $document, $context, $actor, $override);
            }
            if ($document->posted_at) {
                DB::afterCommit(fn () => event(new \App\Events\CommercialDocumentPosted($kind, $document->id, $context->companyId, $context->branchId)));
            }
            return $document->load($kind === 'sale' ? ['customer', 'warehouse', 'productSales', 'payments'] : ['supplier', 'warehouse', 'productPurchases', 'payments']);
        });
    }

    public function audit(string $event, Model $document, CompanyContext $context, int $actor, array $details): void
    {
        DB::table('commercial_audit_events')->insert(['company_id' => $context->companyId, 'branch_id' => $context->branchId,
            'user_id' => $actor, 'event' => $event, 'source_type' => $document instanceof Sale ? 'sale' : 'purchase',
            'source_id' => $document->id, 'details_json' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    /** Ordered quantity per product minus what active bills linked to the order have already received. */
    private function openOrderQuantities(Purchase $order, \App\Services\Platform\CompanyContext $context): array
    {
        $open = [];
        foreach (DB::table('product_purchases')->where('purchase_id', $order->id)->get(['product_id', 'qty']) as $line) {
            $open[(int) $line->product_id] = ($open[(int) $line->product_id] ?? 0) + (float) $line->qty;
        }
        $bills = Purchase::visibleIn($context)->where('supplier_id', $order->supplier_id)->where('status', '<>', 4)->whereNull('reversed_at')
            ->where('id', '<>', $order->id)->get(['id', 'attributes_json'])
            ->filter(fn ($bill) => (int) ($bill->attributes_json['purchase_order_id'] ?? 0) === (int) $order->id)->pluck('id');
        foreach (DB::table('product_purchases')->whereIn('purchase_id', $bills)->get(['product_id', 'qty', 'recieved']) as $line) {
            $open[(int) $line->product_id] = ($open[(int) $line->product_id] ?? 0) - (float) ($line->recieved ?? $line->qty);
        }
        return $open;
    }

    public static function purchaseOrderTerms(array $data, string $date, ?int $supplierDays = null): array
    {
        $type = $data['payment_terms_type'] ?? strtolower((string) ($data['paying_method'] ?? ''));
        $days = $data['credit_days'] ?? ($type === 'cash' ? 0 : $supplierDays);
        validator(['payment_terms_type' => $type, 'credit_days' => $days], [
            'payment_terms_type' => 'required|in:cash,credit', 'credit_days' => 'required|integer|min:0|max:3650',
        ])->validate();
        if ($type === 'cash' && (int) $days !== 0) {
            throw ValidationException::withMessages(['credit_days' => 'Cash terms require zero credit days.']);
        }
        $due = CarbonImmutable::parse($date)->addDays((int) $days)->toDateString();
        if (!empty($data['due_date']) && $data['due_date'] !== $due) {
            throw ValidationException::withMessages(['due_date' => 'Due date must match the approved commercial terms.']);
        }
        return ['payment_terms_type' => $type, 'credit_days' => (int) $days, 'due_date' => $due];
    }

    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['transport_name', 'lr_number', 'lr_date', 'vehicle_number', 'bale_count', 'bundle_count', 'landed_cost_method', 'capitalise_services', 'sales_order_id', 'purchase_order_id', 'goods_receipt_no',
            'bale_no', 'no_of_bales', 'lr_no', 'station_to', 'order_no', 'credit_days', 'agent_id', 'area_id', 'job_work_receipt_id',
            'cash_discount_percent', 'cash_discount_days', 'job_work_charge_mode', 'job_work_remarks', 'supply_type', 'lut_number', 'payment_terms_type', 'is_non_gst', 'bill_type',
            'end_user_name', 'end_user_phone', 'end_user_email', 'end_user_address', 'fitting_charge', 'tinting_cost', 'customer_order_no']));
        if (isset($attributes['job_work_receipt_id'])) validator($attributes, ['job_work_receipt_id' => 'integer|min:1'])->validate();
        if (($attributes['cash_discount_percent'] ?? '') === '') unset($attributes['cash_discount_percent'], $attributes['cash_discount_days']);
        validator($attributes, ['cash_discount_percent' => 'sometimes|numeric|gt:0|max:100', 'cash_discount_days' => 'sometimes|nullable|integer|min:0|max:365',
            'job_work_charge_mode' => 'sometimes|in:capitalise,expense',
            'end_user_name' => 'sometimes|nullable|string|max:191',
            'end_user_phone' => 'sometimes|nullable|string|max:50',
            'end_user_email' => 'sometimes|nullable|string|max:191',
            'end_user_address' => 'sometimes|nullable|string|max:1000',
            'fitting_charge' => 'sometimes|nullable|numeric|min:0',
            'tinting_cost' => 'sometimes|nullable|numeric|min:0',
            'customer_order_no' => 'sometimes|nullable|string|max:191'])->validate();
        foreach (['bale_count', 'bundle_count', 'no_of_bales'] as $count) if (isset($attributes[$count])) validator($attributes, [$count => 'integer|min:0|max:1000000'])->validate();
        if (isset($attributes['credit_days']) && $attributes['credit_days'] !== '') validator($attributes, ['credit_days' => 'integer|min:0|max:3650'])->validate();
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                unset($attributes[$key]);
                continue;
            }
            if ($key === 'end_user_address') {
                if (!is_scalar($value) || strlen((string) $value) > 1000) {
                    throw ValidationException::withMessages([$key => 'Use a value of at most 1000 characters.']);
                }
                continue;
            }
            if (!is_scalar($value) || strlen((string) $value) > 255) {
                throw ValidationException::withMessages([$key => 'Use a value of at most 255 characters.']);
            }
        }
        return $attributes;
    }

    public function convertToGst(string $kind, int $id, CompanyContext $context, int $actor): Sale|Purchase
    {
        $guard = app(CompanyWriteGuard::class);
        $context = $guard->context($context, $actor);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'sales-edit' : 'purchases-edit', $context, $actor);

        return DB::transaction(function () use ($kind, $id, $context, $actor, $guard) {
            $guard->begin($context, null);
            $modelClass = $kind === 'sale' ? Sale::class : Purchase::class;
            $document = $modelClass::visibleIn($context)->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($document->reversed_at) {
                throw ValidationException::withMessages(['document' => 'Cannot convert a reversed document to GST.']);
            }

            $attributes = $document->attributes_json ?? [];
            $snapshot = $document->tax_snapshot_json ?? [];
            $isNonGst = !empty($document->is_non_gst)
                || !empty($attributes['is_non_gst'])
                || ($attributes['bill_type'] ?? '') === 'non_gst'
                || (!empty($snapshot['is_non_gst']))
                || (($snapshot['bill_type'] ?? '') === 'non_gst');

            if (!$isNonGst) {
                throw ValidationException::withMessages(['document' => 'This document is already a GST document.']);
            }

            $date = $document->created_at ? $document->created_at->toDateString() : date('Y-m-d');
            $party = ($kind === 'sale' ? \App\Models\Customer::class : \App\Models\Supplier::class)::forCompany($context)
                ->findOrFail($document->{$kind === 'sale' ? 'customer_id' : 'supplier_id'});

            $relation = $kind === 'sale' ? 'productSales' : 'productPurchases';
            $lines = $document->$relation()->with('product')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['document' => 'Document has no line items to price.']);
            }

            $productIds = $lines->pluck('product_id')->unique()->all();
            $products = \App\Models\Product::forCompany($context)->whereIn('id', $productIds)->get()->keyBy('id')->all();

            $priceField = $kind === 'sale' ? 'net_unit_price' : 'net_unit_cost';
            $itemData = [];
            foreach ($lines as $line) {
                $itemData[] = [
                    'product_id' => $line->product_id,
                    'qty' => (float) $line->qty,
                    $priceField => (float) $line->$priceField,
                    'discount' => (float) ($line->discount ?? 0),
                    'tax_rate_override' => $line->tax_rate_override ?? null,
                ];
            }

            $docData = [
                'items' => $itemData,
                'order_discount' => (float) ($document->order_discount ?? 0),
                'shipping_cost' => (float) ($document->shipping_cost ?? 0),
                'is_non_gst' => false,
                'bill_type' => 'gst',
            ];

            // Run authoritative TaxDeterminationService
            $prepared = app(\App\Services\Tax\TaxDeterminationService::class)->prepare($docData, $kind, $products, $party, $context, $date);
            $calculated = app(CommercialPricing::class)->calculate($prepared, $kind === 'purchase');

            // 1. Update lines
            foreach ($lines as $i => $line) {
                $calcLine = $calculated['items'][$i];
                $lineTax = (float) $calcLine['tax'];
                $lineTotal = (float) $calcLine['total'];
                $lineTaxRate = (float) $calcLine['tax_rate'];
                $lineSnapshot = $calcLine['_tax_snapshot'] ?? [];

                $lineClass = get_class($line);
                $lineClass::withoutEvents(function () use ($line, $lineTaxRate, $lineTax, $lineTotal, $lineSnapshot) {
                    $line->forceFill([
                        'tax_rate' => $lineTaxRate,
                        'tax' => $lineTax,
                        'total' => $lineTotal,
                        'tax_snapshot_json' => $lineSnapshot,
                    ])->save();
                });
            }

            // 2. Update document header
            $totalTax = array_sum(array_column($calculated['items'], 'tax'));
            $grandTotal = (float) $calculated['grand_total'];
            $totalPrice = array_sum(array_column($calculated['items'], 'total'));

            unset($attributes['is_non_gst']);
            $attributes['bill_type'] = 'gst';
            $attributes['converted_to_gst_at'] = now()->toDateTimeString();
            $attributes['converted_to_gst_by'] = $actor;

            $docUpdate = [
                'total_tax' => $totalTax,
                'order_tax' => 0,
                'order_tax_rate' => 0,
                'grand_total' => $grandTotal,
                'attributes_json' => $attributes,
                'tax_snapshot_json' => $calculated['_tax_snapshot'] ?? null,
            ];

            if ($kind === 'sale') {
                $docUpdate['total_price'] = $totalPrice;
            } else {
                $docUpdate['total_cost'] = $totalPrice;
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn($document->getTable(), 'is_non_gst')) {
                $docUpdate['is_non_gst'] = false;
            }

            $docClass = get_class($document);
            $docClass::withoutEvents(function () use ($document, $docUpdate) {
                $document->forceFill($docUpdate)->save();
            });

            // 3. Update accounting journals if posted
            if ($document->posted_at) {
                $existingJournal = DB::table('journal_entries')
                    ->where('company_id', $context->companyId)
                    ->where('reference_type', $kind)
                    ->where('reference_id', $document->id)
                    ->whereNull('reversal_of_id')
                    ->where('status', 'posted')
                    ->first();

                if ($existingJournal) {
                    try {
                        app(\App\Services\Accounting\AccountingPostingService::class)->reverse(
                            $existingJournal->id,
                            $date,
                            'Converted from Non-GST to GST Bill',
                            $context
                        );
                    } catch (\Exception $e) {
                        // Continue if already reversed or standalone
                    }
                }

                $accounts = app(PostingAccounts::class);
                $jLines = [];
                $add = function (string|int $role, float $debit, float $credit, bool $partyFlag = false) use (&$jLines, $accounts, $context, $document, $actor, $kind) {
                    if (round($debit + $credit, 4) == 0) return;
                    $jLines[] = [
                        'chart_of_account_id' => is_int($role) ? $role : $accounts->account($role, $context, $actor),
                        'debit' => round($debit, 4),
                        'credit' => round($credit, 4),
                        'partner_type' => $partyFlag ? ($kind === 'sale' ? 'customer' : 'supplier') : null,
                        'partner_id' => $partyFlag ? ($kind === 'sale' ? $document->customer_id : $document->supplier_id) : null,
                    ];
                };

                if ($kind === 'sale') {
                    $add('accounts_receivable', (float) $document->grand_total, 0, true);
                    $add('sales_discount', (float) ($document->order_discount ?? 0), 0);
                    $add('sales_revenue', 0, (float) $document->total_price - (float) $document->total_tax);
                    if ($document->tax_snapshot_json) {
                        foreach (['cgst', 'sgst', 'igst', 'cess'] as $component) {
                            $amount = $document->tax_snapshot_json['reverse_charge'] ? 0 : $document->productSales->sum(fn ($l) => $l->tax_snapshot_json[$component] ?? 0);
                            $add('output_tax_'.$component, 0, (float) $amount);
                        }
                    }
                } else {
                    $add('inventory', (float) $document->total_cost - (float) $document->total_tax, 0);
                    $add('accounts_payable', 0, (float) $document->grand_total, true);
                    if ($document->tax_snapshot_json) {
                        foreach (['cgst', 'sgst', 'igst', 'cess'] as $component) {
                            $amount = $document->productPurchases->sum(fn ($l) => $l->tax_snapshot_json['input_credit_allowed'] ? ($l->tax_snapshot_json[$component] ?? 0) : 0);
                            $add('input_tax_'.$component, (float) $amount, 0);
                        }
                    }
                }

                if (count($jLines) >= 2) {
                    $description = ucfirst($kind).' '.$document->reference_no.' (Converted to GST Bill)';
                    app(\App\Services\Accounting\AccountingService::class)->postJournalEntry([
                        'entry_date' => $date,
                        'reference_type' => $kind,
                        'reference_id' => $document->id,
                        'reference_no' => $document->reference_no,
                        'posting_key' => $kind.':'.$document->id.':gst-conv:'.time(),
                        'due_date' => $attributes['due_date'] ?? $date,
                        'created_by' => $actor,
                        'voucher_type' => $kind === 'sale' ? 'sales' : 'purchase',
                        'description' => mb_substr($description, 0, 2000),
                    ], $jLines, $context);
                }

                // Record in GST Projections
                $docLines = $kind === 'sale' ? $document->productSales : $document->productPurchases;
                app(\App\Services\Tax\GstProjectionService::class)->record($document, $kind, $docLines->pluck('tax_snapshot_json')->all(), $context);

                // Update document print snapshot
                if (config('compliance.enabled') && \Illuminate\Support\Facades\Schema::hasColumn($document->getTable(), 'document_snapshot_json')) {
                    $document->forceFill(['document_snapshot_json' => app(\App\Services\Documents\DocumentRenderingService::class)->capture($document, $kind, $context)])->save();
                }
            }

            $this->audit('converted_to_gst', $document, $context, $actor, [
                'old_tax' => 0,
                'new_tax' => $totalTax,
                'new_grand_total' => $grandTotal,
            ]);

            return $document->fresh();
        });
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
