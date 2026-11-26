<?php

namespace App\Services\Documents;

use App\Models\{Sale, Purchase, Returns, ReturnPurchase, Company, Product};
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Templates consume a frozen read-only array. Totals always come from the posted source. */
class DocumentRenderingService
{
    public function source(string $kind, int $id, CompanyContext $context): Sale|Purchase|Returns|ReturnPurchase
    {
        $model = match ($kind) { 'sale' => Sale::class, 'purchase' => Purchase::class, 'sale_note' => Returns::class,
            'purchase_note' => ReturnPurchase::class, default => throw ValidationException::withMessages(['document' => 'Unsupported document.']) };
        $source = in_array($kind, ['sale', 'purchase'], true) ? $model::visibleIn($context)->findOrFail($id)
            : $model::forCompany($context)->where('branch_id', $context->branchId)->findOrFail($id);
        // A purchase order (purchases.status 4) is never posted but prints on the same A4 layout as a bill.
        $order = $kind === 'purchase' && (int) $source->status === 4 && !$source->reversed_at;
        if ((!$source->posted_at && !$order) || (int) $source->branch_id !== $context->branchId) {
            throw ValidationException::withMessages(['document' => 'Print and dispatch require a posted document in this branch.']);
        }
        return $source;
    }

    public function capture(Sale|Purchase|Returns|ReturnPurchase $source, string $kind, CompanyContext $context): array
    {
        $sale = str_starts_with($kind, 'sale'); $note = str_ends_with($kind, '_note');
        $order = $kind === 'purchase' && (int) $source->status === 4;
        $company = Company::findOrFail($context->companyId);
        $party = ($sale ? \App\Models\Customer::class : \App\Models\Supplier::class)::forCompany($context)
            ->findOrFail($source->{$sale ? 'customer_id' : 'supplier_id'});
        $tax = $source->tax_snapshot_json ?? [];
        $lines = $note ? $source->products : ($sale ? $source->productSales : $source->productPurchases);
        $units = DB::table('units')->whereIn('id', $lines->pluck($sale ? 'sale_unit_id' : 'purchase_unit_id')->filter())->pluck('unit_code', 'id');
        $rows = [];
        foreach ($lines as $line) {
            if ((int) $line->company_id !== $context->companyId) throw new \LogicException('Document contains a foreign line.');
            $product = Product::forCompany($context)->findOrFail($line->product_id); $snapshot = $line->tax_snapshot_json ?? [];
            $batchNo = !empty($line->product_batch_id) ? DB::table('product_batches')->where('id', $line->product_batch_id)->value('batch_no') : null;
            $stockDetails = $line->stock_details_json ?? [];
            $lotNo = $stockDetails['lot_no'] ?? $stockDetails['batch_no'] ?? $batchNo ?? null;
            $lineDate = $stockDetails['date'] ?? null;
            $rows[] = ['name' => $snapshot['product_name'] ?? $product->name, 'code' => $snapshot['product_code'] ?? $product->code,
                'unit' => $units[$line->{$sale ? 'sale_unit_id' : 'purchase_unit_id'}] ?? '', 'discount' => (float) ($snapshot['discount'] ?? $line->discount ?? 0),
                'qty' => (float) $line->qty, 'price' => (float) $line->{$sale ? 'net_unit_price' : 'net_unit_cost'},
                'dimensions' => $line->stock_details_json['attributes']['stock_dimension'] ?? null,
                'taxable_value' => $snapshot['taxable_value'] ?? (float) $line->total - (float) $line->tax,
                'tax_rate' => $snapshot['rate'] ?? (float) $line->tax_rate, 'cess_rate' => $snapshot['cess_rate'] ?? 0,
                'tax' => (float) $line->tax, 'total' => (float) $line->total, 'hsn_sac' => $snapshot['hsn_sac'] ?? $product->hsn_code,
                'cgst' => $snapshot['cgst'] ?? 0, 'sgst' => $snapshot['sgst'] ?? 0, 'igst' => $snapshot['igst'] ?? 0, 'cess' => $snapshot['cess'] ?? 0,
                'lot_no' => $lotNo, 'date' => $lineDate ? \Carbon\Carbon::parse($lineDate)->format('d-m-Y') : null,
                'sph' => $line->sph ?? null, 'cyl' => $line->cyl ?? null, 'axis' => $line->axis ?? null,
                'addition' => $line->addition ?? null, 'dia' => $line->dia ?? null, 'lr' => $line->lr ?? null];
        }
        $currency = DB::table('currencies')->where('id', $company->base_currency_id)->first();
        $partyGstin = $tax[$sale ? 'buyer_gstin' : 'seller_gstin'] ?? '';
        $attributes = $source->attributes_json ?? [];
        $shipTo = $sale && !$note && trim((string) ($source->shipping_address ?? '')) !== '' ? ['name' => $source->shipping_name ?: $party->name,
            'address' => implode(', ', array_filter([$source->shipping_address, $source->shipping_city, $source->shipping_state, $source->shipping_zip])),
            'phone' => $source->shipping_phone] : null;
        return ['kind' => $kind,
            'company_trade_name' => DB::table('tax_registrations')->where('company_id', $context->companyId)->where('gstin', $tax['registration_gstin'] ?? '')->value('trade_name') ?: $company->trade_name,
            'company_state' => isset($tax['registration_gstin']) ? substr($tax['registration_gstin'], 0, 2) : ($company->state_code ?? ''),
            'party_state' => $partyGstin ? substr($partyGstin, 0, 2) : (string) DB::table('party_tax_profiles')->where('company_id', $context->companyId)
                ->where('party_type', $sale ? 'customer' : 'supplier')->where('party_id', $party->id)->value('state_code'),
            'party_contact' => ['company' => $party->company_name ?? null, 'phone' => $party->phone_number ?? null, 'email' => $party->email ?? null,
                'city' => $party->city ?? null, 'postal_code' => $party->postal_code ?? null],
            'ship_to' => $shipTo, 'round_off' => (float) ($attributes['round_off'] ?? 0),
            // An order owes nothing yet; its supplier-invoice date field holds the expected delivery date.
            'due_date' => $order ? null : ($attributes['due_date'] ?? null), 'paid_amount' => $note || $order ? null : (float) ($source->paid_amount ?? 0),
            'order' => $order, 'delivery_date' => $order ? $source->supplier_invoice_date : null,
            'references' => array_intersect_key($attributes, array_flip(['order_no', 'lr_no', 'station_to', 'credit_days', 'payment_terms_type'])),
            'material_documents' => $attributes['source_documents'] ?? [],
            'note' => $note ? null : ($source->{$sale ? 'sale_note' : 'note'} ?? null),
            'print' => $this->printSettings($context), 'title' => $note ? ucfirst($source->note_type).' note' : ($sale ? ($tax ? match ($tax['registration_type']) { 'composition' => 'Bill of supply', 'unregistered' => 'Invoice', default => 'Tax invoice' } : 'Invoice') : ($order ? 'Purchase order' : 'Purchase bill')),
            'number' => $source->reference_no, 'date' => $source->created_at->toDateString(),
            'company_name' => $tax[$sale ? 'seller_name' : 'buyer_name'] ?? $company->legal_name,
            'party_name' => $tax[$sale ? 'buyer_name' : 'seller_name'] ?? $party->name,
            'company_address' => ($tax['company_address'] ?? '') ?: app(\App\Services\Platform\CompanyProfileService::class)->fullAddress($company),'party_address' => $tax['party_address'] ?? $party->address ?? '', 'company_gstin' => $tax['registration_gstin'] ?? '',
            'party_gstin' => $tax[$sale ? 'buyer_gstin' : 'seller_gstin'] ?? '', 'place_of_supply' => $tax['place_of_supply'] ?? '',
            'reverse_charge' => $tax['reverse_charge'] ?? false, 'currency' => $currency->code ?? 'INR',
            'lines' => $rows, 'total_tax' => (float) $source->total_tax + (float) $source->order_tax,
            'discount' => (float) ($source->order_discount ?? 0), 'shipping' => (float) ($source->shipping_cost ?? 0),
            'grand_total' => (float) $source->grand_total, 'source_number' => $note ? $source->attributes_json['source_no'] : null,
            'quantity_scale' => \Illuminate\Support\Facades\Schema::hasTable('company_industry_settings')
                ? app(\App\Services\Industry\IndustryProfileService::class)->settings($context)['settings']['quantity_scale'] : 4,
            'fitting_charge' => (float) ($source->fitting_charge ?? 0),
            'tinting_cost' => (float) ($source->tinting_cost ?? 0),
            'end_user_name' => $source->end_user_name ?? null,
            'end_user_phone' => $source->end_user_phone ?? null,
            'customer_order_no' => $source->customer_order_no ?? null,
            'transport' => array_intersect_key($source->attributes_json ?? [], array_flip(['transport_name', 'lr_number', 'lr_date', 'vehicle_number', 'bale_count', 'bundle_count', 'bale_no', 'no_of_bales', 'dispatched_from', 'supply_type', 'lut_number'])),
        ];
    }

    public function dto(string $kind, int $id, CompanyContext $context): array
    {
        if ($kind === 'sales_order') return $this->salesOrder($id, $context);
        $source = $this->source($kind, $id, $context);
        // A purchase bill prints the supplier's own bill number; ours stays as the reference. (On an order the field holds their quotation.)
        $supplierBill = $kind === 'purchase' && (int) $source->status !== 4 ? array_filter(['supplier_bill_no' => $source->supplier_invoice_no ?? null,
            'supplier_bill_date' => $source->supplier_invoice_date ? substr((string) $source->supplier_invoice_date, 0, 10) : null]) : [];
        // An order can still be amended, so it prints from its current lines instead of a posting snapshot.
        if (!$source->posted_at) return $supplierBill + $this->capture($source, $kind, $context) + ['reversed_at' => null];
        if (!$source->document_snapshot_json) throw ValidationException::withMessages(['document' => 'Retained document needs reviewed print-snapshot migration.']);
        // Documents posted before print details existed show the current company print details.
        return $supplierBill + $this->jobWorkBill($source, $source->document_snapshot_json, $context)
            + ['reversed_at' => $source->reversed_at?->toDateString(), 'print' => $this->printSettings($context)];
    }

    /**
     * A sales order prints on the A4 invoice layout. It is never posted, so GST is worked out now by the same
     * determination the invoice will use, from the open order lines.
     */
    private function salesOrder(int $id, CompanyContext $context): array
    {
        $order = \App\Models\SalesOrder::visibleIn($context)->findOrFail($id);
        if ((int) $order->branch_id !== $context->branchId) throw ValidationException::withMessages(['document' => 'Switch to the branch the order was entered in.']);
        $items = DB::table('commercial_order_items')->where('company_id', $context->companyId)->where('order_kind', 'sale')
            ->where('order_id', $order->id)->orderBy('id')->get();
        $lines = $items->map(fn ($item) => ['product_id' => $item->product_id, 'qty' => (float) $item->qty, 'net_unit_price' => (float) $item->unit_rate,
            'sale_unit_id' => $item->uom_id, 'variant_id' => $item->variant_id])->values()->all();
        $priced = app(\App\Services\Commercial\CommercialPricing::class)->preview(['customer_id' => $order->customer_id, 'warehouse_id' => $order->warehouse_id,
            'business_date' => $order->business_date->toDateString(), 'items' => $lines], false, $context);
        $party = \App\Models\Customer::forCompany($context)->findOrFail($order->customer_id);
        $company = Company::findOrFail($context->companyId);
        $tax = DB::table('party_tax_profiles')->where('company_id', $context->companyId)->where('party_type', 'customer')->where('party_id', $party->id)->first();
        $units = DB::table('units')->whereIn('id', $items->pluck('uom_id')->filter())->pluck('unit_code', 'id');
        $rows = [];
        foreach ($priced['items'] as $i => $line) {
            $product = Product::forCompany($context)->findOrFail($line['product_id']); $snap = $line['_tax_snapshot'] ?? [];
            $rows[] = ['name' => $product->name, 'code' => $product->code, 'unit' => $units[$items[$i]->uom_id] ?? '', 'discount' => 0,
                'qty' => (float) $line['qty'], 'price' => (float) $line['net_unit_price'], 'dimensions' => null,
                'taxable_value' => $snap['taxable_value'] ?? (float) $line['total'] - (float) $line['tax'], 'tax_rate' => (float) ($line['tax_rate'] ?? 0),
                'cess_rate' => $snap['cess_rate'] ?? 0, 'tax' => (float) $line['tax'], 'total' => (float) $line['total'], 'hsn_sac' => $snap['hsn_sac'] ?? $product->hsn_code,
                'cgst' => $snap['cgst'] ?? 0, 'sgst' => $snap['sgst'] ?? 0, 'igst' => $snap['igst'] ?? 0, 'cess' => $snap['cess'] ?? 0];
        }
        $header = $priced['items'][0]['_tax_snapshot'] ?? [];
        $registration = \App\Services\Tax\GstSetupService::available()
            ? app(\App\Services\Tax\GstSetupService::class)->registration($context, $order->business_date->toDateString()) : null;
        $gstin = $registration->gstin ?? '';
        return ['kind' => 'sales_order', 'order' => true, 'title' => 'Sales order', 'number' => $order->reference_no, 'date' => $order->business_date->toDateString(),
            'delivery_date' => $order->promised_date ? \Carbon\Carbon::parse($order->promised_date)->toDateString() : null,
            'company_trade_name' => $registration->trade_name ?? null ?: $company->trade_name, 'company_name' => $registration->legal_name ?? $company->legal_name,
            'company_state' => $gstin ? substr($gstin, 0, 2) : ($company->state_code ?? ''),
            'company_address' => ($registration->address ?? '') ?: app(\App\Services\Platform\CompanyProfileService::class)->fullAddress($company), 'company_gstin' => $gstin,
            'party_name' => $party->name, 'party_address' => $party->address ?? '', 'party_gstin' => $tax->gstin ?? '', 'party_state' => (string) ($tax->state_code ?? ''),
            'party_contact' => ['company' => $party->company_name ?? null, 'phone' => $party->phone_number ?? null, 'email' => $party->email ?? null,
                'city' => $party->city ?? null, 'postal_code' => $party->postal_code ?? null],
            'place_of_supply' => $header['place_of_supply'] ?? '', 'reverse_charge' => false, 'currency' => 'INR', 'lines' => $rows,
            'total_tax' => (float) $priced['total_tax'], 'discount' => 0, 'shipping' => 0, 'grand_total' => (float) $priced['grand_total'],
            'round_off' => (float) ($priced['round_off'] ?? 0), 'due_date' => null, 'paid_amount' => null, 'references' => [], 'material_documents' => [],
            'note' => $order->note, 'ship_to' => null, 'source_number' => null, 'transport' => [], 'reversed_at' => null,
            'quantity_scale' => \Illuminate\Support\Facades\Schema::hasTable('company_industry_settings')
                ? app(\App\Services\Industry\IndustryProfileService::class)->settings($context)['settings']['quantity_scale'] : 4,
            'print' => $this->printSettings($context)];
    }

    /** A job worker's processing-charge bill prints as such, naming the GRN, DC and order it charges for. */
    private function jobWorkBill(Sale|Purchase|Returns|ReturnPurchase $source, array $document, CompanyContext $context): array
    {
        $receiptId = $source instanceof Purchase ? (int) ($source->attributes_json['job_work_receipt_id'] ?? 0) : 0;
        if (!$receiptId) return $document;
        $refs = DB::table('job_work_receipts as r')->join('job_work_dispatches as d', 'd.id', '=', 'r.dispatch_id')
            ->join('job_work_orders as o', 'o.id', '=', 'd.job_work_order_id')->where('r.company_id', $context->companyId)->where('r.id', $receiptId)
            ->first(['r.id as receipt_id', 'r.reference_no as grn', 'r.business_date as receipt_date', 'r.details_json', 'r.material_movement_id', 'r.output_movement_id', 'r.service_purchase_id',
                'd.id as dispatch_id', 'd.reference_no as dc', 'd.business_date as dispatch_date',
                'o.reference_no as job_order', 'o.business_date as order_date']);
        $document['title'] = 'Job work service bill';
        $document['is_service_bill'] = true;
        if ($refs) $document['source_number'] = 'GRN '.$refs->grn.' · DC '.$refs->dc.' · Order '.$refs->job_order;
        $stockChange = $refs && (int) $refs->service_purchase_id === $source->id
            ? (json_decode($refs->details_json ?? '{}', true)['stock_name_change'] ?? null) : null;
        if ($stockChange) {
            $summary = collect($stockChange['items'])->map(fn ($item) => $item['from_name'].' to '.$item['to_name'].' ('.$item['qty'].')')->implode('; ');
            $document['note'] = trim(($document['note'] ?? '').' · Returned stock: '.$summary);
        }
        if (($document['transport']['bale_no'] ?? null) === $document['number']) unset($document['transport']['bale_no']);

        // Collect fallback lots from receipt, stock change, dispatch or movements
        $defaultLots = [];
        $receiptDetails = json_decode($refs->details_json ?? '{}', true) ?: [];
        if (!empty($receiptDetails['lots']['output'])) {
            $defaultLots = array_merge($defaultLots, (array) $receiptDetails['lots']['output']);
        }
        if (!empty($receiptDetails['lots']['input'])) {
            $defaultLots = array_merge($defaultLots, (array) $receiptDetails['lots']['input']);
        }
        if (!empty($receiptDetails['stock_name_change']['items'])) {
            foreach ($receiptDetails['stock_name_change']['items'] as $item) {
                if (!empty($item['lot'])) $defaultLots[] = $item['lot'];
            }
        }
        if (empty($defaultLots) && !empty($refs->dispatch_id)) {
            $dispatchLines = DB::table('job_work_dispatch_lines')->where('dispatch_id', $refs->dispatch_id)->get(['stock_details_json']);
            $batchIds = [];
            foreach ($dispatchLines as $dl) {
                $st = json_decode($dl->stock_details_json ?? '{}', true) ?: [];
                if (!empty($st['product_batch_id'])) $batchIds[] = (int) $st['product_batch_id'];
                if (!empty($st['lot_no'])) $defaultLots[] = $st['lot_no'];
                if (!empty($st['batch_no'])) $defaultLots[] = $st['batch_no'];
            }
            if ($batchIds) {
                $batchNos = DB::table('product_batches')->whereIn('id', array_unique($batchIds))->pluck('batch_no')->filter()->all();
                $defaultLots = array_merge($defaultLots, $batchNos);
            }
        }
        if (empty($defaultLots)) {
            $movementIds = array_filter([(int) ($refs->material_movement_id ?? 0), (int) ($refs->output_movement_id ?? 0)]);
            if ($movementIds) {
                $movementBatches = DB::table('stock_movement_lines as sml')
                    ->join('product_batches as pb', 'pb.id', '=', 'sml.batch_id')
                    ->whereIn('sml.stock_movement_id', $movementIds)
                    ->pluck('pb.batch_no')->filter()->all();
                if ($movementBatches) $defaultLots = array_merge($defaultLots, $movementBatches);
            }
        }
        $fallbackLot = !empty($defaultLots) ? implode(', ', array_unique($defaultLots)) : null;
        $fallbackDate = !empty($refs->receipt_date) ? \Carbon\Carbon::parse($refs->receipt_date)->format('d-m-Y')
            : (!empty($source->supplier_invoice_date) ? \Carbon\Carbon::parse($source->supplier_invoice_date)->format('d-m-Y') : null);

        // Also check actual product_purchases for this purchase
        $purchaseLines = DB::table('product_purchases as pp')
            ->leftJoin('product_batches as pb', 'pb.id', '=', 'pp.product_batch_id')
            ->where('pp.purchase_id', $source->id)
            ->get(['pp.id', 'pp.product_id', 'pp.product_batch_id', 'pp.stock_details_json', 'pb.batch_no']);

        // Enrich lines with lot_no, date, amount, total_amount
        if (!empty($document['lines'])) {
            $lines = [];
            foreach ($document['lines'] as $i => $line) {
                $pp = $purchaseLines[$i] ?? null;
                $details = $pp && $pp->stock_details_json ? (is_array($pp->stock_details_json) ? $pp->stock_details_json : json_decode($pp->stock_details_json, true)) : [];
                $lot = $line['lot_no'] ?? $details['lot_no'] ?? $details['batch_no'] ?? $pp?->batch_no ?? $fallbackLot;
                $line['lot_no'] = $lot ?: '—';

                $lineDate = $line['date'] ?? $details['date'] ?? null;
                $line['date'] = $lineDate ? \Carbon\Carbon::parse($lineDate)->format('d-m-Y') : ($fallbackDate ?: \Carbon\Carbon::parse($document['date'])->format('d-m-Y'));

                $price = (float) ($line['price'] ?? 0);
                $qty = (float) ($line['qty'] ?? 0);
                $taxable = (float) ($line['taxable_value'] ?? ($qty * $price));
                $line['amount'] = (float) ($line['amount'] ?? round($qty * $price, 2));
                $line['total_amount'] = (float) ($line['total_amount'] ?? round($taxable, 2));
                $lines[] = $line;
            }
            $document['lines'] = $lines;
        }

        return $document;
    }

    /** Letterhead for printed documents: contact and logo from Company profile, bank and terms from Settings › GST › Registration. */
    public function printSettings(CompanyContext $context): array
    {
        $company = Company::findOrFail($context->companyId);
        $filled = fn (array $values) => array_filter($values, fn ($value) => $value !== null && $value !== '');
        // Contact typed in the GST print form before Company profile existed still prints until the profile has it.
        return $filled(['phone' => $company->phone, 'email' => $company->email, 'website' => $company->website, 'pan' => $company->pan, 'logo' => $company->logo])
            + $filled($company->settings_json['invoice_print'] ?? []);
    }

    /** The logo as a data URI, so the HTML page and the DomPDF output show it the same way; null when the file is gone. */
    public static function logo(?string $path): ?string
    {
        if (!$path || !str_starts_with($path, \App\Services\Platform\CompanyProfileService::LOGO_DIR.'/')) return null;
        $file = realpath(public_path($path)); $root = realpath(public_path(\App\Services\Platform\CompanyProfileService::LOGO_DIR));
        if (!$file || !$root || !str_starts_with($file, $root.DIRECTORY_SEPARATOR) || !is_file($file)) return null;
        $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => null };
        return $type ? 'data:'.$type.';base64,'.base64_encode(file_get_contents($file)) : null;
    }

    public function profile(string $kind, CompanyContext $context, ?int $id, string $format): array
    {
        if ($id) {
            $row = DB::table('print_profiles')->where('company_id', $context->companyId)->where('document_type', $kind)->where('id', $id)->first();
            abort_unless($row, 404); $format = $row->format;
            $settings = json_decode($row->settings_json ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $profile = ['format' => $format, 'width' => $row->paper_width, 'lines' => $row->height_lines,
                'columns' => $settings['columns'] ?? 80, 'copies' => json_decode($row->copies_json ?? '["Original"]', true)];
        } else $profile = ['format' => $format, 'width' => $format === 'thermal' ? 80 : 210, 'lines' => 68, 'columns' => 80, 'copies' => ['Original']];
        validator($profile, ['format' => 'required|in:a4,thermal,dot_matrix', 'width' => 'required|integer|min:58|max:300',
            'lines' => 'required|integer|min:20|max:200', 'columns' => 'required|integer|min:60|max:200',
            'copies' => 'required|array|min:1|max:5', 'copies.*' => 'string|max:60'])->validate();
        if ($format === 'dot_matrix') app(\App\Services\Platform\CapabilityService::class)->assertEnabled('printing.dot_matrix', $context);
        return $profile;
    }

    public function html(array $document, array $profile): string
    {
        return view($profile['format'] === 'a4' ? 'backend.compliance.tax-invoice' : 'backend.compliance.print', compact('document', 'profile'))->render();
    }

    /** 1234567.5 → 12,34,567.50 (Indian digit grouping). */
    public static function inr(float $value): string
    {
        $value = round($value, 2);
        [$whole, $fraction] = explode('.', number_format(abs($value), 2, '.', ''));
        if (strlen($whole) > 3) $whole = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3);
        return ($value < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    /** 9878 → "Nine Thousand Eight Hundred Seventy Eight Rupees Only" (lakh/crore system). */
    public static function rupeesInWords(float $amount): string
    {
        $paise = (int) round(abs($amount) * 100);
        $rupees = intdiv($paise, 100); $paise %= 100;
        return self::indianWords($rupees).' Rupees'.($paise ? ' and '.self::indianWords($paise).' Paise' : '').' Only';
    }

    private static function indianWords(int $number): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
            'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = fn (int $n) => $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)].' '.$ones[$n % 10]);
        if ($number === 0) return 'Zero';
        $parts = [];
        if ($number >= 10000000) { $parts[] = self::indianWords(intdiv($number, 10000000)).' Crore'; $number %= 10000000; }
        foreach ([100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $size => $name) {
            if ($number >= $size) { $parts[] = $two(intdiv($number, $size)).' '.$name; $number %= $size; }
        }
        if ($number) $parts[] = $two($number);
        return implode(' ', $parts);
    }

    public function fixedWidth(array $document, array $profile): string
    {
        $columns = $profile['columns']; $height = $profile['lines']; $output = [];
        $safe = function ($value) use ($columns) {
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value);
            return substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $text), 0, $columns);
        };
        $body = [];
        foreach ($document['lines'] as $line) {
            $body[] = $safe($line['code'].' '.$line['name']);
            $body[] = $safe('Qty '.number_format($line['qty'], $document['quantity_scale'] ?? 4, '.', '').' Rate '.self::amount($line['price']).' Tax '.self::amount($line['tax']).' Total '.self::amount($line['total']));
            $body[] = $safe('HSN/SAC '.$line['hsn_sac'].' CGST '.self::amount($line['cgst']).' SGST '.self::amount($line['sgst']).' IGST '.self::amount($line['igst']).' Cess '.self::amount($line['cess']));
            if ($dimension = $line['dimensions'] ?? null) {
                $body[] = $safe('Piece '.($dimension['identity_no'] ?? '').' '.$dimension['length'].' x '.$dimension['width'].' x '.$dimension['thickness'].' '.$dimension['dimension_uom']);
                $body[] = $safe('CFT '.($dimension['line_cft'] ?? $dimension['computed_cft']).' CBM '.($dimension['line_cbm'] ?? $dimension['computed_cbm']).' '.$dimension['formula_version']);
            }
        }
        foreach ($document['transport'] as $label => $value) $body[] = $safe(ucfirst(str_replace('_', ' ', $label)).': '.$value);
        foreach ($profile['copies'] as $copy) {
            $pages = array_chunk($body, $height - 13);
            foreach ($pages ?: [[]] as $index => $lines) {
                $page = [$safe($document['company_name']), $safe($document['title'].' '.$document['number']),
                    $safe('Date '.$document['date'].' '.$copy.' Page '.($index + 1)),
                    $safe('GSTIN '.$document['company_gstin'].' POS '.$document['place_of_supply']),
                    $safe($document['party_name'].' GSTIN '.$document['party_gstin']), $safe($document['party_address']),
                    str_repeat('-', $columns), ...$lines, str_repeat('-', $columns),
                    $safe('Total tax '.$document['currency'].' '.self::amount($document['total_tax'])),
                    $safe('Discount '.self::amount($document['discount']).' Freight '.self::amount($document['shipping'])),
                    $safe('Grand total '.$document['currency'].' '.self::amount($document['grand_total'])),
                    $safe($document['source_number'] ? 'Against '.$document['source_number'] : '')];
                $output[] = implode("\r\n", array_pad($page, $height, ''));
            }
        }
        return implode("\f", $output);
    }

    public static function amount(float $value): string { return number_format($value, 4, '.', ''); }
}
