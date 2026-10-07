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
            $materialField = $kind === 'sale' ? 'delivery_challan_id' : 'goods_received_note_id';
            $material = null;
            if (!empty($data[$materialField])) {
                $materialClass = $kind === 'sale' ? \App\Models\DeliveryChallan::class : \App\Models\GoodsReceivedNote::class;
                $material = $materialClass::forCompany($context)->whereKey($data[$materialField])->lockForUpdate()->firstOrFail();
                if ($material->status !== 'pending'
                    || (int) $material->{$kind === 'sale' ? 'customer_id' : 'supplier_id'} !== (int) $party->id
                    || (int) $material->warehouse_id !== (int) $data['warehouse_id']) {
                    throw ValidationException::withMessages([$materialField => 'Convert a pending document for the same party and warehouse.']);
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
            $guard->products($data['items'], $context, $kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id');
            if ($kind === 'sale') {
                $data['items'] = app(\App\Services\Industry\FmcgInventoryService::class)->prepareSale($data['items'], (int) $data['warehouse_id'], $date, $context, $actor);
                if (count($data['items']) > 500) throw ValidationException::withMessages(['items' => 'Expanded invoice exceeds 500 lines.']);
            }
            $products = $guard->products($data['items'], $context, $kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id')->all();
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
                $rate = $product->tax_id ? (float) \App\Models\Tax::where('company_id', $context->companyId)
                    ->where('is_active', true)->findOrFail($product->tax_id)->rate : 0.0;
                if (!$product->tax_category_id && isset($line['tax_rate']) && (float) $line['tax_rate'] !== $rate) {
                    throw ValidationException::withMessages(['items.tax_rate' => 'Tax rate differs from the configured product rate.']);
                }
                $line['tax_rate'] = $rate;
            }
            unset($line);
            if (!empty($data['order_tax_rate'])) {
                throw ValidationException::withMessages(['order_tax_rate' => 'Document tax determination requires the Phase 7 tax service.']);
            }
            $data = app(\App\Services\Tax\TaxDeterminationService::class)->prepare($data, $kind, $products, $party, $context, $date);
            $data = app(CommercialPricing::class)->calculate($data, $kind === 'purchase');
            if ($kind === 'purchase' && !empty($data['purchase_order_id'])) {
                $order = Purchase::visibleIn($context)->whereKey($data['purchase_order_id'])->lockForUpdate()->firstOrFail();
                if ((int) $order->status !== 4 || (int) $order->supplier_id !== (int) $party->id
                    || (int) $order->warehouse_id !== (int) $data['warehouse_id'] || $order->reversed_at) {
                    throw ValidationException::withMessages(['purchase_order_id' => 'Link an active order for the same supplier and warehouse.']);
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
                $line['stock_details_json'] = array_intersect_key($line, array_flip([
                    'serials', 'imei_number', 'batch', 'product_batch_id', 'variant_id', 'stock_identity_id', 'dimensions', 'attributes',
                ]));
            }
            unset($line);
            $data['business_date'] = $date;
            $document = $kind === 'sale'
                ? app(SaleService::class)->createSale($data, $actor, $context, deferPosting: true)
                : app(PurchaseService::class)->createPurchase($data, $actor, $context, deferPosting: true);
            $attributes = $this->attributes($data);
            $attributes['due_date'] = $data['due_date'] ?? CarbonImmutable::parse($date)->addDays((int) ($party->credit_days ?? 0))->toDateString();
            $dueDate = $attributes['due_date'];
            if (!is_string($dueDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueDate)
                || !checkdate((int) substr($dueDate, 5, 2), (int) substr($dueDate, 8, 2), (int) substr($dueDate, 0, 4)) || $dueDate < $date) {
                throw ValidationException::withMessages(['due_date' => 'Due date must be on or after document date.']);
            }
            $document->forceFill(['branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
                'attributes_json' => $attributes, 'replaces_id' => $replacesId] + (isset($data['_tax_snapshot'])
                    ? ['tax_snapshot_json' => $data['_tax_snapshot']] : []))->save();
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
            $document = $kind === 'sale' ? app(SalePostingService::class)->post($document, $context, $actor)
                : app(PurchasePostingService::class)->post($document, $context, $actor);
            if ($material) {
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

    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['transport_name', 'lr_number', 'lr_date', 'vehicle_number', 'bale_count', 'bundle_count', 'landed_cost_method', 'purchase_order_id', 'goods_receipt_no',
            'bale_no', 'no_of_bales', 'lr_no', 'station_to', 'order_no', 'credit_days', 'agent_id', 'area_id']));
        foreach (['bale_count', 'bundle_count'] as $count) if (isset($attributes[$count])) validator($attributes, [$count => 'integer|min:0|max:1000000'])->validate();
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                unset($attributes[$key]);
                continue;
            }
            if (!is_scalar($value) || strlen((string) $value) > 255) {
                throw ValidationException::withMessages([$key => 'Use a value of at most 255 characters.']);
            }
        }
        return $attributes;
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
