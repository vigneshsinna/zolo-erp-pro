<?php

namespace App\Services\Commercial;

use App\Models\{Sale, Purchase, Returns, ReturnPurchase, ProductReturn, PurchaseProductReturn, Product};
use App\Models\Accounting\AccountOpenItem;
use App\Models\Inventory\StockMovementLine;
use App\Services\Accounting\{AccountingService, OpenItemService};
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\{StockLine, StockMovementCommand, InventoryMovementService};
use App\Services\Platform\{CompanyContext, CompanyContextResolver, DocumentNumberService};
use App\Services\Tax\GstProjectionService;
use App\Support\LedgerAmount;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** Existing return tables also own financial credit/debit notes. Source history is never edited. */
class ReturnService
{
    public function create(string $kind, int $sourceId, array $data, string $key, CompanyContext $context, int $actor): Returns|ReturnPurchase
    {
        abort_unless(config('commercial.enabled') && config('compliance.enabled'), 503, 'Compliance posting remains gated.');
        $context = app(CompanyWriteGuard::class)->context($context, $actor);
        app(CommercialPermission::class)->assert($kind === 'sale' ? 'returns-add' : 'purchase-return-add', $context, $actor);
        validator($data, ['business_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:3|max:500',
            'note_type' => 'required|in:credit,debit', 'adjustment_type' => 'required|in:quantity,rate_difference,discount,tax_correction',
            'items' => 'required|array|min:1|max:500', 'items.*.line_id' => 'required|integer|min:1',
            'items.*.qty' => 'nullable|numeric|gt:0', 'items.*.amount' => 'nullable|numeric|gte:0',
            'items.*.disposition' => 'nullable|in:restock,quarantine,damaged,expired,quality_reject',
            'items.*.warehouse_id' => 'nullable|integer|min:1', 'items.*.stock_line_ids' => 'nullable|array',
            'items.*.stock_line_ids.*' => 'integer|min:1'])->validate();
        if (!in_array($kind, ['sale', 'purchase'], true) || trim($key) === '' || strlen($key) > 150 || !array_is_list($data['items'])) {
            $this->invalid('request', 'Use a valid document kind, retry key and line list.');
        }
        $hash = hash('sha256', json_encode([$kind, $sourceId, $context->branchId, $context->financialYearId, $data], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($kind, $sourceId, $data, $key, $hash, $context, $actor) {
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $model = $kind === 'sale' ? Returns::class : ReturnPurchase::class;
            $existing = $model::forCompany($context)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash || (int) $existing->branch_id !== $context->branchId) $this->invalid('idempotency_key', 'Key already used for different note effects.');
                return $existing->load('products');
            }
            app(CompanyWriteGuard::class)->begin($context, $data['business_date']);
            $source = $this->source($kind, $sourceId, $context);
            if ($data['business_date'] < $source->created_at->toDateString()) $this->invalid('business_date', 'A note cannot precede its source document.');
            $reduction = $data['note_type'] === ($kind === 'sale' ? 'credit' : 'debit');
            if ($data['adjustment_type'] === 'quantity' && !$reduction) $this->invalid('note_type', 'Quantity returns must reduce the original document.');
            $note = (new $model)->forceFill([
                'company_id' => $context->companyId, 'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
                $kind.'_id' => $source->id, $kind === 'sale' ? 'customer_id' : 'supplier_id' => $source->{$kind === 'sale' ? 'customer_id' : 'supplier_id'},
                'warehouse_id' => $source->warehouse_id, 'user_id' => $actor, 'account_id' => 0,
                'reference_no' => 'DRAFT-'.substr(hash('sha256', $context->companyId.':'.$key), 0, 32),
                'item' => count($data['items']), 'total_qty' => 0, 'total_discount' => 0, 'total_tax' => 0,
                $kind === 'sale' ? 'total_price' : 'total_cost' => 0, 'order_tax_rate' => 0, 'order_tax' => 0,
                'grand_total' => 0, 'return_note' => $data['reason'], 'created_at' => $data['business_date'],
                'note_type' => $data['note_type'], 'adjustment_type' => $data['adjustment_type'], 'status' => 'draft',
                'idempotency_key' => $key, 'request_hash' => $hash, 'tax_snapshot_json' => $source->tax_snapshot_json
                    ? $source->tax_snapshot_json + ['sign' => $reduction ? -1 : 1, 'source_document_no' => $source->reference_no] : null,
                'attributes_json' => ['source_no' => $source->reference_no, 'reduction' => $reduction],
            ] + ($kind === 'sale' ? ['biller_id' => $source->biller_id] : []));
            $note->save();
            $this->buildLines($note, $source, $kind, $data, $context, $actor);
            $amount = $note->products->sum('total'); $tax = $note->products->sum('tax'); $qty = $note->products->sum('qty');
            $roundOff = $kind === 'sale' && $reduction && $data['adjustment_type'] === 'quantity' ? $this->roundOffReturned($source, $note, $context) : 0.0;
            $note->forceFill(['total_qty' => $qty, 'total_tax' => $tax, $kind === 'sale' ? 'total_price' : 'total_cost' => $amount,
                'grand_total' => round($amount + $roundOff, 4)] + ($roundOff ? ['attributes_json' => $note->attributes_json + ['round_off' => $roundOff]] : []))->save();
            $policy = \App\Models\Company::findOrFail($context->companyId)->settings_json['return_policy'] ?? null;
            $late = \Carbon\CarbonImmutable::parse($source->created_at)->diffInDays($data['business_date']) > ($policy['days'] ?? 0);
            $approval = !$policy || $amount > ($policy['amount'] ?? 0) || $late;
            if ($approval) {
                $note->forceFill(['status' => 'awaiting_approval'])->save();
                return $note->load('products');
            }
            return $this->post($note, $kind, $context, $actor);
        });
    }

    public function approve(string $kind, int $id, CompanyContext $context, int $actor): Returns|ReturnPurchase
    {
        abort_unless(config('commercial.enabled') && config('compliance.enabled'), 503, 'Compliance posting remains gated.');
        abort_unless(in_array($kind, ['sale', 'purchase'], true), 404);
        $context = app(CompanyWriteGuard::class)->context($context, $actor);
        app(CommercialPermission::class)->assert('returns-approve', $context, $actor);
        return DB::transaction(function () use ($kind, $id, $context, $actor) {
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $model = $kind === 'sale' ? Returns::class : ReturnPurchase::class;
            $note = $model::forCompany($context)->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)->lockForUpdate()->findOrFail($id);
            if ($note->posted_at) return $note;
            if ($note->status !== 'awaiting_approval') $this->invalid('note', 'Only a pending note can be approved.');
            $note->forceFill(['approved_by' => $actor, 'approved_at' => now()])->save();
            return $this->post($note, $kind, $context, $actor);
        });
    }

    /** The note that returns the last of every sold quantity also reverses the invoice round-off, so the notes add up to the invoice. */
    private function roundOffReturned(Sale $source, Returns $note, CompanyContext $context): float
    {
        $roundOff = (float) ($source->attributes_json['round_off'] ?? 0);
        if (!$roundOff) return 0.0;
        $returned = ProductReturn::forCompany($context)->whereIn('return_id', Returns::forCompany($context)->where('sale_id', $source->id)
            ->where('note_type', 'credit')->where(fn ($q) => $q->whereNotNull('posted_at')->orWhere('id', $note->id))->select('id'))
            ->groupBy('source_line_id')->selectRaw('source_line_id, SUM(qty) as qty')->pluck('qty', 'source_line_id');
        $complete = $source->productSales()->forCompany($context)->get()->every(fn ($line) => abs((float) ($returned[$line->id] ?? 0) - (float) $line->qty) < 0.00005);
        return $complete ? $roundOff : 0.0;
    }

    private function source(string $kind, int $id, CompanyContext $context): Sale|Purchase
    {
        $model = $kind === 'sale' ? Sale::class : Purchase::class;
        $source = $model::visibleIn($context)->lockForUpdate()->findOrFail($id);
        if (!$source->posted_at || $source->reversed_at || (int) $source->branch_id !== $context->branchId) {
            $this->invalid('source', 'Use an active posted source in the selected branch. Legacy documents require reviewed migration.');
        }
        return $source;
    }

    private function buildLines(Returns|ReturnPurchase $note, Sale|Purchase $source, string $kind, array $data, CompanyContext $context, int $actor): void
    {
        $originals = ($kind === 'sale' ? $source->productSales() : $source->productPurchases())->forCompany($context)->lockForUpdate()->get()->keyBy('id');
        $model = $kind === 'sale' ? ProductReturn::class : PurchaseProductReturn::class;
        $seen = [];
        foreach ($data['items'] as $input) {
            $line = $originals[$input['line_id']] ?? null;
            if (!$line || isset($seen[$line->id])) $this->invalid('line_id', 'Select distinct lines from the source document.');
            $seen[$line->id] = true;
            $product = app(CompanyWriteGuard::class)->owned(Product::class, $line->product_id, $context, 'product');
            $physical = !in_array($product->type, ['service', 'digital'], true);
            $qty = $data['adjustment_type'] === 'quantity' ? app(CommercialPricing::class)->number($input['qty'] ?? null, 'qty', 4) : 0;
            $disposition = $input['disposition'] ?? 'restock';
            $warehouse = (int) ($input['warehouse_id'] ?? $source->warehouse_id);
            app(CompanyWriteGuard::class)->warehouse($warehouse, $context, $actor);
            // Goods go back to the supplier from the bill's warehouse, or from quarantine where rejected goods wait.
            if ($kind === 'purchase' && $warehouse !== (int) $source->warehouse_id
                && !(Schema::hasColumn('warehouses', 'is_quarantine') && DB::table('warehouses')->where('id', $warehouse)->where('is_quarantine', true)->exists())) {
                $this->invalid('warehouse_id', 'Return goods from the bill\'s warehouse or from a quarantine warehouse.');
            }
            $snapshot = $line->tax_snapshot_json;
            $baseOriginal = $snapshot ? $snapshot['taxable_value'] : (float) $line->total - (float) $line->tax;
            if (!$snapshot && $source->order_discount) {
                $weights = $originals->values()->map(fn ($l) => LedgerAmount::units((float) $l->total))->all();
                $discounts = \App\Services\Tax\TaxDeterminationService::spread(LedgerAmount::units((float) $source->order_discount), $weights);
                $index = $originals->keys()->search($line->id);
                $baseOriginal -= $discounts[$index] / 10000;
            }
            if ($data['adjustment_type'] === 'quantity') {
                if ($qty <= 0) $this->invalid('qty', 'Return quantity must be positive.');
                $base = round($baseOriginal * $qty / (float) $line->qty, 4);
                $tax = round((float) $line->tax * $qty / (float) $line->qty, 4);
            } else {
                $base = app(CommercialPricing::class)->number($input['amount'] ?? null, 'amount');
                $tax = round($base * (float) $line->tax_rate / 100, 4);
                if ($data['adjustment_type'] === 'tax_correction') { $tax = $base; $base = 0; }
                if ($base + $tax <= 0) $this->invalid('amount', 'A financial note must change value.');
            }
            if ($snapshot) {
                $factor = $data['adjustment_type'] === 'quantity' ? $qty / (float) $line->qty
                    : ($baseOriginal > 0 ? $base / $baseOriginal : 0);
                if ($data['adjustment_type'] === 'tax_correction') {
                    $originalTax = array_sum(array_intersect_key($snapshot, array_flip(['cgst', 'sgst', 'igst', 'cess'])));
                    if ($originalTax <= 0) $this->invalid('amount', 'A zero-tax source cannot use this tax correction path.');
                    $factor = $tax / $originalTax;
                }
                foreach (['cgst', 'sgst', 'igst', 'cess'] as $component) $snapshot[$component] = round($snapshot[$component] * $factor, 4);
                $snapshot['taxable_value'] = $base; $snapshot['qty'] = $qty;
                $snapshot['discount'] = 0;
                $tax = $snapshot['reverse_charge'] ? 0 : round(array_sum(array_intersect_key($snapshot, array_flip(['cgst', 'sgst', 'igst', 'cess']))), 4);
            }
            if ($data['adjustment_type'] === 'quantity') {
                $headerTable = $kind === 'sale' ? 'returns' : 'return_purchases';
                $prior = $model::forCompany($context)->where('source_line_id', $line->id)
                    ->whereIn('return_id', DB::table($headerTable)->where('company_id', $context->companyId)->whereNotNull('posted_at')
                        ->where('note_type', $kind === 'sale' ? 'credit' : 'debit')->select('id'))->get();
                // Final quantity return consumes the exact saved residual, including document discount and split-tax rounding.
                if (abs((float) $prior->sum('qty') + $qty - (float) $line->qty) < 0.00005 && !$prior->contains(fn ($p) => !$p->qty)) {
                    $base = round($baseOriginal - $prior->sum(fn ($p) => (float) $p->total - (float) $p->tax), 4);
                    $tax = round((float) $line->tax - (float) $prior->sum('tax'), 4);
                    if ($snapshot) {
                        foreach (['cgst', 'sgst', 'igst', 'cess'] as $component) $snapshot[$component] = round($line->tax_snapshot_json[$component]
                            - $prior->sum(fn ($p) => $p->tax_snapshot_json[$component] ?? 0), 4);
                        $snapshot['taxable_value'] = $base;
                    }
                }
            }
            $stock = [];
            if ($qty > 0 && $physical) {
                $quarantine = (bool) DB::table('warehouses')->where('id', $warehouse)->value('is_quarantine');
                if ($kind === 'sale' && (($disposition !== 'restock') !== $quarantine)) $this->invalid('warehouse_id', 'Non-sellable returns require a quarantine warehouse; restock requires a sellable warehouse.');
                $stock = $this->stockChunks($source, $line, $kind, $qty, $input['stock_line_ids'] ?? [], $context);
                if ($kind === 'sale' && $disposition === 'restock') {
                    foreach ($stock as $chunk) {
                        if ($chunk['batch_id'] && DB::table('product_batches')->where('id', $chunk['batch_id'])->value('expired_date') < $data['business_date']) {
                            $this->invalid('disposition', 'Expired batches must enter quarantine.');
                        }
                    }
                }
            }
            $record = (new $model)->forceFill([
                'company_id' => $context->companyId, 'return_id' => $note->id, 'source_line_id' => $line->id,
                'product_id' => $line->product_id, 'variant_id' => $line->variant_id, 'product_batch_id' => $line->product_batch_id,
                'qty' => $qty, $kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id' => $line->{$kind === 'sale' ? 'sale_unit_id' : 'purchase_unit_id'} ?: 0,
                $kind === 'sale' ? 'net_unit_price' : 'net_unit_cost' => $qty ? $base / $qty : 0,
                'discount' => 0, 'tax_rate' => $line->tax_rate, 'tax' => $tax, 'total' => round($base + $tax, 4),
                'disposition' => $disposition, 'stock_details_json' => ['warehouse_id' => $warehouse, 'chunks' => $stock],
                'tax_snapshot_json' => $snapshot,
                'valuation_amount' => $kind === 'purchase' && $line->valuation_amount !== null && $qty ? round((float) $line->valuation_amount * $qty / (float) $line->qty, 4) : null,
            ]);
            $record->save();
        }
        $note->load('products');
        $this->checkLimits($note, $source, $kind, $context);
    }

    private function checkLimits(Returns|ReturnPurchase $note, Sale|Purchase $source, string $kind, CompanyContext $context): void
    {
        $headerTable = $kind === 'sale' ? 'returns' : 'return_purchases'; $lineTable = $kind === 'sale' ? 'product_returns' : 'purchase_product_return';
        if ($note->attributes_json['reduction']) {
            $used = DB::table($headerTable)->where('company_id', $context->companyId)->where($kind.'_id', $source->id)
                ->whereNotNull('posted_at')->where('note_type', $kind === 'sale' ? 'credit' : 'debit')->sum('grand_total');
            if (round((float) $used + (float) $note->grand_total, 4) > (float) $source->grand_total + 0.00005) {
                $this->invalid('amount', 'Total reductions exceed the original document value.');
            }
        }
        foreach ($note->products as $line) {
            $original = ($kind === 'sale' ? $source->productSales() : $source->productPurchases())->forCompany($context)->findOrFail($line->source_line_id);
            $prior = DB::table($lineTable.' as l')->join($headerTable.' as h', 'h.id', '=', 'l.return_id')
                ->where('h.company_id', $context->companyId)->where('l.company_id', $context->companyId)
                ->where('l.source_line_id', $line->source_line_id)->whereNotNull('h.posted_at')
                ->where('h.note_type', $kind === 'sale' ? 'credit' : 'debit');
            $max = $kind === 'purchase' && !in_array(Product::findOrFail($line->product_id)->type, ['service', 'digital'], true) ? $original->recieved : $original->qty;
            if ((float) $prior->sum('l.qty') + (float) $line->qty > (float) $max + 0.00005) $this->invalid('qty', 'Return exceeds source quantity received or sold.');
            if ($note->attributes_json['reduction'] && round((float) $prior->sum('l.total') + (float) $line->total, 4) > (float) $original->total + 0.00005) {
                $this->invalid('amount', 'Credit/debit reductions exceed the original line value.');
            }
        }
    }

    private function stockChunks(Sale|Purchase $source, object $line, string $kind, float $qty, array $selected, CompanyContext $context): array
    {
        $movements = StockMovementLine::where('stock_movement_lines.company_id', $context->companyId)
            ->where('product_id', $line->product_id)->whereHas('movement', fn ($q) => $q->where('source_type', $kind)->where('source_id', $source->id)
                ->where('branch_id', $context->branchId)->where('projection_mode', 'applied')->where('status', 'posted')->whereNull('reversal_of_id'))
            ->orderBy('id')->get()->filter(fn ($m) => (int) ($m->attributes_json['commercial_line_id'] ?? 0) === $line->id)->values();
        // Quantities settled from a Customer DC / Supplier GRN moved on that document's stock lines.
        $materials = app(MaterialDocumentService::class);
        $available = $movements->mapWithKeys(fn ($movement) => [$movement->id => abs((float) $movement->qty_base)])->all();
        $materialLines = $materials->stockLinesFor($kind, $line->id, $context);
        $materialLines->each(fn ($movement) => $movement->loadMissing('movement'));
        $settlements = $materials->settledLines($kind, $source->id, $context)[$line->id]['rows'] ?? collect();
        // The bill owns only its settled share of a material item, even when that item's original stock line is larger.
        foreach ($settlements as $settlement) {
            $remaining = (float) $settlement->qty_base;
            foreach ($materialLines as $movement) {
                if ((int) $movement->movement->source_id !== (int) $settlement->document_id
                    || (int) ($movement->attributes_json['material_item_id'] ?? 0) !== (int) $settlement->document_item_id) continue;
                $take = min($remaining, max(0, abs((float) $movement->qty_base) - ($available[$movement->id] ?? 0)));
                $available[$movement->id] = round(($available[$movement->id] ?? 0) + $take, 4);
                $remaining = round($remaining - $take, 4);
                if ($remaining <= 0.00005) break;
            }
            if ($remaining > 0.00005) $this->invalid('stock', 'Settled material stock history does not cover the source line.');
        }
        $movements = $movements->concat($materialLines)->unique('id')->filter(fn ($movement) => ($available[$movement->id] ?? 0) > 0)->values();
        if ($movements->isEmpty()) $this->invalid('stock', 'Stock history requires reviewed commercial line attribution before return.');
        if ($selected) {
            if (count(array_unique($selected)) !== count($selected) || array_diff($selected, $movements->pluck('id')->all())) $this->invalid('stock_line_ids', 'Select distinct stock identities from this source line.');
            $movements = $movements->whereIn('id', $selected)->values();
        } elseif ($movements->contains(fn ($m) => $m->stock_identity_id)) {
            $this->invalid('stock_line_ids', 'Select the exact serial or dimensional identities being returned.');
        }
        $ratio = $kind === 'purchase' ? (float) $line->recieved : (float) $line->qty;
        // Base/entered ratio is the posting-time ratio, unaffected by later UOM changes.
        $needed = round(array_sum($available) * $qty / $ratio, 4);
        $priorModel = $kind === 'sale' ? ProductReturn::class : PurchaseProductReturn::class;
        $headerTable = $kind === 'sale' ? 'returns' : 'return_purchases';
        $prior = $priorModel::forCompany($context)->where('source_line_id', $line->id)
            ->whereIn('return_id', DB::table($headerTable)->where('company_id', $context->companyId)->whereNotNull('posted_at')->select('id'))->get();
        $used = [];
        foreach ($prior as $p) foreach ($p->stock_details_json['chunks'] ?? [] as $chunk) $used[$chunk['source_stock_line_id']] = ($used[$chunk['source_stock_line_id']] ?? 0) + $chunk['qty'];
        $chunks = [];
        foreach ($movements as $m) {
            $take = min($needed, max(0, $available[$m->id] - ($used[$m->id] ?? 0)));
            if ($take <= 0) continue;
            if ($m->stock_identity_id && $m->identity?->identity_type === 'serial' && $take !== 1.0) $this->invalid('qty', 'A serial return must restore one whole base unit.');
            $chunks[] = ['source_stock_line_id' => $m->id, 'product_id' => $m->product_id, 'qty' => $take,
                'variant_id' => $m->variant_id, 'batch_id' => $m->batch_id, 'stock_identity_id' => $m->stock_identity_id,
                'unit_cost' => (float) $m->unit_cost];
            $needed = round($needed - $take, 4);
            if ($needed <= 0) break;
        }
        if ($needed > 0.00005) $this->invalid('qty', 'Selected stock has already been returned or does not cover the quantity.');
        return $chunks;
    }

    private function post(Returns|ReturnPurchase $note, string $kind, CompanyContext $context, int $actor): Returns|ReturnPurchase
    {
        $date = $note->created_at->toDateString();
        app(CompanyWriteGuard::class)->begin($context, $date);
        $source = $this->source($kind, $note->{$kind.'_id'}, $context);
        $note->load('products'); $this->checkLimits($note, $source, $kind, $context);
        $type = $kind.'_'.$note->note_type.'_note';
        $numbers = app(DocumentNumberService::class); $reservation = $numbers->reserve($type, $context, $date, $actor);
        $note->forceFill(['reference_no' => $reservation->formatted_number])->save(); $numbers->assign($reservation, $note);
        $stockValue = 0;
        foreach ($note->products as $line) {
            $chunks = $line->stock_details_json['chunks'] ?? [];
            if (!$chunks) continue;
            // Revalidate cumulative identities at approval time, under the source/company lock.
            $original = ($kind === 'sale' ? $source->productSales() : $source->productPurchases())->findOrFail($line->source_line_id);
            $current = $this->stockChunks($source, $original, $kind, (float) $line->qty, array_column($chunks, 'source_stock_line_id'), $context);
            if ($current != $chunks) $this->invalid('stock', 'Selected source stock changed after submission. Submit a new return with the remaining identities.');
            $command = new StockMovementCommand(date: $date, lines: array_map(fn ($chunk) => StockLine::fromArray(
                ['attributes' => ['return_line_id' => $line->id]] + $chunk), $chunks),
                warehouseId: $line->stock_details_json['warehouse_id'], sourceType: $type, sourceId: $note->id, sourceNo: $note->reference_no,
                idempotencyKey: $type.':'.$note->id.':'.$line->id, reason: $note->return_note, userId: $actor, context: $context,
                purpose: $kind === 'sale' ? 'customer_return' : 'purchase_return');
            $movement = $kind === 'sale' ? app(InventoryMovementService::class)->receive($command) : app(InventoryMovementService::class)->issue($command);
            $stockValue += abs(LedgerAmount::units((float) $movement->lines->sum('value')));
        }
        $accounts = app(PostingAccounts::class); $journalLines = [];
        $sign = $note->attributes_json['reduction'] ? 1 : -1;
        $add = function (string $role, int $debit, int $credit, bool $party = false) use (&$journalLines, $accounts, $context, $source, $kind, $sign, $actor) {
            if (!$debit && !$credit) return;
            if ($sign < 0) [$debit, $credit] = [$credit, $debit];
            if ($debit < 0) { $credit -= $debit; $debit = 0; } if ($credit < 0) { $debit -= $credit; $credit = 0; }
            $journalLines[] = ['chart_of_account_id' => $accounts->account($role, $context, $actor), 'debit' => LedgerAmount::decimal($debit), 'credit' => LedgerAmount::decimal($credit),
                'partner_type' => $party ? ($kind === 'sale' ? 'customer' : 'supplier') : null, 'partner_id' => $party ? $source->{$kind === 'sale' ? 'customer_id' : 'supplier_id'} : null];
        };
        $grand = LedgerAmount::units((float) $note->grand_total); $tax = LedgerAmount::units((float) $note->total_tax);
        if ($kind === 'sale') {
            $roundOff = LedgerAmount::units((float) ($note->attributes_json['round_off'] ?? 0));
            if ($roundOff) app(\App\Services\Tax\GstSetupService::class)->ensureLedgers($context->companyId, ['rounding']);
            $add('sales_returns', $grand - $roundOff - $tax, 0); $add('rounding', $roundOff, 0); $add('accounts_receivable', 0, $grand, true);
            $add('inventory', $stockValue, 0); $add('cogs', 0, $stockValue);
        } else {
            $add('accounts_payable', $grand, 0, true);
            $recoverable = $note->tax_snapshot_json ? LedgerAmount::units($note->products->sum(fn ($l) => $l->tax_snapshot_json['input_credit_allowed'] ? (float) $l->tax : 0)) : 0;
            $net = $grand - $recoverable;
            $add('inventory', 0, $stockValue);
            $add($stockValue ? 'variance' : 'operating_expense', 0, $net - $stockValue);
        }
        if ($note->tax_snapshot_json) {
            foreach (['cgst', 'sgst', 'igst', 'cess'] as $component) {
                $units = LedgerAmount::units($note->products->sum(fn ($l) => $kind === 'purchase' && !$l->tax_snapshot_json['input_credit_allowed'] ? 0 : ($l->tax_snapshot_json[$component] ?? 0)));
                if ($note->tax_snapshot_json['reverse_charge'] && $kind === 'sale') $units = 0;
                $add(($kind === 'sale' ? 'output' : 'input').'_tax_'.$component, $kind === 'sale' ? $units : 0, $kind === 'sale' ? 0 : $units);
                if ($note->tax_snapshot_json['reverse_charge'] && $kind === 'purchase') {
                    $liability = LedgerAmount::units($note->products->sum(fn ($l) => $l->tax_snapshot_json[$component] ?? 0));
                    $add('output_tax_'.$component, $liability, 0); $add('operating_expense', 0, $liability - $units);
                }
            }
        } elseif ($kind === 'sale') $add('tax_payable', $tax, 0);
        if ($journalLines) {
            $journal = app(AccountingService::class)->postJournalEntry(['entry_date' => $date, 'reference_type' => $type, 'reference_id' => $note->id,
                'reference_no' => $note->reference_no, 'posting_key' => $type.':'.$note->id.':v1', 'description' => $note->return_note, 'created_by' => $actor], $journalLines, $context);
            if ($sign > 0) {
                $positive = AccountOpenItem::forCompany($context)->where('branch_id', $context->branchId)->where('source_type', $kind)->where('source_id', $source->id)->where('open_amount', '>', 0)->first();
                $negative = AccountOpenItem::forCompany($context)->where('journal_entry_id', $journal->id)->where('open_amount', '<', 0)->first();
                if ($positive && $negative) app(OpenItemService::class)->allocate($positive->id, $negative->id,
                    LedgerAmount::decimal(min(LedgerAmount::units($positive->open_amount), -LedgerAmount::units($negative->open_amount))),
                    $date, 'note:'.$type.':'.$note->id, $context, $journal->id, $actor);
            }
        }
        $note->forceFill(['document_snapshot_json' => app(\App\Services\Documents\DocumentRenderingService::class)->capture($note, $kind.'_note', $context),
            'posted_at' => now(), 'status' => 'posted'])->save();
        $snapshotLines = $note->products->pluck('tax_snapshot_json')->all();
        if ($note->tax_snapshot_json) {
            app(GstProjectionService::class)->record($note, $type, $snapshotLines, $context);
        }
        app(CommercialApplicationService::class)->audit('note_posted', $source, $context, $actor, ['note_type' => $type, 'note_id' => $note->id, 'reason' => $note->return_note, 'approved_by' => $note->approved_by]);
        return $note->load('products');
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
