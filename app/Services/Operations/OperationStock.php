<?php

namespace App\Services\Operations;

use App\Models\Product;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Inventory\UomConversionService;
use App\Services\Platform\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Identity-aware command construction, without another inventory writer. */
class OperationStock
{
    public function line(array $data, CompanyContext $context): StockLine
    {
        // A blank "Lot / batch no." box on a form means no lot, not an invalid one.
        if (isset($data['batch']) && trim((string) ($data['batch']['batch_no'] ?? '')) === '') unset($data['batch']);
        validator($data, ['product_id' => 'required|integer|min:1', 'qty' => 'required|numeric|gt:0|max:999999999',
            'uom_id' => 'nullable|integer|min:1', 'stock_identity_id' => 'nullable|integer|min:1',
            'serials' => 'nullable|array', 'serials.*' => 'string|max:191', 'batch' => 'nullable|array',
            'batch.batch_no' => 'required_with:batch|string|max:100', 'batch.expired_date' => 'nullable|date_format:Y-m-d',
            'batch.mfg_date' => 'nullable|date_format:Y-m-d', 'batch.mrp' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|array', 'attributes' => 'nullable|array'])->validate();
        $product = app(CompanyWriteGuard::class)->product($data, $context, 'uom_id');
        if ($product->type !== 'standard' || !$product->is_active) {
            throw ValidationException::withMessages(['product_id' => 'Operations require an active stock product.']);
        }
        app(UomConversionService::class)->toBase($product, (float) $data['qty'], (int) $data['uom_id']);
        return StockLine::fromArray($data);
    }

    public function command(Model $source, string $date, array $lines, int $warehouse, CompanyContext $context,
        int $actor, string $part, ?int $destination = null, string $purpose = 'ordinary'): StockMovementCommand
    {
        return new StockMovementCommand(date: $date, lines: $lines, warehouseId: $warehouse, toWarehouseId: $destination,
            sourceType: $source->getTable(), sourceId: $source->id, sourceNo: $source->reference_no,
            idempotencyKey: $source->getTable().':'.$source->id.':'.$part, userId: $actor, context: $context, purpose: $purpose);
    }

    public function value($movement): int
    {
        return $movement->lines->sum(fn ($line) => \App\Support\LedgerAmount::units($line->value));
    }
}
