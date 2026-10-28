<?php

namespace App\Services\JobWork;

use App\Models\Operations\JobWorkDispatch;
use App\Models\Operations\JobWorkOrder;
use App\Models\Operations\JobWorkReceipt;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Accounting\SemanticAccountResolver;
use App\Services\Commercial\PurchaseApplicationService;
use App\Services\Commercial\PurchaseCommand;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\UomConversionService;
use App\Services\Operations\OperationPosting;
use App\Services\Operations\OperationStock;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\DocumentNumberService;
use App\Support\LedgerAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Own material remains inventory at a dedicated location until reconciled receipt or loss. */
class JobWorkService
{
    public function configureProcess(array $data, CompanyContext $context, int $actor): int
    {
        app(OperationPosting::class)->authorize('operations.job_work', 'job_work.manage', $context, $actor);
        $data = validator($data, ['code' => 'required|string|max:60|regex:/^[A-Z0-9_-]+$/D', 'name' => 'required|string|max:100',
            'max_loss_percent' => 'required|numeric|min:0|max:100', 'conversion' => 'required|boolean',
            'quantity_basis' => 'sometimes|in:base_qty,volume_cbm', 'output_product_id' => 'nullable|integer|min:1',
            'service_product_id' => 'nullable|integer|min:1', 'service_rate' => 'nullable|numeric|min:0|max:99999999'])->validate();
        foreach (['output_product_id' => 'standard', 'service_product_id' => 'service'] as $field => $type) {
            if (empty($data[$field])) { $data[$field] = null; continue; }
            $product = app(CompanyWriteGuard::class)->owned(\App\Models\Product::class, $data[$field], $context, $field);
            if ($product->type !== $type) throw ValidationException::withMessages([$field => $type === 'service' ? 'Choose a service item for the processing charge.' : 'Choose a stock item as the default output.']);
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('process_types', 'output_product_id')) unset($data['output_product_id'], $data['service_product_id'], $data['service_rate']);
        return DB::transaction(function () use ($data, $context, $actor) {
            app(CompanyWriteGuard::class)->begin($context, null);
            $existing = DB::table('process_types')->where('company_id', $context->companyId)->where('code', $data['code'])->first();
            if ($existing && DB::table('job_work_orders')->where('process_type_id', $existing->id)->exists()) {
                // Loss limit, conversion and yield basis are fixed once used; name and route defaults (items, rate) only pre-fill forms.
                $changed = (float) $existing->max_loss_percent !== (float) $data['max_loss_percent'] || (bool) $existing->conversion !== (bool) $data['conversion']
                    || ($existing->quantity_basis ?? 'base_qty') !== ($data['quantity_basis'] ?? $existing->quantity_basis ?? 'base_qty');
                if ($changed) throw ValidationException::withMessages(['process' => 'Used process policy is immutable; create a new process code to change its loss limit, conversion or yield basis.']);
                $data = array_intersect_key($data, array_flip(['code', 'name', 'output_product_id', 'service_product_id', 'service_rate']));
            }
            // Explicit configuration activates the conservative, initially disabled Bleaching preset.
            if ($data['code'] === 'BLEACHING') $data['is_active'] = true;
            DB::table('process_types')->updateOrInsert(['company_id' => $context->companyId, 'code' => $data['code']],
                $data + ['created_at' => now(), 'updated_at' => now()]);
            $id = (int) DB::table('process_types')->where('company_id', $context->companyId)->where('code', $data['code'])->value('id');
            app(OperationPosting::class)->audit('process_configure', $id, $context, $actor, $data);
            return $id;
        }, 3);
    }

    public function order(array $data, string $key, CompanyContext $context, int $actor): JobWorkOrder
    {
        return app(OperationPosting::class)->run('job_work_order', JobWorkOrder::class, 'operations.job_work', 'job_work.manage',
            $data, $key, $context, $actor, function ($data, $date) use ($context, $actor) {
                validator($data, ['job_worker_party_id' => 'required|integer|min:1', 'process_type_id' => 'required|integer|min:1',
                    'expected_return_date' => 'nullable|date_format:Y-m-d|after_or_equal:business_date', 'notes' => 'nullable|string|max:5000'])->validate();
                $supplier = app(CompanyWriteGuard::class)->owned(Supplier::class, $data['job_worker_party_id'], $context, 'job_worker_party_id');
                if (!$supplier->is_active) throw ValidationException::withMessages(['job_worker_party_id' => 'Select an active job worker.']);
                $process = DB::table('process_types')->where('company_id', $context->companyId)->where('is_active', true)->find($data['process_type_id']);
                if (!$process) throw ValidationException::withMessages(['process_type_id' => 'Select an active company process.']);
                DB::table('job_worker_roles')->updateOrInsert(['company_id' => $context->companyId, 'supplier_id' => $supplier->id], ['updated_at' => now(), 'created_at' => now()]);
                $numbers = app(DocumentNumberService::class);
                $number = $numbers->reserve('job_work_order', $context, $date, $actor);
                $location = Warehouse::forceCreate(['name' => 'External '.$number->formatted_number,
                    'address' => $supplier->address ?? 'Job worker location', 'is_active' => true,
                    'company_id' => $context->companyId, 'branch_id' => $context->branchId]);
                $order = JobWorkOrder::create(app(OperationPosting::class)->header($context, $actor) + [
                    'job_worker_party_id' => $supplier->id, 'process_type_id' => $process->id,
                    'external_warehouse_id' => $location->id, 'reference_no' => $number->formatted_number,
                    'business_date' => $date, 'expected_return_date' => $data['expected_return_date'] ?? null,
                    'notes' => $data['notes'] ?? null, 'details_json' => ['process' => (array) $process], 'posted_at' => now(),
                ]);
                DB::table('warehouses')->where('id', $location->id)->update(['external_job_order_id' => $order->id]);
                $numbers->assign($number, $order);
                return $order;
            });
    }

    /** Material DC: first send of material for the order's process. */
    public function dispatch(int $orderId, array $data, string $key, CompanyContext $context, int $actor): JobWorkDispatch
    {
        unset($data['rework_of_receipt_id']);
        return $this->send($orderId, null, $data, $key, $context, $actor);
    }

    /** GRN "Return / Send for rework": a new Material DC under the same order that references the GRN it reworks. */
    public function rework(int $receiptId, array $data, string $key, CompanyContext $context, int $actor): JobWorkDispatch
    {
        if (!self::supports('job_work_dispatches', 'rework_of_receipt_id')) throw ValidationException::withMessages(['receipt' => 'Run the job work rework migration first.']);
        $receipt = JobWorkReceipt::visibleIn($context)->findOrFail($receiptId);
        $orderId = (int) JobWorkDispatch::visibleIn($context)->findOrFail($receipt->dispatch_id)->job_work_order_id;
        return $this->send($orderId, $receiptId, $data, $key, $context, $actor);
    }

    private function send(int $orderId, ?int $reworkOf, array $data, string $key, CompanyContext $context, int $actor): JobWorkDispatch
    {
        $data['order_id'] = $orderId;
        if ($reworkOf) $data['rework_of_receipt_id'] = $reworkOf;
        return app(OperationPosting::class)->run('job_work_dispatch', JobWorkDispatch::class, 'operations.job_work', 'job_work.dispatch',
            $data, $key, $context, $actor, function ($data, $date) use ($orderId, $reworkOf, $context, $actor) {
                validator($data, ['warehouse_id' => 'required|integer|min:1', 'lines' => 'required|array|min:1|max:500',
                    'transport' => 'nullable|array', 'transport.vehicle_number' => 'nullable|string|max:30', 'transport.transporter_name' => 'nullable|string|max:100',
                    'transport.eway_bill_no' => 'nullable|string|max:30', 'transport.notes' => 'nullable|string|max:500'])->validate();
                $order = JobWorkOrder::visibleIn($context)->lockForUpdate()->findOrFail($orderId);
                if ($date < $order->business_date) throw ValidationException::withMessages(['business_date' => 'Dispatch cannot precede the order.']);
                if ($reworkOf) {
                    $source = JobWorkReceipt::visibleIn($context)->lockForUpdate()->findOrFail($reworkOf);
                    if ($source->reversed_at || ($source->receipt_kind ?? 'process') === 'material_return') {
                        throw ValidationException::withMessages(['receipt' => 'Send an active processed GRN for rework.']);
                    }
                    if ($date < $source->business_date) throw ValidationException::withMessages(['business_date' => 'Rework cannot precede the GRN.']);
                }
                $warehouse = app(CompanyWriteGuard::class)->warehouse($data['warehouse_id'], $context, $actor);
                if ($warehouse->external_job_order_id) throw ValidationException::withMessages(['warehouse_id' => 'Dispatch from an internal warehouse.']);
                $number = app(DocumentNumberService::class)->reserve('job_work_dispatch', $context, $date, $actor);
                $dispatch = JobWorkDispatch::create(app(OperationPosting::class)->header($context, $actor) + [
                    'job_work_order_id' => $orderId, 'warehouse_id' => $warehouse->id, 'reference_no' => $number->formatted_number, 'business_date' => $date,
                ] + (self::supports('job_work_dispatches', 'details_json')
                    ? ['details_json' => ['transport' => array_filter($data['transport'] ?? [], fn ($value) => $value !== null && $value !== '')]] : [])
                  + ($reworkOf ? ['purpose' => 'rework', 'rework_of_receipt_id' => $reworkOf] : []));
                $stock = app(OperationStock::class);
                $lines = array_map(fn ($line) => $stock->line(array_diff_key($line, array_flip(['unit_cost'])), $context), $data['lines']);
                $movement = app(InventoryMovementService::class)->transfer($stock->command($dispatch, $date, $lines, $warehouse->id,
                    $context, $actor, 'dispatch', $order->external_warehouse_id, $reworkOf ? 'rework' : 'ordinary'));
                // Persist actual base quantities, identities and values from the transfer, not product master costs.
                foreach ($movement->lines->where('warehouse_id', $order->external_warehouse_id)->values() as $i => $line) {
                    $details = ['product_id' => $line->product_id, 'variant_id' => $line->variant_id,
                        'product_batch_id' => $line->batch_id, 'stock_identity_id' => $line->stock_identity_id];
                    DB::table('job_work_dispatch_lines')->insert(app(OperationPosting::class)->lines('job_work_dispatch_lines', [['dispatch_id' => $dispatch->id, 'line_no' => $i + 1,
                        'product_id' => $line->product_id, 'qty_base' => $line->qty_base, 'value' => $line->value,
                        'stock_details_json' => json_encode($details, JSON_THROW_ON_ERROR)]], $context));
                }
                $dispatch->forceFill(['movement_id' => $movement->id, 'posted_at' => now()])->save();
                app(DocumentNumberService::class)->assign($number, $dispatch);
                return $dispatch;
            });
    }

    /** Job Work GRN: processed material back (accepted / rejected at receipt / loss). */
    public function receive(int $dispatchId, array $data, string $key, CompanyContext $context, int $actor): JobWorkReceipt
    {
        return $this->settle($dispatchId, $data, 'process', $key, $context, $actor);
    }

    /** Material DC "Return material": the job worker sends material back unprocessed, as the same item, with no loss. */
    public function returnMaterial(int $dispatchId, array $data, string $key, CompanyContext $context, int $actor): JobWorkReceipt
    {
        if (!self::supports('job_work_receipts', 'receipt_kind')) throw ValidationException::withMessages(['dispatch' => 'Run the job work rework migration first.']);
        validator($data, ['lines' => 'required|array|min:1|max:500', 'lines.*.dispatch_line_id' => 'required|integer|min:1|distinct',
            'lines.*.returned_qty' => 'required|numeric|min:0'])->validate();
        $data['lines'] = array_map(fn ($line) => ['dispatch_line_id' => $line['dispatch_line_id'], 'accepted_qty' => $line['returned_qty'],
            'rejected_qty' => 0, 'loss_qty' => 0], $data['lines']);
        unset($data['outputs'], $data['rejected_warehouse_id']);
        return $this->settle($dispatchId, $data, 'material_return', $key, $context, $actor);
    }

    private function settle(int $dispatchId, array $data, string $kind, string $key, CompanyContext $context, int $actor): JobWorkReceipt
    {
        $data['dispatch_id'] = $dispatchId;
        return app(OperationPosting::class)->run($kind === 'process' ? 'job_work_receipt' : 'job_work_material_return', JobWorkReceipt::class,
            'operations.job_work', 'job_work.receive', $data, $key, $context, $actor, function ($data, $date) use ($dispatchId, $kind, $context, $actor) {
                validator($data, ['warehouse_id' => 'required_without:direct_dispatch|nullable|integer|min:1', 'direct_dispatch' => 'sometimes|boolean',
                    'lines' => 'required|array|min:1|max:500',
                    'lines.*.dispatch_line_id' => 'required|integer|min:1|distinct', 'lines.*.accepted_qty' => 'required|numeric|min:0',
                    'lines.*.rejected_qty' => 'required|numeric|min:0', 'lines.*.loss_qty' => 'required|numeric|min:0',
                    'rejected_warehouse_id' => 'nullable|integer|min:1', 'outputs' => 'sometimes|array|min:1|max:500'])->validate();
                $dispatch = JobWorkDispatch::visibleIn($context)->lockForUpdate()->findOrFail($dispatchId);
                $order = JobWorkOrder::visibleIn($context)->lockForUpdate()->findOrFail($dispatch->job_work_order_id);
                if ($dispatch->reversed_at || $date < $dispatch->business_date) {
                    throw ValidationException::withMessages(['dispatch' => 'Receive an active dispatch on or after its date.']);
                }
                $guard = app(CompanyWriteGuard::class);
                // Sec 143(1)(a): processed goods may be supplied to the customer straight from the job worker's premises.
                // They stay in the job worker's location (still ours) until the invoice issues them from there.
                $direct = $kind === 'process' && !empty($data['direct_dispatch']);
                $warehouse = $guard->warehouse($direct ? $order->external_warehouse_id : $data['warehouse_id'], $context, $actor);
                if ($warehouse->external_job_order_id && !$direct) throw ValidationException::withMessages(['warehouse_id' => 'Receive into an internal warehouse.']);
                $policy = $order->details_json['process'];
                if ($direct && (!$policy['conversion'] || empty($data['outputs']))) {
                    throw ValidationException::withMessages(['direct_dispatch' => 'Direct dispatch to a customer needs processed output entered on the GRN.']);
                }
                // Unprocessed returns bring back the item that was sent. A rework GRN converts only when outputs are entered
                // (rejected grey re-dyed), otherwise the reworked item comes back as itself (dyed fabric re-finished).
                $rework = ($dispatch->purpose ?? 'process') === 'rework';
                $conversion = (bool) $policy['conversion'] && $kind === 'process' && (!$rework || !empty($data['outputs']));
                if (!$conversion && isset($data['outputs'])) throw ValidationException::withMessages(['outputs' => 'This receipt returns the dispatched material.']);
                $number = app(DocumentNumberService::class)->reserve('job_work_receipt', $context, $date, $actor);
                $receipt = JobWorkReceipt::create(app(OperationPosting::class)->header($context, $actor) + [
                    'dispatch_id' => $dispatchId, 'warehouse_id' => $warehouse->id, 'reference_no' => $number->formatted_number,
                    'business_date' => $date, 'details_json' => [],
                ] + (self::supports('job_work_receipts', 'receipt_kind') ? ['receipt_kind' => $kind] : []));
                $stock = app(OperationStock::class); $returns = []; $rejects = []; $losses = []; $consumes = []; $saved = [];
                $received = $lost = $settled = 0;
                foreach ($data['lines'] as $input) {
                    $line = DB::table('job_work_dispatch_lines')->where('dispatch_id', $dispatchId)->find($input['dispatch_line_id']);
                    if (!$line) throw ValidationException::withMessages(['dispatch_line_id' => 'Receipt line must belong to this dispatch.']);
                    $previous = DB::table('job_work_receipt_lines as l')->join('job_work_receipts as r', 'r.id', '=', 'l.receipt_id')
                        ->where('l.dispatch_line_id', $line->id)->whereNull('r.reversed_at')->sum(DB::raw('l.received_qty + l.loss_qty'));
                    $accept = (float) $input['accepted_qty']; $reject = (float) $input['rejected_qty']; $loss = (float) $input['loss_qty'];
                    $qty = round($accept + $reject + $loss, 4);
                    if ($qty === 0) continue;
                    if ($qty + $previous > (float) $line->qty_base + InventoryMovementService::EPSILON
                        || $loss / $qty * 100 > (float) $policy['max_loss_percent'] + 0.00005) {
                        throw ValidationException::withMessages(['lines' => 'Receipt exceeds remaining dispatch quantity or process loss threshold.']);
                    }
                    $details = json_decode($line->stock_details_json, true, 512, JSON_THROW_ON_ERROR);
                    $product = $guard->owned(\App\Models\Product::class, $line->product_id, $context, 'product_id');
                    $make = fn ($amount) => new StockLine(productId: $line->product_id, qty: $amount,
                        variantId: $details['variant_id'], batchId: $details['product_batch_id'], identityId: $details['stock_identity_id'],
                        uomId: $product->unit_id ? (int) $product->unit_id : null,
                        attributes: ['dispatch_line_id' => $line->id]);
                    if ($conversion && $accept > 0) $consumes[] = $make($accept);
                    if (!$conversion && $accept > 0) $returns[] = $make($accept);
                    if ($reject > 0) $rejects[] = $make($reject);
                    if ($loss > 0) $losses[] = $make($loss);
                    $settled += $qty; $received += $accept + $reject; $lost += $loss;
                    $saved[] = ['receipt_id' => $receipt->id, 'dispatch_line_id' => $line->id, 'received_qty' => $accept + $reject,
                        'accepted_qty' => $accept, 'rejected_qty' => $reject, 'loss_qty' => $loss];
                }
                if (!$saved) throw ValidationException::withMessages(['lines' => 'Enter at least one received or lost quantity.']);
                $material = null; $output = null; $lossMovement = null; $rejectedMovement = null;
                // Reduce an identity's external balance before relocating its surviving material.
                if ($consumes) $material = app(InventoryMovementService::class)->issue($stock->command($receipt, $date, $consumes,
                    $order->external_warehouse_id, $context, $actor, 'convert'));
                if ($losses) $lossMovement = app(InventoryMovementService::class)->issue($stock->command($receipt, $date, $losses,
                    $order->external_warehouse_id, $context, $actor, 'loss'));
                if ($returns) $material = app(InventoryMovementService::class)->transfer($stock->command($receipt, $date, $returns,
                    $order->external_warehouse_id, $context, $actor, 'return', $warehouse->id));
                if ($rejects) {
                    if (empty($data['rejected_warehouse_id'])) {
                        throw ValidationException::withMessages(['rejected_warehouse_id' => 'Choose a quarantine warehouse for the rejected quantity, or enter 0 rejected.']);
                    }
                    $quarantine = $guard->warehouse($data['rejected_warehouse_id'], $context, $actor);
                    if ($quarantine->id === $warehouse->id || $quarantine->external_job_order_id) {
                        throw ValidationException::withMessages(['rejected_warehouse_id' => 'Rejected material needs a separate internal quarantine warehouse.']);
                    }
                    $rejectedMovement = app(InventoryMovementService::class)->transfer($stock->command($receipt, $date, $rejects,
                        $order->external_warehouse_id, $context, $actor, 'rejected', $quarantine->id));
                }
                if ($conversion) {
                    // Allocate actual movement cost to outputs; subcontract service charges stay on a normal purchase.
                    $value = $material ? -$stock->value($material) : 0;
                    if ($consumes) $output = $this->conversionOutputs($receipt, $data['outputs'] ?? [], $value, $date, $context, $actor);
                    elseif (!empty($data['outputs'])) throw ValidationException::withMessages(['outputs' => 'Output requires accepted material.']);
                    if ($output && ($policy['quantity_basis'] ?? 'base_qty') === 'volume_cbm') {
                        $dimensions = app(\App\Services\Inventory\DimensionCalculationService::class);
                        $inputVolume = $dimensions->movementVolume($material);
                        $outputVolume = $dimensions->movementVolume($output);
                        if (abs($inputVolume - $outputVolume) > max(0.000001, $inputVolume * 0.005)) {
                            throw ValidationException::withMessages(['outputs' => 'Accepted material volume must reconcile conversion outputs within 0.5%; record loss separately.']);
                        }
                    }
                }
                $journal = null;
                $actualLoss = $lossMovement ? -$stock->value($lossMovement) : 0;
                if ($actualLoss) {
                    $accounts = app(SemanticAccountResolver::class);
                    $journal = app(AccountingPostingService::class)->postJournalEntry(['entry_date' => $date, 'reference_type' => 'job_work_receipt',
                        'reference_id' => $receipt->id, 'reference_no' => $receipt->reference_no, 'description' => 'Job work loss '.$receipt->reference_no,
                        'created_by' => $actor], [['chart_of_account_id' => $accounts->resolve('damage', $context)->id, 'debit' => LedgerAmount::decimal($actualLoss)],
                            ['chart_of_account_id' => $accounts->resolve('inventory', $context)->id, 'credit' => LedgerAmount::decimal($actualLoss)]], $context);
                }
                DB::table('job_work_receipt_lines')->insert(app(OperationPosting::class)->lines('job_work_receipt_lines', $saved, $context));
                $lot = fn ($batchId) => $batchId ? DB::table('product_batches')->where('id', $batchId)->value('batch_no') : null;
                $lots = ['input' => array_values(array_unique(array_filter(array_map(fn ($line) => $lot($line->batchId), [...$consumes, ...$returns, ...$losses])))),
                    'output' => array_values(array_unique(array_filter(($output ?? $material)?->lines->where('qty_base', '>', 0)->map(fn ($line) => $lot($line->batch_id))->all() ?? [])))];
                $receipt->forceFill(['material_movement_id' => $material?->id, 'output_movement_id' => $output?->id,
                    'loss_movement_id' => $lossMovement?->id, 'journal_entry_id' => $journal?->id,
                    'details_json' => ['received_qty' => $received, 'loss_qty' => $lost, 'settled_qty' => $settled,
                        'loss_percent' => round($lost / $settled * 100, 4), 'rejected_movement_id' => $rejectedMovement?->id,
                        'rejected_warehouse_id' => $rejectedMovement ? (int) $data['rejected_warehouse_id'] : null,
                        'process' => $policy, 'lots' => $lots] + ($direct ? ['direct_dispatch' => true] : []), 'posted_at' => now()])->save();
                app(DocumentNumberService::class)->assign($number, $receipt);
                return $receipt;
            });
    }

    private function conversionOutputs(JobWorkReceipt $receipt, array $outputs, int $value, string $date, CompanyContext $context, int $actor)
    {
        validator(['outputs' => $outputs], ['outputs' => 'required|array|min:1|max:500', 'outputs.*.cost_weight' => 'required|numeric|gt:0'])->validate();
        $weight = array_sum(array_column($outputs, 'cost_weight')); $allocated = 0; $lines = [];
        foreach ($outputs as $i => $line) {
            validator($line, ['qty' => 'required|numeric|gt:0'])->validate();
            $part = $i === count($outputs) - 1 ? $value - $allocated : (int) round($value * $line['cost_weight'] / $weight);
            $allocated += $part; $line['unit_cost'] = $part / 10000 / $line['qty'];
            $lines[] = app(OperationStock::class)->line($line, $context);
        }
        $movement = app(InventoryMovementService::class)->receive(app(OperationStock::class)->command($receipt, $date, $lines,
            $receipt->warehouse_id, $context, $actor, 'output'));
        if (app(OperationStock::class)->value($movement) !== $value) {
            throw ValidationException::withMessages(['outputs' => 'Output cost allocation exceeds stock precision.']);
        }
        return $movement;
    }

    public function serviceBill(int $receiptId, array $data, string $key, CompanyContext $context, int $actor): JobWorkReceipt
    {
        $data['receipt_id'] = $receiptId;
        return app(OperationPosting::class)->run('job_work_bill', JobWorkReceipt::class, 'operations.job_work', 'job_work.manage',
            $data, $key, $context, $actor, function ($data, $date) use ($receiptId, $context, $actor, $key) {
                $receipt = JobWorkReceipt::visibleIn($context)->lockForUpdate()->findOrFail($receiptId);
                $dispatch = JobWorkDispatch::visibleIn($context)->findOrFail($receipt->dispatch_id);
                $order = JobWorkOrder::visibleIn($context)->findOrFail($dispatch->job_work_order_id);
                if ($receipt->reversed_at || $receipt->service_purchase_id || $date < $receipt->business_date) {
                    throw ValidationException::withMessages(['receipt' => 'Bill an active, unbilled receipt on or after its date.']);
                }
                if (($receipt->receipt_kind ?? 'process') === 'material_return') {
                    throw ValidationException::withMessages(['receipt' => 'Material returned unprocessed carries no processing charge.']);
                }
                validator($data, ['items' => 'required|array|min:1|max:500', 'charge_mode' => 'sometimes|in:capitalise,expense',
                    'their_names' => 'sometimes|array|max:50', 'their_names.*' => 'nullable|string|max:150',
                    'supplier_invoice_no' => 'nullable|string|max:60', 'supplier_invoice_date' => 'nullable|date_format:Y-m-d',
                    'due_date' => 'nullable|date_format:Y-m-d', 'remarks' => 'nullable|string|max:500'])->validate();
                foreach ($data['items'] as $line) {
                    $product = app(CompanyWriteGuard::class)->owned(\App\Models\Product::class, $line['product_id'] ?? null, $context, 'product_id');
                    if ($product->type !== 'service') throw ValidationException::withMessages(['items' => 'Job work bills accept service items only.']);
                }
                $remarks = trim((string) ($data['remarks'] ?? ''));
                // The job worker may bill the material under their own name: keep it as their alternate name and on the bill.
                $theirNames = [];
                $sent = DB::table('job_work_receipt_lines as l')->join('job_work_dispatch_lines as d', 'd.id', '=', 'l.dispatch_line_id')
                    ->join('products as p', 'p.id', '=', 'd.product_id')->where('l.receipt_id', $receipt->id)->pluck('p.name', 'd.product_id');
                foreach ($data['their_names'] ?? [] as $productId => $name) {
                    $name = trim((string) $name);
                    if ($name === '') continue;
                    if (!$sent->has($productId)) throw ValidationException::withMessages(['their_names' => 'Name only the material on this receipt.']);
                    $theirNames[] = $name.' for '.$sent[$productId];
                    if (\Illuminate\Support\Facades\Schema::hasTable('product_aliases')
                        && app(\App\Services\Commercial\CommercialPermission::class)->allows('products-edit', $context, $actor)) {
                        app(\App\Services\Commercial\ProductAliasService::class)->create(['product_id' => (int) $productId, 'party_type' => 'supplier',
                            'party_id' => $order->job_worker_party_id, 'name' => $name], $context, $actor);
                    }
                }
                $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand([
                    'supplier_id' => $order->job_worker_party_id, 'warehouse_id' => $receipt->warehouse_id,
                    'business_date' => $date, 'status' => 1, 'items' => $data['items'],
                    'note' => 'Job work '.$order->reference_no.' / '.$receipt->reference_no.($theirNames ? ' · Their name: '.implode('; ', $theirNames) : '')
                        .($remarks !== '' ? ' · '.$remarks : ''),
                    'job_work_receipt_id' => $receipt->id, 'job_work_charge_mode' => $data['charge_mode'] ?? 'capitalise',
                    // The job worker's own bill number and date print as the Bill No.; blank due date falls back to their credit days.
                    'supplier_invoice_no' => trim((string) ($data['supplier_invoice_no'] ?? '')) ?: null,
                    'supplier_invoice_date' => ($data['supplier_invoice_date'] ?? null) ?: null,
                    'job_work_remarks' => $remarks !== '' ? $remarks : null,
                ] + (!empty($data['due_date']) ? ['due_date' => $data['due_date']] : []), 'job-work-bill:'.$key, $actor, $context));
                DB::table('job_work_receipts')->where('id', $receiptId)->update(['service_purchase_id' => $purchase->id]);
                return $receipt->fresh();
            });
    }

    /**
     * Edit a booked service bill: reverse it (reason kept for audit) and book the corrected bill against the same GRN,
     * in one transaction. bill_id names the bill being edited, so a resubmitted form never reverses its own correction.
     */
    public function rebill(int $receiptId, array $data, string $key, CompanyContext $context, int $actor): JobWorkReceipt
    {
        validator($data, ['bill_id' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500'])->validate();
        return DB::transaction(function () use ($receiptId, $data, $key, $context, $actor) {
            $receipt = JobWorkReceipt::visibleIn($context)->lockForUpdate()->findOrFail($receiptId);
            if ((int) $receipt->service_purchase_id !== (int) $data['bill_id']) {
                if ($receipt->service_purchase_id) return $receipt; // already corrected by this form
                throw ValidationException::withMessages(['bill_id' => 'This GRN has no service bill to edit. Book a new bill instead.']);
            }
            $bill = \App\Models\Purchase::visibleIn($context)->findOrFail($receipt->service_purchase_id);
            app(\App\Services\Commercial\CommercialReversalService::class)->reverse($bill, (string) ($data['business_date'] ?? ''), $data['reason'], $context);
            return $this->serviceBill($receiptId, \Illuminate\Support\Arr::except($data, ['bill_id', 'reason']), $key, $context, $actor);
        });
    }

    public function pending(CompanyContext $context, int $actor): array
    {
        app(OperationPosting::class)->authorize('operations.job_work', 'job_work.read', $context, $actor);
        // Read the ledger, including partial receipts and reversals, rather than a cached dispatch total.
        return DB::table('job_work_orders as o')->join('stock_movement_lines as l', 'l.warehouse_id', '=', 'o.external_warehouse_id')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->where('o.company_id', $context->companyId)->where('o.branch_id', $context->branchId)
            ->where('l.company_id', $context->companyId)->where('m.company_id', $context->companyId)->where('m.projection_mode', 'applied')
            ->groupBy('o.id', 'o.reference_no', 'o.job_worker_party_id', 'l.product_id')
            ->selectRaw('o.id AS order_id, o.reference_no, o.job_worker_party_id, l.product_id, SUM(l.qty_base) AS pending_qty, SUM(l.value) AS pending_value')
            ->havingRaw('ABS(SUM(l.qty_base)) > 0.00005')->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Sec 143(3): inputs not back within 1 year (capital goods 3 years) of the first Material DC are a deemed supply on
     * the DC date. Each pending row gets that deadline, the days left on $asOf, and ok / due_soon (60 days) / overdue.
     */
    public function deadlines(CompanyContext $context, int $actor, ?string $asOf = null): array
    {
        $asOf = \Carbon\CarbonImmutable::parse($asOf ?? now()->toDateString());
        $capital = \Illuminate\Support\Facades\Schema::hasColumn('products', 'is_capital_goods');
        return array_map(function (array $row) use ($context, $asOf, $capital) {
            $sent = DB::table('job_work_dispatches as d')->join('job_work_dispatch_lines as l', 'l.dispatch_id', '=', 'd.id')
                ->where('d.company_id', $context->companyId)->where('d.job_work_order_id', $row['order_id'])->whereNull('d.reversed_at')
                ->where('l.product_id', $row['product_id'])->min('d.business_date')
                ?? DB::table('job_work_orders')->where('id', $row['order_id'])->value('business_date');
            $years = $capital && DB::table('products')->where('id', $row['product_id'])->value('is_capital_goods') ? 3 : 1;
            $due = \Carbon\CarbonImmutable::parse(substr((string) $sent, 0, 10))->addYears($years);
            $left = (int) $asOf->diffInDays($due, false);
            return $row + ['sent_on' => substr((string) $sent, 0, 10), 'return_by' => $due->toDateString(), 'days_left' => $left,
                'goods_kind' => $years === 3 ? 'capital_goods' : 'inputs', 'status' => $left < 0 ? 'overdue' : ($left <= 60 ? 'due_soon' : 'ok')];
        }, $this->pending($context, $actor));
    }

    public function reverse(string $kind, int $id, string $date, string $reason, CompanyContext $context, int $actor)
    {
        app(OperationPosting::class)->authorize('operations.job_work', 'job_work.reverse', $context, $actor);
        validator(['reason' => $reason], ['reason' => 'required|string|max:500'])->validate();
        return DB::transaction(function () use ($kind, $id, $date, $reason, $context, $actor) {
            app(CompanyWriteGuard::class)->begin($context, $date);
            $model = match ($kind) { 'dispatch' => JobWorkDispatch::class, 'receipt' => JobWorkReceipt::class,
                default => throw ValidationException::withMessages(['kind' => 'Reverse a dispatch or receipt.']) };
            $record = $model::visibleIn($context)->lockForUpdate()->findOrFail($id);
            if ($record->reversed_at) return $record;
            if ($date < $record->business_date) throw ValidationException::withMessages(['business_date' => 'Reversal cannot precede the operation.']);
            $openReceipts = $kind === 'dispatch' ? JobWorkReceipt::where('dispatch_id', $id)->whereNull('reversed_at')->pluck('reference_no') : collect();
            if ($openReceipts->isNotEmpty()) {
                throw ValidationException::withMessages(['dispatch' => 'Reverse GRN '.$openReceipts->implode(', ').' before reversing this DC.']);
            }
            if ($kind === 'receipt' && self::supports('job_work_dispatches', 'rework_of_receipt_id')
                && ($rework = JobWorkDispatch::where('rework_of_receipt_id', $id)->whereNull('reversed_at')->value('reference_no'))) {
                throw ValidationException::withMessages(['receipt' => 'Reverse the rework DC '.$rework.' sent against this GRN first.']);
            }
            if ($kind === 'receipt' && $record->service_purchase_id) {
                $purchase = \App\Models\Purchase::visibleIn($context)->findOrFail($record->service_purchase_id);
                if (!$purchase->reversed_at) throw ValidationException::withMessages(['service_bill' => 'Reverse the job worker\'s service bill '.$purchase->reference_no.' first (Purchase Bills).']);
            }
            $ids = $kind === 'dispatch' ? [$record->movement_id] : [$record->output_movement_id,
                $record->details_json['rejected_movement_id'] ?? null, $record->material_movement_id, $record->loss_movement_id];
            foreach ($ids as $movement) if ($movement) app(InventoryMovementService::class)->reverse($movement, $reason, $actor, $date);
            if ($kind === 'receipt' && $record->journal_entry_id) app(AccountingPostingService::class)->reverse($record->journal_entry_id, $date, $reason, $context);
            DB::table($record->getTable())->where('id', $id)->update(['reversed_at' => now(), 'reversal_reason' => $reason]);
            app(OperationPosting::class)->audit('job_work_'.$kind.'_reverse', $id, $context, $actor, compact('date', 'reason'));
            return $record->fresh();
        }, 3);
    }

    /** Columns added by later migrations; older installs keep working without them. */
    public static function supports(string $table, string $column): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
    }
}
