<?php

namespace App\Services\Inventory;

use App\Models\Inventory\StockDimension;
use App\Models\Inventory\StockIdentity;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockMovementLine;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Services\Platform\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * The only writer of stock history (doc 09). Each call runs in one transaction that locks the
 * affected products (sorted, so concurrent postings serialize without deadlock), validates
 * batch/serial/piece identities and the negative-stock policy, persists cost/value at posting
 * time and updates the legacy quantity projections.
 */
class InventoryMovementService
{
    public const EPSILON = 0.00005;

    private array $columns = [];

    public function __construct(
        private readonly UomConversionService $uom,
        private readonly InventoryPolicy $policy,
        private readonly LegacyStockShadow $shadow,
    ) {
    }

    public function receive(StockMovementCommand $command): StockMovement
    {
        return $this->post('receipt', $command);
    }

    public function issue(StockMovementCommand $command): StockMovement
    {
        return $this->post('issue', $command);
    }

    public function productionConsume(StockMovementCommand $command): StockMovement
    {
        return $this->post('issue', $command, 'production_consume');
    }

    public function productionOutput(StockMovementCommand $command): StockMovement
    {
        return $this->post('receipt', $command, 'production_output');
    }

    public function productionScrap(StockMovementCommand $command): StockMovement
    {
        return $this->post('receipt', $command, 'production_scrap');
    }

    /** An explicit expiry disposal; expired stock remains blocked in ordinary issue paths. */
    public function writeOffExpired(StockMovementCommand $command): StockMovement
    {
        foreach ($command->lines as $line) {
            $batch = $line->batchId ? DB::table('product_batches')->find($line->batchId) : null;
            if (!$batch || (int) $batch->product_id !== $line->productId || !$batch->expired_date || substr($batch->expired_date, 0, 10) >= $command->date) {
                throw new InvalidArgumentException('Expiry disposal requires an expired batch of the selected product.');
            }
        }
        return $this->post('issue', $command, 'expiry_writeoff');
    }

    public function transfer(StockMovementCommand $command): StockMovement
    {
        return $this->post('transfer', $command);
    }

    /** Line quantities are signed: positive found/added, negative lost/removed. */
    public function adjust(StockMovementCommand $command): StockMovement
    {
        return $this->post('adjustment', $command);
    }

    /** Records signed quantities that already exist in the projections; projections are not changed. */
    public function opening(StockMovementCommand $command): StockMovement
    {
        return $this->post('opening', $command);
    }

    /** A reviewed external opening import starts with zero projections and applies its stock once. */
    public function importOpening(StockMovementCommand $command): StockMovement
    {
        return $this->post('receipt', $command, 'migration_opening');
    }

    /** Empty projection metadata preserves legacy warehouse product selectors; it has no stock effect. */
    public function initializeWarehouse(int $warehouseId, ?CompanyContext $context = null): void
    {
        $this->shadow->paused(fn () => DB::transaction(function () use ($warehouseId, $context) {
            $companyId = $this->companyFor($context, [$warehouseId]);
            $query = Product::query();
            if ($companyId !== null && $this->hasColumn('products', 'company_id')) {
                $query->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
            }
            foreach ($query->orderBy('id')->lockForUpdate()->get() as $product) {
                $this->warehouseRow($companyId, $product->id, $warehouseId, null, null)->save();
            }
        }));
    }

    /** Posts the exact negation of a movement at its original cost and marks it reversed. */
    /**
     * Value-only movement: capitalised cost (e.g. job work processing) added to stock already on hand. Quantities and
     * projections never change; the weighted average of each product moves because its ledger value does.
     *
     * @param array<int, array{product_id:int, warehouse_id:int, value:float, batch_id?:?int, variant_id?:?int}> $lines
     */
    public function revalue(string $date, array $lines, string $sourceType, int $sourceId, ?string $sourceNo, string $idempotencyKey,
        ?int $userId, CompanyContext $context, ?string $reason = null): StockMovement
    {
        return $this->shadow->paused(fn () => DB::transaction(function () use ($date, $lines, $sourceType, $sourceId, $sourceNo, $idempotencyKey, $userId, $context, $reason) {
            if ($existing = $this->replay('revaluation', $idempotencyKey)) {
                return $existing;
            }
            $lines = array_values(array_filter($lines, fn ($line) => abs((float) $line['value']) >= 0.0001));
            if ($lines === []) {
                throw new InvalidArgumentException('A revaluation needs at least one non-zero value.');
            }
            $companyId = $this->companyFor($context, array_values(array_unique(array_map(fn ($line) => (int) $line['warehouse_id'], $lines))));
            $this->lockProducts(array_column($lines, 'product_id'), $companyId);
            $movement = $this->createHeader('revaluation', [
                'company_id' => $companyId, 'branch_id' => $context->branchId, 'financial_year_id' => $context->financialYearId,
                'movement_date' => Carbon::parse($date)->toDateString(), 'source_type' => $sourceType, 'source_id' => $sourceId,
                'source_no' => $sourceNo, 'idempotency_key' => $idempotencyKey, 'reason' => $reason, 'created_by' => $userId,
                'projection_mode' => 'applied',
            ]);
            $this->valueLines($movement, $lines, 1);
            return $movement->load('lines');
        }));
    }

    /** @param iterable<array|object> $lines */
    private function valueLines(StockMovement $movement, iterable $lines, int $sign): void
    {
        $lineNo = 0;
        foreach ($lines as $line) {
            $line = (array) $line;
            StockMovementLine::create([
                'stock_movement_id' => $movement->id, 'company_id' => $movement->company_id, 'line_no' => ++$lineNo,
                'product_id' => (int) $line['product_id'], 'variant_id' => $line['variant_id'] ?? null, 'warehouse_id' => (int) $line['warehouse_id'],
                'uom_id' => null, 'uom_qty' => null, 'qty_base' => 0, 'unit_cost' => 0, 'value' => round($sign * (float) $line['value'], 4),
                'batch_id' => $line['batch_id'] ?? null, 'stock_identity_id' => null,
                'attributes_json' => ($line['attributes_json'] ?? null) ? (is_string($line['attributes_json']) ? json_decode($line['attributes_json'], true) : $line['attributes_json']) : null,
            ]);
        }
    }

    public function reverse(StockMovement|int $movement, string $reason, ?int $userId = null, ?string $date = null): StockMovement
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A stock reversal needs a reason.');
        }

        return $this->shadow->paused(fn () => DB::transaction(function () use ($movement, $reason, $userId, $date) {
            $original = StockMovement::whereKey($movement instanceof StockMovement ? $movement->id : $movement)
                ->lockForUpdate()->firstOrFail();
            if ($original->movement_type === 'reversal') {
                throw new InvalidArgumentException('A reversal cannot itself be reversed; post a new movement instead.');
            }
            if ($original->status !== 'posted') {
                throw new InvalidArgumentException("Stock movement {$original->movement_no} is already reversed.");
            }
            $lines = $original->lines()->get();
            if ($original->movement_type === 'revaluation') {
                // Value-only lines carry no quantity, so they are negated by value rather than rebuilt from qty × cost.
                $this->lockProducts($lines->pluck('product_id')->all(), $original->company_id);
                $reversal = $this->createHeader('reversal', [
                    'company_id' => $original->company_id, 'branch_id' => $original->branch_id, 'financial_year_id' => $original->financial_year_id,
                    'movement_date' => Carbon::parse($date ?? now())->toDateString(), 'source_type' => $original->source_type,
                    'source_id' => $original->source_id, 'source_no' => $original->source_no, 'reversal_of_id' => $original->id,
                    'idempotency_key' => 'reversal:'.$original->id, 'reason' => $reason, 'created_by' => $userId, 'projection_mode' => 'applied',
                ]);
                $this->valueLines($reversal, $lines->map(fn ($line) => $line->only(['product_id', 'variant_id', 'warehouse_id', 'value', 'batch_id', 'attributes_json'])), -1);
                $original->status = 'reversed';
                $original->save();
                return $reversal->load('lines');
            }
            $products = $this->lockProducts($lines->pluck('product_id')->all(), $original->company_id);

            $reversal = $this->createHeader('reversal', [
                'company_id' => $original->company_id,
                'branch_id' => $original->branch_id,
                'financial_year_id' => $original->financial_year_id,
                'movement_date' => Carbon::parse($date ?? now())->toDateString(),
                'source_type' => $original->source_type,
                'source_id' => $original->source_id,
                'source_no' => $original->source_no,
                'warehouse_from_id' => $original->warehouse_to_id,
                'warehouse_to_id' => $original->warehouse_from_id,
                'reversal_of_id' => $original->id,
                'idempotency_key' => 'reversal:'.$original->id,
                'reason' => $reason,
                'created_by' => $userId,
                'projection_mode' => $original->projection_mode ?? 'applied',
            ]);
            // Openings and shadow movements only described projections, so their reversals do not change them either.
            $describes = $original->movement_type === 'opening' || $original->projection_mode === 'shadow';
            $run = new PostingRun($reversal, $original->company_id, !$describes, 'removed', $original->projection_mode === 'shadow');

            // Remove stock before restoring it so identities never appear in two places.
            foreach ($lines->sortBy(fn ($line) => [(float) $line->qty_base > 0 ? 0 : 1, $line->line_no]) as $line) {
                $this->postLine($run, $products[$line->product_id], [
                    'warehouse_id' => (int) $line->warehouse_id,
                    'qty' => -(float) $line->qty_base,
                    'unit_cost' => (float) $line->unit_cost,
                    'variant_id' => $line->variant_id,
                    'batch_id' => $line->batch_id,
                    'identity' => $line->stock_identity_id ? StockIdentity::find($line->stock_identity_id) : null,
                    'uom_id' => $line->uom_id,
                    'uom_qty' => $line->uom_qty === null ? null : -(float) $line->uom_qty,
                    'attributes' => $line->attributes_json ?? [],
                ]);
            }
            $original->status = 'reversed';
            $original->save();

            return $this->finish($run);
        }));
    }

    private function post(string $type, StockMovementCommand $command, ?string $ledgerType = null): StockMovement
    {
        // The service's own projection writes are never captured as legacy shadow changes.
        $ledgerType ??= $type;
        return $this->shadow->paused(fn () => DB::transaction(function () use ($type, $ledgerType, $command) {
            if ($command->idempotencyKey !== null && ($existing = $this->replay($ledgerType, $command->idempotencyKey))) {
                return $existing;
            }
            $from = $command->warehouseId;
            $warehouseIds = [];
            if ($type === 'transfer') {
                if ($from === null || $command->toWarehouseId === null || $from === $command->toWarehouseId) {
                    throw new InvalidArgumentException('A transfer needs different source and destination warehouses.');
                }
                $warehouseIds = [$from, $command->toWarehouseId];
            }
            foreach ($command->lines as $line) {
                $warehouseId = $line->warehouseId ?? $from;
                if ($warehouseId === null || ($type === 'transfer' && $warehouseId !== $from)) {
                    throw new InvalidArgumentException('Every stock line needs a warehouse; transfer lines use the transfer source.');
                }
                $warehouseIds[] = $warehouseId;
            }
            $companyId = $this->companyFor($command->context, array_values(array_unique($warehouseIds)));
            $products = $this->lockProducts(array_map(fn (StockLine $line) => $line->productId, $command->lines), $companyId);

            $movement = $this->createHeader($ledgerType, [
                'company_id' => $companyId,
                'branch_id' => $command->context?->branchId,
                'financial_year_id' => $command->context?->financialYearId,
                'movement_date' => Carbon::parse($command->date)->toDateString(),
                'source_type' => $command->sourceType,
                'source_id' => $command->sourceId,
                'source_no' => $command->sourceNo,
                'warehouse_from_id' => in_array($type, ['issue', 'transfer'], true) ? $from : null,
                'warehouse_to_id' => $type === 'transfer' ? $command->toWarehouseId : ($type === 'issue' ? null : $from),
                'idempotency_key' => $command->idempotencyKey,
                'reason' => $command->reason,
                'created_by' => $command->userId,
                'projection_mode' => $command->shadow ? 'shadow' : 'applied',
            ]);
            $run = new PostingRun($movement, $companyId, $type !== 'opening' && !$command->shadow,
                $type === 'issue' ? StockIdentity::ISSUED : 'removed', $command->shadow, $command->purpose);
            // Only taking goods OUT of quarantine is restricted; moving rejects or damaged goods INTO it is its purpose.
            if (!$command->shadow && in_array($type, ['issue', 'transfer'], true) && $this->hasColumn('warehouses', 'is_quarantine')
                && DB::table('warehouses')->whereIn('id', $type === 'transfer' ? [$from] : $warehouseIds)->where('is_quarantine', true)->exists()
                && !in_array($command->purpose, ['disposal', 'purchase_return', 'rework'], true)) {
                throw new StockPolicyException('Quarantined stock cannot be sold or transferred through ordinary stock operations.');
            }

            foreach ($command->lines as $line) {
                $product = $products[$line->productId];
                foreach ($this->expand($type, $command, $line, $product, $run) as $posting) {
                    $this->postLine($run, $product, $posting);
                }
            }

            return $this->finish($run);
        }));
    }

    private function replay(string $type, string $key): ?StockMovement
    {
        $existing = StockMovement::where('idempotency_key', $key)->first();
        if ($existing && $existing->movement_type !== $type) {
            throw new InvalidArgumentException("Idempotency key {$key} was already used for a {$existing->movement_type} movement.");
        }

        return $existing?->load('lines');
    }

    private function createHeader(string $type, array $attributes): StockMovement
    {
        $movement = StockMovement::create($attributes + [
            'movement_type' => $type,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        // Internal ledger reference; commercial documents keep their own series numbers.
        $movement->movement_no = sprintf('SM-%08d', $movement->id);
        $movement->save();

        return $movement;
    }

    private function finish(PostingRun $run): StockMovement
    {
        if ($run->warnings !== []) {
            $run->movement->warnings_json = $run->warnings;
            $run->movement->save();
        }

        return $run->movement->load('lines');
    }

    /** @return iterable<array<string, mixed>> postings in execution order */
    private function expand(string $type, StockMovementCommand $command, StockLine $line, Product $product, PostingRun $run): iterable
    {
        $qty = $this->uom->toBase($product, $line->qty, $line->uomId);
        // Openings mirror legacy projections, which may already be negative.
        $signed = $type === 'adjustment' || $type === 'opening';
        if (abs($qty) <= self::EPSILON || (!$signed && $qty < 0)) {
            throw new InvalidArgumentException("Stock quantity for product {$product->id} must be ".($signed ? 'nonzero.' : 'positive.'));
        }
        $direction = $type === 'issue' || ($signed && $qty < 0) ? -1 : 1;
        $qty = abs($qty);
        $outbound = $type === 'transfer' || $direction < 0;
        // Entered cost is per entered unit; the ledger stores cost per base unit.
        $unitCost = $line->unitCost === null ? null : round($line->unitCost * abs($line->qty) / $qty, 6);
        $warehouseId = $line->warehouseId ?? $command->warehouseId;

        $this->assertVariant($product, $line->variantId, $type, $run);
        $batchId = $this->resolveBatch($type, $product, $line, $outbound, $run);

        foreach ($this->identityChunks($type, $product, $line, $qty, $outbound, $batchId, $run) as [$chunk, $identity]) {
            $uomQty = $line->uomId === null ? null : abs($line->qty) * $chunk / $qty;
            $shared = [
                'variant_id' => $line->variantId,
                'batch_id' => $batchId,
                'identity' => $identity,
                'uom_id' => $line->uomId,
                'attributes' => $line->attributes,
            ];
            if ($type === 'transfer') {
                $cost = $this->averageCost($run, $product);
                yield ['warehouse_id' => $command->warehouseId, 'qty' => -$chunk, 'uom_qty' => $uomQty === null ? null : -$uomQty, 'unit_cost' => $cost] + $shared;
                yield ['warehouse_id' => $command->toWarehouseId, 'qty' => $chunk, 'uom_qty' => $uomQty, 'unit_cost' => $cost] + $shared;
            } else {
                yield [
                    'warehouse_id' => $warehouseId,
                    'qty' => $direction * $chunk,
                    'uom_qty' => $uomQty === null ? null : $direction * $uomQty,
                    'unit_cost' => $direction > 0 ? $unitCost : null,
                ] + $shared;
            }
        }
    }

    private function postLine(PostingRun $run, Product $product, array $posting): void
    {
        $qty = round((float) $posting['qty'], 4);
        $cost = $posting['unit_cost'] ?? $this->averageCost($run, $product);
        $run->valuation[$product->id] ??= $this->ledgerBalance($product->id);

        if ($posting['identity'] !== null) {
            try {
                $this->moveIdentity($run, $posting['identity'], (int) $posting['warehouse_id'], $qty);
            } catch (StockPolicyException $error) {
                // A shadow line still records the legacy quantity; identity conflicts are left for reconciliation.
                if (!$run->shadow) {
                    throw $error;
                }
                $run->warnings[] = $error->getMessage();
                $posting['identity'] = null;
            }
        }
        if ($run->updateProjections) {
            $this->applyProjections($run, $product, $posting, $qty);
        }
        $value = round($qty * $cost, 4);
        StockMovementLine::create([
            'stock_movement_id' => $run->movement->id,
            'company_id' => $run->companyId,
            'line_no' => ++$run->lineNo,
            'product_id' => $product->id,
            'variant_id' => $posting['variant_id'],
            'warehouse_id' => $posting['warehouse_id'],
            'uom_id' => $posting['uom_id'],
            'uom_qty' => $posting['uom_qty'] === null ? null : round($posting['uom_qty'], 4),
            'qty_base' => $qty,
            'unit_cost' => $cost,
            'value' => $value,
            'batch_id' => $posting['batch_id'],
            'stock_identity_id' => $posting['identity']?->id,
            'attributes_json' => $posting['attributes'] ?: null,
        ]);
        $run->valuation[$product->id]['qty'] += $qty;
        $run->valuation[$product->id]['value'] += $value;
    }

    /** Weighted average of the product's ledger value, or the product master cost when there is no positive balance. */
    private function averageCost(PostingRun $run, Product $product): float
    {
        if ($this->policy->valuation($run->companyId) === 'standard') {
            return round((float) $product->cost, 6);
        }
        $balance = $run->valuation[$product->id] ??= $this->ledgerBalance($product->id);

        return $balance['qty'] > self::EPSILON && $balance['value'] > 0
            ? round($balance['value'] / $balance['qty'], 6)
            : round((float) $product->cost, 6);
    }

    private function ledgerBalance(int $productId): array
    {
        $row = DB::table('stock_movement_lines')->where('product_id', $productId)
            ->selectRaw('COALESCE(SUM(qty_base), 0) AS qty, COALESCE(SUM(value), 0) AS value')->first();

        return ['qty' => (float) $row->qty, 'value' => (float) $row->value];
    }

    private function applyProjections(PostingRun $run, Product $product, array $posting, float $qty): void
    {
        $warehouseId = (int) $posting['warehouse_id'];
        $row = $this->warehouseRow($run->companyId, $product->id, $warehouseId, $posting['variant_id'], $posting['batch_id']);
        $after = (float) $row->qty + $qty;
        if ($qty < 0 && $after < -self::EPSILON) {
            $message = sprintf('Insufficient stock for product %d in warehouse %d: %s available, %s requested.',
                $product->id, $warehouseId, round((float) $row->qty, 4) + 0, -$qty);
            match ($this->policy->negativeStock($run->companyId, $product)) {
                'block' => throw new StockPolicyException($message),
                'warn' => $run->warnings[] = $message,
                default => null,
            };
        }
        $row->qty = $after;
        if ($posting['identity']?->identity_type === StockIdentity::SERIAL && $this->hasColumn('product_warehouse', 'imei_number')) {
            // Legacy screens read in-stock serials from the comma-separated imei_number projection.
            $serials = StockLine::parseSerials((string) $row->imei_number);
            $serials = $qty > 0 ? [...array_diff($serials, [$posting['identity']->identity_no]), $posting['identity']->identity_no]
                : array_diff($serials, [$posting['identity']->identity_no]);
            $row->imei_number = $serials === [] ? null : implode(',', $serials);
        }
        $row->save();

        DB::table('products')->where('id', $product->id)->increment('qty', $qty);
        if ($posting['variant_id'] !== null) {
            DB::table('product_variants')->where('product_id', $product->id)
                ->where('variant_id', $posting['variant_id'])->increment('qty', $qty);
        }
        if ($posting['batch_id'] !== null) {
            DB::table('product_batches')->where('id', $posting['batch_id'])->increment('qty', $qty);
        }
    }

    private function warehouseRow(?int $companyId, int $productId, int $warehouseId, ?int $variantId, ?int $batchId): Product_Warehouse
    {
        // Foreign or duplicate rows must stay visible here: they are exactly what this check refuses to touch.
        $query = Product_Warehouse::withoutGlobalScope('request_company')->where('product_id', $productId)->where('warehouse_id', $warehouseId);
        foreach (['variant_id' => $variantId, 'product_batch_id' => $batchId] as $column => $value) {
            $value === null
                ? $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, 0))
                : $query->where($column, $value);
        }
        // Ambiguous or foreign projection rows must be reconciled before any posting touches them.
        $builder = $query->getQuery();
        if ($builder instanceof \App\Support\Database\CompanyScopedBuilder) {
            $builder->withoutCompanyScope();
        }
        $rows = $query->orderBy('id')->lockForUpdate()->get();
        $foreign = $companyId !== null && $this->hasColumn('product_warehouse', 'company_id')
            && $rows->contains(fn ($row) => (int) $row->company_id !== $companyId);
        if ($rows->count() > 1 || $foreign) {
            throw new StockPolicyException("Stock ownership or identity of product {$productId} in warehouse {$warehouseId} requires reconciliation.");
        }
        if ($row = $rows->first()) {
            return $row;
        }
        $row = (new Product_Warehouse)->forceFill([
            'product_id' => $productId, 'warehouse_id' => $warehouseId,
            'variant_id' => $variantId, 'product_batch_id' => $batchId, 'qty' => 0,
        ]);
        if ($this->hasColumn('product_warehouse', 'company_id')) {
            $row->company_id = $companyId;
        }

        return $row;
    }

    private function assertVariant(Product $product, ?int $variantId, string $type, PostingRun $run): void
    {
        if ($variantId === null) {
            if ($product->is_variant && $type !== 'opening') {
                $this->violate($run, "Product {$product->id} has variants; specify the variant.");
            }

            return;
        }
        if (!DB::table('product_variants')->where('product_id', $product->id)->where('variant_id', $variantId)->exists()) {
            throw new InvalidArgumentException("Variant {$variantId} does not belong to product {$product->id}.");
        }
    }

    private function resolveBatch(string $type, Product $product, StockLine $line, bool $outbound, PostingRun $run): ?int
    {
        if ($line->batchId !== null) {
            $batch = DB::table('product_batches')->where('id', $line->batchId)->first();
        } elseif ($line->batch !== null) {
            $batchNo = trim((string) ($line->batch['batch_no'] ?? ''));
            if ($batchNo === '') {
                throw new InvalidArgumentException("A batch for product {$product->id} needs a batch number.");
            }
            $batch = DB::table('product_batches')->where('product_id', $product->id)->where('batch_no', $batchNo)->first();
            if (!$batch && !$outbound) {
                $batch = $this->createBatch($product, $batchNo, $line->batch, $run);
            }
            if (!$batch) {
                throw new StockPolicyException("Lot / batch \"{$batchNo}\" of {$product->name} is not in stock. Pick an existing lot, or leave the lot blank.");
            }
        } else {
            if ($product->is_batch && $type !== 'opening') {
                $this->violate($run, "Product {$product->id} is batch-tracked; specify the batch.");
            }

            return null;
        }
        if (!$batch || (int) $batch->product_id !== $product->id) {
            throw new InvalidArgumentException("Batch {$line->batchId} does not belong to product {$product->id}.");
        }
        $date = $run->movement->movement_date->toDateString();
        if ($type === 'issue' && $run->movement->movement_type !== 'expiry_writeoff' && !in_array($run->purpose, ['disposal', 'purchase_return'], true) && $batch->expired_date !== null && substr((string) $batch->expired_date, 0, 10) < $date) {
            $message = "Batch {$batch->batch_no} of product {$product->id} expired on ".substr((string) $batch->expired_date, 0, 10).'.';
            match ($run->shadow ? 'warn' : $this->policy->expiredBatch($run->companyId)) {
                'block' => throw new StockPolicyException($message),
                'warn' => $run->warnings[] = $message,
                default => null,
            };
        }

        return (int) $batch->id;
    }

    private function createBatch(Product $product, string $batchNo, array $data, PostingRun $run): object
    {
        // Expiry is mandatory where batch/expiry control is on (FMCG); a textile lot or dyed batch has no expiry.
        $expiryRequired = !$this->nullableExpiry() || ($run->companyId && app(\App\Services\Platform\CapabilityService::class)->enabled('inventory.batch_expiry', $run->companyId));
        if ((!empty($data['expired_date']) && strtotime($data['expired_date']) === false) || ($expiryRequired && empty($data['expired_date']))) {
            throw new InvalidArgumentException("New batch {$batchNo} needs an expiry date.");
        }
        $id = DB::table('product_batches')->insertGetId([
            'product_id' => $product->id,
            'batch_no' => $batchNo,
            'expired_date' => empty($data['expired_date']) ? null : Carbon::parse($data['expired_date'])->toDateString(),
            'mfg_date' => empty($data['mfg_date']) ? null : Carbon::parse($data['mfg_date'])->toDateString(),
            'mrp' => isset($data['mrp']) ? (float) $data['mrp'] : null,
            'company_id' => $run->companyId,
            'qty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('product_batches')->where('id', $id)->first();
    }

    private ?bool $nullableExpiry = null;

    private function nullableExpiry(): bool
    {
        return $this->nullableExpiry ??= !Schema::getConnection()->getDoctrineColumn('product_batches', 'expired_date')->getNotnull();
    }

    /** @return list<array{0: float, 1: ?StockIdentity}> */
    private function identityChunks(string $type, Product $product, StockLine $line, float $qty, bool $outbound, ?int $batchId, PostingRun $run): array
    {
        if ($line->serials !== []) {
            $serials = array_values(array_unique($line->serials));
            if (count($serials) !== count($line->serials)) {
                throw new StockPolicyException("Duplicate serial numbers for product {$product->id}.");
            }
            if (abs($qty - count($serials)) > self::EPSILON) {
                throw new StockPolicyException("Product {$product->id} needs exactly {$qty} serial numbers; ".count($serials).' given.');
            }

            return array_map(function (string $serial) use ($product, $outbound, $batchId, $run) {
                $identity = StockIdentity::where('product_id', $product->id)->where('identity_type', StockIdentity::SERIAL)
                    ->where('identity_no', $serial)->lockForUpdate()->first();
                if (!$identity && $outbound) {
                    $this->violate($run, "Serial {$serial} of product {$product->id} is not in stock.");

                    return [1.0, null];
                }

                return [1.0, $identity ?? StockIdentity::create([
                    'company_id' => $run->companyId, 'product_id' => $product->id,
                    'identity_type' => StockIdentity::SERIAL, 'identity_no' => $serial,
                    'batch_id' => $batchId, 'status' => 'new',
                ])];
            }, $serials);
        }
        if ($line->identityId !== null) {
            $identity = StockIdentity::where('product_id', $product->id)->lockForUpdate()->find($line->identityId);
            if (!$identity) {
                throw new InvalidArgumentException("Stock identity {$line->identityId} does not belong to product {$product->id}.");
            }
            $whole = $identity->identity_type === StockIdentity::SERIAL ? 1.0 : $this->identityBalance($identity->id);
            if (($type === 'transfer' || $identity->identity_type === StockIdentity::SERIAL) && abs($qty - $whole) > self::EPSILON) {
                throw new StockPolicyException("Stock identity {$identity->identity_no} must be moved whole ({$whole}).");
            }

            return [[$qty, $identity]];
        }
        if ($line->dimensions !== null) {
            if ($outbound) {
                throw new InvalidArgumentException('Dimensions describe a new piece on receipt; move existing pieces by stock_identity_id.');
            }

            return [[$qty, $this->createPiece($product, $line->dimensions, $batchId, $run)]];
        }
        if ($product->is_imei && $type !== 'opening') {
            $this->violate($run, "Product {$product->id} is serial-tracked; provide its serial numbers.");
        }

        return [[$qty, null]];
    }

    private function createPiece(Product $product, array $dimensions, ?int $batchId, PostingRun $run): StockIdentity
    {
        // Before the dimensional migration, legacy quantities have no normalized unit contract.
        // Never reinterpret that geometry as CBM/CFT. Migrated receipts require explicit units.
        $calculated = $this->hasColumn('stock_dimensions', 'formula_version')
            ? app(DimensionCalculationService::class)->calculate($dimensions) : [];
        $dimensions = $calculated + $dimensions;
        $number = trim((string) ($dimensions['identity_no'] ?? ''))
            ?: sprintf('%s-%d', $run->movement->movement_no, $run->lineNo + 1);
        if (StockIdentity::where('product_id', $product->id)->where('identity_type', StockIdentity::PIECE)->where('identity_no', $number)->exists()) {
            throw new StockPolicyException("Piece {$number} of product {$product->id} already exists.");
        }
        $identity = StockIdentity::create([
            'company_id' => $run->companyId, 'product_id' => $product->id,
            'identity_type' => StockIdentity::PIECE, 'identity_no' => $number,
            'batch_id' => $batchId, 'status' => 'new',
        ]);
        $size = fn ($key) => isset($dimensions[$key]) && is_numeric($dimensions[$key]) ? (float) $dimensions[$key] : null;
        $pieces = max(1, (int) ($dimensions['pieces'] ?? 1));
        [$length, $width, $thickness] = [$size('length'), $size('width'), $size('thickness')];
        $values = [
            'stock_identity_id' => $identity->id,
            'length' => $length, 'width' => $width, 'thickness' => $thickness,
            'dimension_uom' => $dimensions['dimension_uom'] ?? null,
            'pieces' => $pieces,
            'computed_volume' => $calculated['computed_volume'] ?? ($length !== null && $width !== null && $thickness !== null
                ? round($length * $width * $thickness * $pieces, 6) : null),
            'volume_uom' => $calculated['normalized_volume_uom'] ?? $dimensions['volume_uom'] ?? null,
            'grade' => $dimensions['grade'] ?? null,
        ];
        foreach (['computed_cbm', 'computed_cft', 'formula_version'] as $column) {
            if ($this->hasColumn('stock_dimensions', $column)) $values[$column] = $dimensions[$column];
        }
        StockDimension::create($values);

        return $identity;
    }

    /** One location per identity: outbound requires it in stock here; inbound requires it not in stock elsewhere. */
    private function moveIdentity(PostingRun $run, StockIdentity $identity, int $warehouseId, float $qty): void
    {
        $identity = StockIdentity::lockForUpdate()->findOrFail($identity->id);
        $label = ucfirst($identity->identity_type).' '.$identity->identity_no;
        $inStockHere = $identity->status === StockIdentity::IN_STOCK && (int) $identity->warehouse_id === $warehouseId;
        if ($qty < 0) {
            if (Schema::hasTable('project_serial_reservations')) {
                $reservation = DB::table('project_serial_reservations')->where('stock_identity_id', $identity->id)
                    ->whereNotNull('active_key')->lockForUpdate()->first();
                if ($reservation) {
                    $ownDispatch = $run->movement->source_type === 'project_serial_reservations'
                        && (int) $run->movement->source_id === (int) $reservation->id;
                    $ownSale = $run->movement->source_type === 'sale'
                        && in_array($reservation->status, ['dispatched', 'installed', 'commissioned'], true)
                        && DB::table('project_document_links')->where('company_id', $run->companyId)->where('project_id', $reservation->project_id)
                            ->where('source_type', 'sale')->where('source_id', $run->movement->source_id)->exists();
                    $reverse = $run->movement->movement_type === 'reversal';
                    if (!$ownDispatch && !$ownSale && !$reverse) {
                        throw new StockPolicyException('Serial is reserved for a project; use its dispatch or linked sale.');
                    }
                }
            }
            if (!$inStockHere) {
                throw new StockPolicyException("{$label} is not in stock in warehouse {$warehouseId}.");
            }
            $remaining = $identity->identity_type === StockIdentity::SERIAL ? 1.0 : $this->identityBalance($identity->id);
            if (-$qty > $remaining + self::EPSILON) {
                throw new StockPolicyException("{$label} has only {$remaining} remaining.");
            }
            if ($remaining + $qty <= self::EPSILON) {
                $identity->status = $run->outStatus;
                $identity->warehouse_id = null;
            }
        } else {
            if ($identity->status === StockIdentity::IN_STOCK && ($identity->identity_type === StockIdentity::SERIAL || !$inStockHere)) {
                throw new StockPolicyException("{$label} is already in stock in warehouse {$identity->warehouse_id}.");
            }
            $identity->status = StockIdentity::IN_STOCK;
            $identity->warehouse_id = $warehouseId;
            if ($run->purpose === 'customer_return' && $this->hasColumn('stock_identities', 'warranty_state')) {
                $identity->warranty_state = 'returned';
                $identity->return_inspected_at = now();
            }
        }
        $identity->last_movement_id = $run->movement->id;
        $identity->save();
    }

    /** Identity rules block applied postings; a shadow posting records the legacy quantity with a warning. */
    private function violate(PostingRun $run, string $message): void
    {
        if (!$run->shadow) {
            throw new StockPolicyException($message);
        }
        $run->warnings[] = $message;
    }

    private function identityBalance(int $identityId): float
    {
        return (float) DB::table('stock_movement_lines')->where('stock_identity_id', $identityId)->sum('qty_base');
    }

    /** @return array<int, Product> locked products keyed by ID */
    private function lockProducts(array $ids, ?int $companyId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== count($ids)) {
            throw new InvalidArgumentException('Unknown product in stock movement.');
        }
        if ($companyId !== null && $this->hasColumn('products', 'company_id')
            && $products->contains(fn ($product) => $product->company_id !== null && (int) $product->company_id !== $companyId)) {
            throw new InvalidArgumentException('A product does not belong to the stock movement company.');
        }

        return $products->all();
    }

    private function companyFor(?CompanyContext $context, array $warehouseIds): ?int
    {
        $warehouses = DB::table('warehouses')->whereIn('id', $warehouseIds)->get();
        if ($warehouses->count() !== count($warehouseIds)) {
            throw new InvalidArgumentException('Unknown warehouse in stock movement.');
        }
        $owners = $this->hasColumn('warehouses', 'company_id')
            ? $warehouses->pluck('company_id')->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values()
            : collect();
        if ($context !== null) {
            if ($owners->contains(fn ($id) => $id !== $context->companyId)) {
                throw new InvalidArgumentException('A warehouse does not belong to the active company.');
            }

            return $context->companyId;
        }
        if ($owners->count() > 1) {
            throw new InvalidArgumentException('Stock movement warehouses belong to different companies.');
        }

        return $owners->first();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->columns[$table.'.'.$column] ??= Schema::hasColumn($table, $column);
    }
}
