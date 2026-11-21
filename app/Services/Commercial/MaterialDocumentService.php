<?php

namespace App\Services\Commercial;

use App\Models\DeliveryChallan;
use App\Models\GoodsReceivedNote;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockMovementLine;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Accounting\AccountingService;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Inventory\UomConversionService;
use App\Services\Platform\CompanyContext;
use App\Support\LedgerAmount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Physical movement first, commercial settlement later.
 *
 * A Supplier GRN receives stock at its own cost (Dr Inventory, Cr Goods Received Not Invoiced) and a Customer DC issues
 * stock (Dr Goods Delivered Not Invoiced, Cr Inventory). The later bill or invoice never moves those quantities again:
 * it settles them line by line (material_settlements), clearing the accrual instead of touching stock. Unbilled
 * quantities can be returned, which moves the goods back and clears the same accrual.
 */
class MaterialDocumentService
{
    public const EPSILON = 0.00005;

    /** Rule 55 cases in which goods may leave on a delivery challan before the tax invoice. */
    public const DC_PURPOSES = [
        'approval' => 'On approval / sale or return',
        'quantity_unknown' => 'Quantity known only at delivery',
        'not_supply' => 'Not a supply (exhibition, repair, own use)',
    ];

    public function enabled(): bool
    {
        return (bool) config('commercial.enabled') && Schema::hasTable('material_settlements');
    }

    /** Post the document's stock movement and accrual journal. */
    public function post(GoodsReceivedNote|DeliveryChallan $document, CompanyContext $context, int $actor): void
    {
        $grn = $document instanceof GoodsReceivedNote;
        $guard = app(CompanyWriteGuard::class);
        $date = $guard->begin($context, $this->date($document));
        $document = $document::query()->where('company_id', $context->companyId)->lockForUpdate()->findOrFail($document->id);
        if ($document->posted_at) {
            return;
        }
        $this->assertContext($document, $context);
        if (!$grn && !array_key_exists((string) $document->purpose, self::DC_PURPOSES)) {
            throw ValidationException::withMessages(['purpose' => 'Select the purpose of this Customer DC. Use an invoice for a normal sale.']);
        }
        $guard->warehouse($document->warehouse_id, $context, $actor);
        $items = $document->items()->orderBy('id')->lockForUpdate()->get();
        $products = Product::forCompany($context)->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if (!$product || $product->type !== 'standard') {
                throw ValidationException::withMessages(['product_id' => 'A '.($grn ? 'GRN' : 'delivery challan').' carries stock items only; bill services directly.']);
            }
            if ($product->is_imei) {
                throw ValidationException::withMessages(['product_id' => $product->name.' is serial-numbered; '.($grn ? 'receive it on the purchase bill.' : 'sell it on the invoice.')]);
            }
            $lines[] = StockLine::fromArray(['product_id' => $product->id, 'qty' => $item->qty, 'uom_id' => $item->unit_id,
                'variant_id' => $item->variant_id,
                'batch' => $item->batch_no ? ['batch_no' => $item->batch_no] : null,
                'attributes' => ['material_item_id' => $item->id]] + ($grn ? ['unit_cost' => (float) $item->cost] : []));
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['product_id' => 'Add at least one item.']);
        }
        $command = new StockMovementCommand(date: $date, lines: $lines, warehouseId: $document->warehouse_id,
            sourceType: $this->type($document), sourceId: $document->id, sourceNo: $this->number($document),
            idempotencyKey: $this->key($document).':v'.(int) $document->posting_version, userId: $actor, context: $context);
        $inventory = app(InventoryMovementService::class);
        $movement = $grn ? $inventory->receive($command) : $inventory->issue($command);

        $total = 0;
        foreach ($items as $item) {
            $own = $movement->lines->filter(fn ($line) => (int) ($line->attributes_json['material_item_id'] ?? 0) === $item->id);
            $value = abs(LedgerAmount::units($own->sum('value')));
            $total += $value;
            $item->forceFill(['qty_base' => round(abs((float) $own->sum('qty_base')), 4), 'value' => LedgerAmount::decimal($value)])->save();
        }
        $journal = $total > 0 ? $this->journal($document, $date, $grn
            ? [['inventory', $total], ['goods_received_not_invoiced', -$total]]
            : [['goods_delivered_not_invoiced', $total], ['inventory', -$total]],
            $this->key($document).':v'.(int) $document->posting_version, ($grn ? 'Goods received ' : 'Goods delivered ').$this->number($document), $context, $actor) : null;
        $document->forceFill(['stock_movement_id' => $movement->id, 'journal_entry_id' => $journal?->id, 'posted_at' => now()])->save();
    }

    /** Undo an unsettled posting before an edit or cancellation; the edit then posts the next version. */
    public function unpost(GoodsReceivedNote|DeliveryChallan $document, string $reason, CompanyContext $context, int $actor): void
    {
        $document = $document::query()->where('company_id', $context->companyId)->lockForUpdate()->findOrFail($document->id);
        if (!$document->posted_at) {
            return;
        }
        if ($document->items()->where(fn ($q) => $q->where('billed_qty_base', '>', 0)->orWhere('returned_qty_base', '>', 0))->exists()) {
            throw ValidationException::withMessages(['status' => 'Billed or returned goods cannot be changed; reverse the bill or return first.']);
        }
        $date = app(CompanyWriteGuard::class)->begin($context, $this->date($document));
        if ($document->stock_movement_id) {
            app(InventoryMovementService::class)->reverse($document->stock_movement_id, $reason, $actor, $date);
        }
        if ($document->journal_entry_id) {
            app(AccountingPostingService::class)->reverse($document->journal_entry_id, $date, $reason, $context);
        }
        $document->items()->update(['qty_base' => null, 'value' => null]);
        $document->forceFill(['stock_movement_id' => null, 'journal_entry_id' => null, 'posted_at' => null,
            'posting_version' => (int) $document->posting_version + 1])->save();
    }

    /**
     * Send unbilled goods back: the supplier takes back GRN stock, or the customer returns DC stock.
     *
     * @param array<int, float>|null $quantities document item id => quantity in the item's unit; null returns everything open
     */
    public function returnOpen(GoodsReceivedNote|DeliveryChallan $document, ?array $quantities, string $date, string $reason,
        CompanyContext $context, int $actor, ?string $retryKey = null): void
    {
        $grn = $document instanceof GoodsReceivedNote;
        app(CommercialPermission::class)->assert($grn ? 'grn-index' : 'delivery-challans-index', $context, $actor);
        if (strlen(trim($reason)) < 3 || strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason between 3 and 500 characters.']);
        }
        $date = app(CompanyWriteGuard::class)->begin($context, $date);
        \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
        $document = $document::query()->where('company_id', $context->companyId)->lockForUpdate()->findOrFail($document->id);
        $this->assertContext($document, $context);
        app(CompanyWriteGuard::class)->warehouse($document->warehouse_id, $context, $actor);
        $retryHash = hash('sha256', json_encode([$this->code($document), $document->id, $quantities, $date, $reason]));
        if ($retryKey) {
            $retry = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $retryKey)->first();
            if ($retry) {
                if ($retry->response_type !== 'material_return' || $retry->request_hash !== $retryHash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Key already used for a different request.']);
                }
                return;
            }
        }
        if (!$document->posted_at || !in_array($document->status, ['pending', 'partially_billed'], true)) {
            throw ValidationException::withMessages(['status' => 'Only posted documents with unbilled goods can be returned.']);
        }
        if ($date < $this->date($document)) {
            throw ValidationException::withMessages(['business_date' => 'A return cannot be dated before the document.']);
        }
        $items = $document->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $returns = [];
        foreach ($quantities ?? $items->map(fn ($item) => null)->all() as $itemId => $qty) {
            $item = $items->get((int) $itemId);
            if (!$item) {
                throw ValidationException::withMessages(['items' => 'Return lines must belong to this document.']);
            }
            $open = $this->openBase($item);
            $base = $qty === null ? $open : round((float) $qty * (float) $item->qty_base / (float) $item->qty, 4);
            if ($base < -self::EPSILON || $base > $open + self::EPSILON) {
                throw ValidationException::withMessages(['items' => 'Return at most the unbilled quantity of each line.']);
            }
            if ($base > self::EPSILON) $returns[$item->id] = min($base, $open);
        }
        if ($returns === []) {
            throw ValidationException::withMessages(['items' => 'Nothing is left unbilled to return.']);
        }
        $sequence = DB::table('material_settlements')->where('company_id', $context->companyId)->where('document_type', $this->code($document))
            ->where('document_id', $document->id)->where('settled_by_type', 'return')->count() + 1;
        $key = $this->key($document).':return:'.$sequence;
        $lines = [];
        $accrual = [];
        foreach ($returns as $itemId => $base) {
            $item = $items[$itemId];
            $accrual[$itemId] = (int) round(LedgerAmount::units($item->value) * $base / (float) $item->qty_base);
            $lines[] = StockLine::fromArray(['product_id' => $item->product_id, 'qty' => $base,
                'variant_id' => $item->variant_id,
                'batch' => $item->batch_no ? ['batch_no' => $item->batch_no] : null,
                'attributes' => ['material_item_id' => $item->id, 'material_return' => true]]
                + ($grn ? [] : ['unit_cost' => $accrual[$itemId] / 10000 / $base]));
        }
        $command = new StockMovementCommand(date: $date, lines: $lines, warehouseId: $document->warehouse_id,
            sourceType: $this->type($document), sourceId: $document->id, sourceNo: $this->number($document),
            idempotencyKey: $key, reason: $reason, userId: $actor, context: $context, purpose: $grn ? 'purchase_return' : 'customer_return');
        $inventory = app(InventoryMovementService::class);
        $movement = $grn ? $inventory->issue($command) : $inventory->receive($command);
        $stock = abs(LedgerAmount::units($movement->lines->sum('value')));
        $total = array_sum($accrual);
        // Supplier returns leave at the current average cost; the gap to the GRN cost is a stock variance.
        $this->journal($document, $date, $grn
            ? [['goods_received_not_invoiced', $total], ['inventory', -$stock], ['variance', $stock - $total]]
            : [['inventory', $stock], ['goods_delivered_not_invoiced', -$total], ['variance', $total - $stock]],
            $key, 'Return of '.$this->number($document).': '.$reason, $context, $actor);
        foreach ($returns as $itemId => $base) {
            $this->settle($document, $items[$itemId], 'return', $movement->id, null, $base, $accrual[$itemId], $context);
        }
        $this->refreshStatus($document);
        app(OrderLifecycleService::class)->returnMaterial($document, $returns, $context);
        if ($retryKey) DB::table('idempotency_keys')->insert(['company_id' => $context->companyId, 'key' => $retryKey,
            'request_hash' => $retryHash, 'response_type' => 'material_return', 'response_ref' => $document->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Resolve source documents under the posting transaction, before any bill is created. */
    public function sources(string $kind, array $data, CompanyContext $context, int $actor, string $date): Collection
    {
        $field = $kind === 'sale' ? 'delivery_challan_id' : 'goods_received_note_id';
        validator($data, [$field => 'nullable|integer|min:1', $field.'s' => 'nullable|array|max:100',
            $field.'s.*' => 'integer|min:1|distinct'])->validate();
        $ids = $data[$field.'s'] ?? [];
        if (!empty($data[$field])) {
            if ($ids && $ids !== [(int) $data[$field]] && array_map('intval', $ids) !== [(int) $data[$field]]) {
                throw ValidationException::withMessages([$field => 'Use either one source document or the selected source list.']);
            }
            $ids = [(int) $data[$field]];
        }
        $class = $kind === 'sale' ? DeliveryChallan::class : GoodsReceivedNote::class;
        $sources = collect();
        if ($ids) app(CommercialPermission::class)->assert($kind === 'sale' ? 'delivery-challans-index' : 'grn-index', $context, $actor);
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $source = $class::forCompany($context)->lockForUpdate()->findOrFail($id);
            $this->assertContext($source, $context);
            if ($this->date($source) > $date) throw ValidationException::withMessages([$field => 'Bill date cannot be before the source document date.']);
            if (!in_array($source->status, ['pending', 'partially_billed'], true)
                || (int) $source->{$kind === 'sale' ? 'customer_id' : 'supplier_id'} !== (int) $data[$kind === 'sale' ? 'customer_id' : 'supplier_id']
                || (int) $source->warehouse_id !== (int) $data['warehouse_id']) {
                throw ValidationException::withMessages([$field => 'Select open documents for the same party, branch and warehouse.']);
            }
            if ($kind === 'sale' && $source->purpose === 'not_supply') {
                throw ValidationException::withMessages([$field => 'Other Than Supply goods cannot be converted to a sales invoice.']);
            }
            if ($source->posted_at && (($kind === 'purchase' && (int) ($data['status'] ?? 1) === 4)
                || ($kind === 'sale' && (int) ($data['sale_status'] ?? 1) !== 1))) {
                throw ValidationException::withMessages([$field => 'Settle physically moved goods with a completed bill, not an order or pending sale.']);
            }
            if (count($ids) > 1 && !$source->posted_at) {
                throw ValidationException::withMessages([$field => 'Legacy memo documents require individual reviewed conversion.']);
            }
            $sources->put((int) $source->id, $source);
        }
        return $sources;
    }

    /** Settle source lines once, preserving a bill line's remainder across all selected documents. */
    public function allocateMany(Collection $sources, Sale|Purchase $document, array $input, CompanyContext $context): void
    {
        if ($sources->isEmpty()) return;
        $kind = $document instanceof Sale ? 'sale' : 'purchase';
        $items = $sources->filter(fn ($s) => $s->posted_at)->map(fn ($s) => $s->items()->orderBy('id')->lockForUpdate()->get());
        $lines = ($kind === 'sale' ? $document->productSales() : $document->productPurchases())->orderBy('id')->get();
        $products = Product::forCompany($context)->whereIn('id', $lines->pluck('product_id'))->get()->keyBy('id');
        $counts = [];
        foreach ($lines as $index => $line) {
            $product = $products->get($line->product_id);
            if (!$product || $product->type !== 'standard') continue;
            $remaining = app(UomConversionService::class)->toBase($product, (float) $line->qty, (int) ($kind === 'sale' ? $line->sale_unit_id : $line->purchase_unit_id) ?: null);
            $allocations = $input[$index]['material_sources'] ?? null;
            $batchName = (string) ($input[$index]['batch']['batch_no'] ?? '');
            if ($line->product_batch_id) {
                $batch = DB::table('product_batches')->where('company_id', $context->companyId)->where('product_id', $line->product_id)->find($line->product_batch_id);
                if (!$batch || ($batchName !== '' && $batchName !== (string) $batch->batch_no)) {
                    throw ValidationException::withMessages(['items.product_batch_id' => 'Batch ID and lot name must identify the same source lot.']);
                }
                $batchName = (string) $batch->batch_no;
            }
            if ($sources->count() > 1 && $allocations === null) {
                throw ValidationException::withMessages(['items' => 'Select source lines explicitly when combining material documents.']);
            }
            if ($allocations !== null) validator(['allocations' => $allocations], ['allocations' => 'array|max:100',
                'allocations.*.document_id' => 'required|integer|min:1', 'allocations.*.item_id' => 'required|integer|min:1',
                'allocations.*.qty_base' => 'required|numeric|gt:0|max:1000000000'])->validate();
            foreach ($sources as $id => $material) {
                if (!$material->posted_at) continue;
                foreach ($items[$id] as $item) {
                    $specified = $allocations === null ? null : collect($allocations)->filter(fn ($a) => (int) $a['document_id'] === $id && (int) $a['item_id'] === $item->id)->sum('qty_base');
                    if ($allocations !== null && !$specified) continue;
                    $identityMatches = (int) $item->product_id === (int) $line->product_id
                        && (int) ($item->variant_id ?? 0) === (int) ($line->variant_id ?? 0)
                        && (string) ($item->batch_no ?? '') === $batchName;
                    if (!$identityMatches) {
                        if ($specified) throw ValidationException::withMessages(['items' => 'Source item, variant and lot must match the billed line.']);
                        continue;
                    }
                    $take = $specified ?? min($this->openBase($item), $remaining);
                    if ($take > $this->openBase($item) + self::EPSILON || $take > $remaining + self::EPSILON) {
                        throw ValidationException::withMessages(['items' => 'Source allocation exceeds the open or billed quantity.']);
                    }
                    if ($take <= self::EPSILON) continue;
                    $value = (int) round(LedgerAmount::units($item->value) * $take / (float) $item->qty_base);
                    $this->settle($material, $item, $kind, $document->id, $line->id, $take, $value, $context);
                    $remaining = round($remaining - $take, 4);
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
            if ($allocations !== null) foreach ($allocations as $a) {
                if (!$sources->has((int) $a['document_id']) || !$items->get((int) $a['document_id'], collect())->contains('id', (int) $a['item_id'])) {
                    throw ValidationException::withMessages(['items' => 'Allocation must name an item of a selected source.']);
                }
            }
        }
        foreach ($sources as $id => $material) {
            if (!$material->posted_at) continue;
            if (empty($counts[$id])) throw ValidationException::withMessages(['items' => 'No unbilled source item selected from '.$this->number($material).'.']);
            $this->refreshStatus($material, $document->id);
        }
    }

    public function allocate(GoodsReceivedNote|DeliveryChallan $material, Sale|Purchase $document, CompanyContext $context): void
    {
        $this->allocateMany(collect([$material->id => $material]), $document, [], $context);
    }

    /** @return array<int, array{qty_base: float, value: int, rows: Collection}> per commercial line id */
    public function settledLines(string $kind, int $documentId, CompanyContext $context): array
    {
        if (!Schema::hasTable('material_settlements')) {
            return [];
        }
        return DB::table('material_settlements')->where('company_id', $context->companyId)->where('settled_by_type', $kind)
            ->where('settled_by_id', $documentId)->whereNull('released_at')->orderBy('id')->get()->groupBy('settled_by_line_id')
            ->map(fn ($rows) => ['qty_base' => round((float) $rows->sum('qty_base'), 4),
                'value' => array_sum($rows->map(fn ($row) => LedgerAmount::units($row->value))->all()), 'rows' => $rows])->all();
    }

    /** The GRN/DC stock lines that physically moved what this bill line settled, for returns against the bill. */
    public function stockLinesFor(string $kind, int $lineId, CompanyContext $context): Collection
    {
        if (!Schema::hasTable('material_settlements')) {
            return collect();
        }
        $rows = DB::table('material_settlements')->where('company_id', $context->companyId)->where('settled_by_type', $kind)
            ->where('settled_by_line_id', $lineId)->whereNull('released_at')->get();
        if ($rows->isEmpty()) {
            return collect();
        }
        $type = $kind === 'sale' ? 'delivery_challan' : 'goods_received_note';
        $items = $rows->pluck('document_item_id')->map(fn ($id) => (int) $id)->all();
        return StockMovementLine::where('company_id', $context->companyId)
            ->whereHas('movement', fn ($q) => $q->where('source_type', $type)->whereIn('source_id', $rows->pluck('document_id'))
                ->where('projection_mode', 'applied')->where('status', 'posted')->whereNull('reversal_of_id'))
            ->orderBy('id')->get()
            ->filter(fn ($line) => in_array((int) ($line->attributes_json['material_item_id'] ?? 0), $items, true)
                && empty($line->attributes_json['material_return']))->values();
    }

    public function settledBase(string $kind, int $lineId, CompanyContext $context): float
    {
        if (!Schema::hasTable('material_settlements')) {
            return 0.0;
        }
        return round((float) DB::table('material_settlements')->where('company_id', $context->companyId)->where('settled_by_type', $kind)
            ->where('settled_by_line_id', $lineId)->whereNull('released_at')->sum('qty_base'), 4);
    }

    /**
     * Bill price differs from the GRN cost: the part still in stock revalues it, the part already used goes to COGS.
     *
     * @param list<array{rows: Collection, amount: int}> $variances ledger units per settled bill line
     * @return array{inventory: int, cogs: int}
     */
    public function priceVariance(Purchase $purchase, array $variances, string $date, CompanyContext $context, int $actor): array
    {
        $lines = [];
        $inventory = 0;
        $total = 0;
        $groups = [];
        foreach ($variances as $variance) {
            $total += $variance['amount'];
            $qty = (float) $variance['rows']->sum('qty_base');
            $left = $variance['amount'];
            foreach ($variance['rows']->values() as $i => $row) {
                $share = $i === $variance['rows']->count() - 1 ? $left : (int) round($variance['amount'] * (float) $row->qty_base / $qty);
                $left -= $share;
                $grn = GoodsReceivedNote::query()->where('company_id', $context->companyId)->find($row->document_id);
                $sourceLine = $this->stockLinesFor('purchase', (int) $row->settled_by_line_id, $context)
                    ->first(fn ($line) => (int) $line->attributes_json['material_item_id'] === (int) $row->document_item_id);
                if (!$grn || !$sourceLine) throw ValidationException::withMessages(['valuation' => 'The original GRN stock identity requires review.']);
                $identity = [(int) $row->product_id, (int) $grn->warehouse_id, $sourceLine->variant_id, $sourceLine->batch_id];
                $key = json_encode($identity);
                $groups[$key] ??= ['identity' => $identity, 'qty' => 0, 'amount' => 0, 'sources' => []];
                $groups[$key]['qty'] += (float) $row->qty_base;
                $groups[$key]['amount'] += $share;
                $groups[$key]['sources'][(int) $row->document_id] = (int) $row->document_id;
            }
        }
        // One available quantity pool per identity; repeated sources/lines cannot reuse the same held units.
        foreach ($groups as $group) {
            [$product, $warehouse, $variant, $batch] = $group['identity'];
            $held = (float) DB::table('stock_movement_lines as l')->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
                ->where('l.company_id', $context->companyId)->where('m.company_id', $context->companyId)
                ->where('m.projection_mode', 'applied')->where('m.status', 'posted')
                ->where('m.movement_type', '!=', 'reversal')->whereNull('m.reversal_of_id')
                ->where('l.product_id', $product)->where('l.warehouse_id', $warehouse)
                ->where('l.variant_id', $variant)->where('l.batch_id', $batch)->sum('l.qty_base');
            $onHand = (int) round($group['amount'] * max(0, min(1, $held / $group['qty'])));
            if ($onHand === 0) continue;
            $inventory += $onHand;
            $lines[] = ['product_id' => $product, 'warehouse_id' => $warehouse, 'batch_id' => $batch, 'variant_id' => $variant,
                'value' => $onHand / 10000, 'attributes_json' => ['goods_received_note_ids' => array_values($group['sources']), 'price_variance' => true]];
        }
        if ($lines !== []) {
            app(InventoryMovementService::class)->revalue($date, $lines, 'purchase', $purchase->id, $purchase->reference_no,
                'purchase:'.$purchase->id.':grn-price', $actor, $context, 'Bill price differs from GRN cost');
        }
        return ['inventory' => $inventory, 'cogs' => $total - $inventory];
    }

    /** A reversed bill or invoice reopens the GRN/DC quantities it had settled. */
    public function release(Sale|Purchase $document, CompanyContext $context): void
    {
        if (!Schema::hasTable('material_settlements')) {
            return;
        }
        $kind = $document instanceof Sale ? 'sale' : 'purchase';
        $rows = DB::table('material_settlements')->where('company_id', $context->companyId)->where('settled_by_type', $kind)
            ->where('settled_by_id', $document->id)->whereNull('released_at')->lockForUpdate()->get();
        foreach ($rows->groupBy('document_id') as $documentId => $group) {
            $material = ($kind === 'sale' ? DeliveryChallan::class : GoodsReceivedNote::class)::query()
                ->where('company_id', $context->companyId)->lockForUpdate()->findOrFail($documentId);
            foreach ($group as $row) {
                $material->items()->whereKey($row->document_item_id)->decrement('billed_qty_base', (float) $row->qty_base);
            }
            DB::table('material_settlements')->whereIn('id', $group->pluck('id'))->update(['released_at' => now(), 'updated_at' => now()]);
            $latest = DB::table('material_settlements')->where('company_id', $context->companyId)->where('document_type', $this->code($material))
                ->where('document_id', $material->id)->where('settled_by_type', $kind)->whereNull('released_at')->max('settled_by_id');
            $this->refreshStatus($material, $latest ? (int) $latest : null, true);
        }
    }

    public function openBase(object $item): float
    {
        return round((float) $item->qty_base - (float) $item->billed_qty_base - (float) $item->returned_qty_base, 4);
    }

    /** Open quantity per item, in the item's own unit, for loading the rest of a document into a bill. */
    public function openQuantities(GoodsReceivedNote|DeliveryChallan $document): array
    {
        return $document->items->mapWithKeys(fn ($item) => [$item->id => $document->posted_at && (float) $item->qty_base > 0
            ? round((float) $item->qty * $this->openBase($item) / (float) $item->qty_base, 4) : (float) $item->qty])->all();
    }

    private function settle(GoodsReceivedNote|DeliveryChallan $document, object $item, string $by, int $byId, ?int $lineId,
        float $base, int $value, CompanyContext $context): void
    {
        DB::table('material_settlements')->insert(['company_id' => $context->companyId, 'document_type' => $this->code($document),
            'document_id' => $document->id, 'document_item_id' => $item->id, 'settled_by_type' => $by, 'settled_by_id' => $byId,
            'settled_by_line_id' => $lineId, 'product_id' => $item->product_id, 'qty_base' => $base,
            'value' => LedgerAmount::decimal($value), 'created_at' => now(), 'updated_at' => now()]);
        $item->forceFill([$by === 'return' ? 'returned_qty_base' : 'billed_qty_base'
            => round((float) $item->{$by === 'return' ? 'returned_qty_base' : 'billed_qty_base'} + $base, 4)])->save();
    }

    private function refreshStatus(GoodsReceivedNote|DeliveryChallan $document, ?int $billId = null, bool $replaceBill = false): void
    {
        $grn = $document instanceof GoodsReceivedNote;
        $items = $document->items()->get();
        $open = $items->sum(fn ($item) => max(0, $this->openBase($item)));
        $billed = $items->sum(fn ($item) => (float) $item->billed_qty_base);
        $returned = $items->sum(fn ($item) => (float) $item->returned_qty_base);
        $status = match (true) {
            $open > self::EPSILON => $billed + $returned > self::EPSILON ? 'partially_billed' : 'pending',
            $billed > self::EPSILON => $grn ? 'converted_to_purchase' : 'converted_to_sale',
            default => 'returned',
        };
        $link = $grn ? 'purchase_id' : 'sale_id';
        $document->forceFill(['status' => $status] + ($billId || $replaceBill ? [$link => $billId] : []))->save();
    }

    /** @param list<array{0: string, 1: int}> $amounts role => signed ledger units (debit positive) */
    private function journal(GoodsReceivedNote|DeliveryChallan $document, string $date, array $amounts, string $key, string $description,
        CompanyContext $context, int $actor): ?\App\Models\Accounting\JournalEntry
    {
        $accounts = app(PostingAccounts::class);
        $lines = [];
        foreach ($amounts as [$role, $units]) {
            if ($units === 0) continue;
            $lines[] = ['chart_of_account_id' => $accounts->account($role, $context, $actor),
                'debit' => $units > 0 ? $units / 10000 : 0, 'credit' => $units < 0 ? -$units / 10000 : 0];
        }
        if ($lines === []) {
            return null;
        }
        return app(AccountingService::class)->postJournalEntry(['entry_date' => $date, 'reference_type' => $this->type($document),
            'reference_id' => $document->id, 'reference_no' => $this->number($document), 'posting_key' => $key,
            'created_by' => $actor, 'description' => mb_substr($description.'. Items: '.$document->items()
                ->with('product')->get()->map(fn ($item) => $item->product?->name.' ('.$item->qty.')')->implode(', '), 0, 2000)], $lines, $context);
    }

    private function assertContext(GoodsReceivedNote|DeliveryChallan $document, CompanyContext $context): void
    {
        if (($document->branch_id && (int) $document->branch_id !== $context->branchId)
            || ($document->financial_year_id && (int) $document->financial_year_id !== $context->financialYearId)) {
            throw ValidationException::withMessages(['branch_id' => 'Switch to the branch and financial year the document was entered in.']);
        }
        $document->forceFill(['branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId]);
    }

    private function date(GoodsReceivedNote|DeliveryChallan $document): string
    {
        return ($document instanceof GoodsReceivedNote ? $document->grn_date : $document->challan_date)->toDateString();
    }

    private function type(GoodsReceivedNote|DeliveryChallan $document): string
    {
        return $document instanceof GoodsReceivedNote ? 'goods_received_note' : 'delivery_challan';
    }

    private function code(GoodsReceivedNote|DeliveryChallan $document): string
    {
        return $document instanceof GoodsReceivedNote ? 'grn' : 'dc';
    }

    private function key(GoodsReceivedNote|DeliveryChallan $document): string
    {
        return $this->code($document).':'.$document->id;
    }

    private function number(GoodsReceivedNote|DeliveryChallan $document): string
    {
        return $document instanceof GoodsReceivedNote ? $document->grn_no : $document->challan_no;
    }
}
