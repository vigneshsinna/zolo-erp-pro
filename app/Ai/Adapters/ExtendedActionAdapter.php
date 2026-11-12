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
use App\Ai\Services\ExtendedMasterService;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Operations\Bom;
use App\Models\Operations\JobWorkDispatch;
use App\Models\Operations\JobWorkOrder;
use App\Models\Operations\ProductionOrder;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Commercial\CommercialPermission;
use App\Services\Deployment\OpeningImportService;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Inventory\UomConversionService;
use App\Services\JobWork\JobWorkService;
use App\Services\Manufacturing\ProductionService;
use App\Services\Operations\OperationPosting;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use App\Support\LedgerAmount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A reviewed dispatcher for related operational capabilities. Previews never write.
 * Domain effects and the durable recovery receipt commit in the same transaction.
 */
final class ExtendedActionAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(ActionDefinition $definition, array $slots, CompanyContext|BootstrapSetupPrincipal $context): ActionPreviewResult
    {
        $context = $this->companyContext($context);
        $slots = $this->normalize($slots);
        $preview = match ($definition->key) {
            'stock_transfer.create.v1', 'stock_adjust.create.v1', 'stock_adjustment.create.v1' => $this->inventoryPreview($definition->key, $slots, $context),
            'journal_entry.create.v1' => $this->journalPreview($slots, $context),
            'production_order.create.v1' => $this->productionPreview($slots, $context),
            'production_order.complete.v1' => $this->productionCompletionPreview($slots, $context),
            'job_work.outward.v1', 'job_work.inward.v1' => $this->jobWorkPreview($definition->key, $slots, $context),
            'setup.account.create.v1' => app(ExtendedMasterService::class)->accountPreview($slots, $context),
            'setup.pricelist.update.v1' => app(ExtendedMasterService::class)->pricePreview($slots, $context),
            'setup.opening_stock.create.v1', 'setup.opening_balance.create.v1' => $this->openingPreview($definition->key, $slots, $context),
            default => throw new \InvalidArgumentException('Unreviewed extended action.'),
        };
        return new ActionPreviewResult(
            payload: $slots,
            preview: $this->normalize($preview + ['action_key' => $definition->key, 'approval_required' => true]),
            sourceStateHash: $this->sourceHash($definition->key, $slots, $context),
        );
    }

    public function execute(ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId, string $idempotencyKey, string $expectedSourceStateHash): ActionExecutionReceipt
    {
        $context = $this->companyContext($context);
        app(CompanyContextResolver::class)->forActor($context, $actorId);
        foreach ($definition->requiredPermissions as $permission) {
            app(CommercialPermission::class)->assert($permission, $context, $actorId);
        }
        $payloadHash = CanonicalActionPayload::hash($payload);
        return DB::transaction(function () use ($definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash, $payloadHash): ActionExecutionReceipt {
            Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $existing = $this->receiptRow($idempotencyKey, $context)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->payload_hash !== $payloadHash || $existing->action_key !== $definition->key) {
                    throw new AuthorizationException('Idempotency key identifies a different approved command.');
                }
                return $this->decodeReceipt($existing->receipt_json);
            }
            $this->assertAdministrativeAction($definition->key, $context, $actorId);
            $this->lockDependencies($payload, $context);
            if (!hash_equals($expectedSourceStateHash, $this->sourceHash($definition->key, $payload, $context))) {
                $this->fail('source_state', 'ERP source state changed. Rebuild the proposal and obtain fresh approval.');
            }
            // Re-run the read-only validators under locks. Never trust stale preview validation.
            $this->preview($definition, $payload, $context);
            if (isset($payload['business_date'])) {
                app(CompanyWriteGuard::class)->begin($context, $payload['business_date']);
            }
            $record = match ($definition->key) {
                'stock_transfer.create.v1' => $this->inventoryCommand('transfer', $payload, $context, $actorId, $idempotencyKey),
                'stock_adjust.create.v1', 'stock_adjustment.create.v1' => app(ExtendedMasterService::class)->adjustStock($payload, $context, $actorId, $idempotencyKey),
                'journal_entry.create.v1' => app(AccountingPostingService::class)->postJournalEntry([
                    'entry_date' => $payload['business_date'], 'description' => $payload['description'],
                    'reference_type' => 'manual', 'created_by' => $actorId, 'idempotency_key' => $idempotencyKey,
                ], $payload['items'], $context),
                'production_order.create.v1' => app(ProductionService::class)->plan($payload, $idempotencyKey, $context, $actorId),
                'production_order.complete.v1' => app(ProductionService::class)->complete((int) $payload['production_order_id'], $payload, $idempotencyKey, $context, $actorId),
                'job_work.outward.v1' => app(JobWorkService::class)->dispatch((int) $payload['order_id'], $payload, $idempotencyKey, $context, $actorId),
                'job_work.inward.v1' => app(JobWorkService::class)->receive((int) $payload['dispatch_id'], $payload, $idempotencyKey, $context, $actorId),
                'setup.account.create.v1' => app(ExtendedMasterService::class)->createAccount($payload, $context, $actorId),
                'setup.pricelist.update.v1' => app(ExtendedMasterService::class)->updatePrices($payload, $context, $actorId),
                'setup.opening_stock.create.v1', 'setup.opening_balance.create.v1' => app(OpeningImportService::class)->commit((int) $payload['batch_id'], $context, $actorId),
                default => throw new \InvalidArgumentException('Unreviewed extended action.'),
            };
            $type = match ($definition->key) {
                'stock_transfer.create.v1' => 'stock_movement',
                'stock_adjust.create.v1', 'stock_adjustment.create.v1' => 'adjustment',
                'journal_entry.create.v1' => 'journal_entry',
                'production_order.create.v1', 'production_order.complete.v1' => 'production_order',
                'job_work.outward.v1' => 'job_work_dispatch',
                'job_work.inward.v1' => 'job_work_receipt',
                'setup.account.create.v1' => 'chart_of_account',
                'setup.pricelist.update.v1' => 'price_list_update',
                default => 'opening_import',
            };
            $receipt = new ActionExecutionReceipt($type, (int) $record->id, 'completed', $this->normalize([
                'reference_no' => $record->reference_no ?? $record->entry_number ?? $record->movement_no ?? $record->code ?? null,
                'company_id' => $context->companyId, 'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId, 'action_key' => $definition->key,
                'status' => $record->status ?? 'completed',
                'summary' => $record instanceof ImportBatch ? $record->summary_json : null,
                'changed_items' => $definition->key === 'setup.pricelist.update.v1' ? count($payload['items']) : null,
            ]));
            DB::table('ai_extended_action_receipts')->insert([
                'company_id' => $context->companyId, 'branch_id' => $context->branchId,
                'financial_year_id' => $context->financialYearId, 'actor_id' => $actorId,
                'action_key' => $definition->key, 'idempotency_key' => $idempotencyKey, 'payload_hash' => $payloadHash,
                'receipt_json' => CanonicalActionPayload::json([
                    'resource_type' => $receipt->resourceType, 'resource_id' => $receipt->resourceId,
                    'status' => $receipt->status, 'details' => $receipt->details,
                ]), 'created_at' => now('UTC'),
            ]);
            return $receipt;
        });
    }

    public function recover(string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context): ?ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) {
            return null;
        }
        $row = $this->receiptRow($idempotencyKey, $context)->first();
        return $row ? $this->decodeReceipt($row->receipt_json) : null;
    }

    private function inventoryPreview(string $key, array $slots, CompanyContext $context): array
    {
        $this->assertDate($slots['business_date'], $context);
        $warehouse = $this->warehouse((int) $slots['warehouse_id'], $context);
        $transfer = $key === 'stock_transfer.create.v1';
        $destination = $transfer ? $this->warehouse((int) ($slots['to_warehouse_id'] ?? 0), $context) : null;
        if ($destination && $destination->id === $warehouse->id) {
            $this->fail('to_warehouse_id', 'Choose a different destination warehouse.');
        }
        $products = $this->stockLines($slots['lines'], $context);
        $availability = app(InventoryAvailabilityService::class)->forCatalogBatch($context, $products, [$warehouse->id]);
        $required = [];
        $lines = [];
        foreach ($slots['lines'] as $line) {
            $product = $products->get($line['product_id']);
            if ($transfer && LedgerAmount::units($line['qty']) <= 0) {
                $this->fail('lines.qty', 'Transfer quantities must be positive.');
            }
            $base = app(UomConversionService::class)->toBase($product, (float) $line['qty'], (int) $line['uom_id']);
            $outbound = $transfer || $base < 0;
            if ($outbound) {
                $required[$product->id] = ($required[$product->id] ?? 0) + LedgerAmount::units(abs($base));
            } elseif (!isset($line['unit_cost'])) {
                $this->fail('lines.unit_cost', 'Positive stock adjustments require an explicit reviewed unit cost.');
            }
            $state = $availability[$product->id] ?? [];
            if (!empty($state['unreconciled'])) {
                $this->fail('lines', 'Stock projection and movement ledger differ. Reconcile stock before adjustment or transfer.');
            }
            $lines[] = $line + ['product_name' => $product->name, 'base_qty' => LedgerAmount::decimal(LedgerAmount::units($base))];
        }
        foreach ($required as $productId => $qty) {
            $stock = $availability[$productId] ?? [];
            if ($qty > ($stock['on_hand'] ?? 0) - ($stock['reserved'] ?? 0)) {
                $this->fail('lines.qty', 'Requested quantity exceeds available stock after reservations.');
            }
        }
        if (!$transfer) {
            app(\App\Services\Accounting\SemanticAccountResolver::class)->resolve('inventory', $context);
            app(\App\Services\Accounting\SemanticAccountResolver::class)->resolve('variance', $context);
        }
        return ['document_type' => $transfer ? 'Warehouse Transfer' : 'Stock Adjustment',
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
            'destination' => $destination ? ['id' => $destination->id, 'name' => $destination->name] : null,
            'business_date' => $slots['business_date'], 'reason' => $slots['reason'], 'lines' => $lines,
            'policy' => ['inventory_impact' => $transfer ? 'balanced_warehouse_transfer' : 'signed_adjustment',
                'ledger_impact' => $transfer ? 'none' : 'actual_movement_value_against_inventory_variance']];
    }

    private function journalPreview(array $slots, CompanyContext $context): array
    {
        $this->assertDate($slots['business_date'], $context);
        $debits = $credits = 0;
        $lines = [];
        foreach ($slots['items'] as $item) {
            $account = ChartOfAccount::where('company_id', $context->companyId)->find((int) $item['chart_of_account_id']);
            if (!$account || !$account->is_active || !$account->allow_manual_posting
                || ChartOfAccount::where('company_id', $context->companyId)->where('parent_id', $account->id)->exists()) {
                $this->fail('items.chart_of_account_id', 'Choose an active company leaf account allowing manual posting.');
            }
            $debit = LedgerAmount::units($item['debit'] ?? 0);
            $credit = LedgerAmount::units($item['credit'] ?? 0);
            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                $this->fail('items', 'Every journal line must have one positive debit or credit.');
            }
            if (!empty($item['partner_id']) || !empty($item['partner_type']) || in_array($account->control_type, ['ar', 'ap'], true)) {
                $type = $item['partner_type'] ?? '';
                $table = match ($type) { 'customer' => 'customers', 'supplier' => 'suppliers', default => null };
                if (!$table || !DB::table($table)->where('company_id', $context->companyId)->where('id', $item['partner_id'] ?? 0)->where('is_active', true)->exists()
                    || ($account->control_type === 'ar' && $type !== 'customer') || ($account->control_type === 'ap' && $type !== 'supplier')) {
                    $this->fail('items.partner_id', 'Control-account lines require the correct active company customer or supplier.');
                }
            }
            $debits += $debit;
            $credits += $credit;
            $lines[] = $item + ['account_code' => $account->code, 'account_name' => $account->name];
        }
        if ($debits === 0 || $debits !== $credits) {
            $this->fail('items', 'Journal debit and credit totals must balance exactly at four-decimal precision.');
        }
        return ['document_type' => 'Journal Entry', 'business_date' => $slots['business_date'],
            'description' => $slots['description'], 'lines' => $lines,
            'totals' => ['debit' => LedgerAmount::decimal($debits), 'credit' => LedgerAmount::decimal($credits)],
            'policy' => ['ledger_impact' => 'posted_balanced_journal', 'inventory_impact' => 'none']];
    }

    private function productionPreview(array $slots, CompanyContext $context): array
    {
        $this->assertOperationalFeature('manufacturing.production', $context);
        $this->assertDate($slots['business_date'], $context);
        $this->warehouse((int) $slots['warehouse_id'], $context);
        $bom = Bom::where('company_id', $context->companyId)->where('status', 'published')->find((int) $slots['bom_id']);
        if (!$bom || $slots['business_date'] < $bom->effective_from || ($bom->effective_to && $slots['business_date'] > $bom->effective_to)) {
            $this->fail('bom_id', 'Choose a published company BOM effective on the production date.');
        }
        $lines = DB::table('bom_lines')->where('company_id', $context->companyId)->where('bom_id', $bom->id)->orderBy('line_no')->get();
        $components = [];
        $warnings = [];
        foreach ($lines as $line) {
            $product = Product::where('company_id', $context->companyId)->where('is_active', true)->find($line->component_product_id);
            if (!$product) {
                $this->fail('bom_id', 'BOM contains an unavailable company product.');
            }
            $quantity = round((float) $line->qty * (float) $slots['planned_qty'] / (float) $bom->output_qty * (1 + (float) $line->scrap_percent / 100), 4);
            $available = app(InventoryAvailabilityService::class)->forCatalogBatch($context, collect([$product]), [(int) $slots['warehouse_id']]);
            $baseQty = app(UomConversionService::class)->toBase($product, $quantity, (int) $line->uom_id);
            $stock = $available[$product->id] ?? [];
            if (LedgerAmount::units($baseQty) > ($stock['on_hand'] ?? 0) - ($stock['reserved'] ?? 0)) {
                $warnings[] = 'Material shortage for '.$product->name.'. Completion requires fresh material validation.';
            }
            $components[] = ['product_id' => $product->id, 'product_name' => $product->name,
                'uom_id' => $line->uom_id, 'planned_qty' => LedgerAmount::decimal(LedgerAmount::units($quantity))];
        }
        return ['document_type' => 'Production Plan', 'business_date' => $slots['business_date'],
            'bom_id' => $bom->id, 'bom_version' => $bom->version, 'product_id' => $bom->product_id,
            'planned_qty' => $slots['planned_qty'], 'components' => $components, 'warnings' => $warnings,
            'policy' => ['inventory_impact' => 'none_until_separate_completion', 'ledger_impact' => 'none_until_separate_completion']];
    }

    private function jobWorkPreview(string $key, array $slots, CompanyContext $context): array
    {
        $this->assertOperationalFeature('operations.job_work', $context);
        $this->assertDate($slots['business_date'], $context);
        $this->warehouse((int) $slots['warehouse_id'], $context);
        if ($key === 'job_work.outward.v1') {
            $order = JobWorkOrder::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)->find((int) ($slots['order_id'] ?? 0));
            if (!$order || $slots['business_date'] < $order->business_date || $order->reversed_at) {
                $this->fail('order_id', 'Choose an active job-work order in the selected branch and year.');
            }
            $products = $this->stockLines($slots['lines'], $context);
            $available = app(InventoryAvailabilityService::class)->forCatalogBatch($context, $products, [(int) $slots['warehouse_id']]);
            $requested = [];
            foreach ($slots['lines'] as $line) {
                $product = $products->get($line['product_id']);
                $base = app(UomConversionService::class)->toBase($product, (float) ($line['qty'] ?? 0), (int) $line['uom_id']);
                if ($base <= 0) {
                    $this->fail('lines.qty', 'Dispatch quantities must be positive.');
                }
                $requested[$product->id] = ($requested[$product->id] ?? 0) + LedgerAmount::units($base);
            }
            foreach ($requested as $id => $quantity) {
                $stock = $available[$id] ?? [];
                if (!empty($stock['unreconciled']) || $quantity > ($stock['on_hand'] ?? 0) - ($stock['reserved'] ?? 0)) {
                    $this->fail('lines.qty', 'Dispatch requires reconciled stock sufficient after reservations.');
                }
            }
            return ['document_type' => 'Job Work Outward', 'order_id' => $order->id, 'reference_no' => $order->reference_no,
                'lines' => $slots['lines'], 'policy' => ['inventory_impact' => 'transfer_to_job_worker_location', 'ledger_impact' => 'none']];
        }
        $dispatch = JobWorkDispatch::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)->find((int) ($slots['dispatch_id'] ?? 0));
        if (!$dispatch || $dispatch->reversed_at || $slots['business_date'] < $dispatch->business_date) {
            $this->fail('dispatch_id', 'Choose an active job-work dispatch in the selected branch and year.');
        }
        $order = JobWorkOrder::where('company_id', $context->companyId)->findOrFail($dispatch->job_work_order_id);
        $policy = $order->details_json['process'];
        if (!empty($slots['rejected_warehouse_id'])) {
            $this->warehouse((int) $slots['rejected_warehouse_id'], $context);
            if ((int) $slots['rejected_warehouse_id'] === (int) $slots['warehouse_id']) {
                $this->fail('rejected_warehouse_id', 'Rejected goods require a different quarantine warehouse.');
            }
        }
        foreach ($slots['lines'] as $line) {
            $source = DB::table('job_work_dispatch_lines')->where('company_id', $context->companyId)
                ->where('dispatch_id', $dispatch->id)->where('id', $line['dispatch_line_id'] ?? 0)->first();
            if (!$source || !isset($line['accepted_qty'], $line['rejected_qty'], $line['loss_qty'])) {
                $this->fail('lines', 'Each receipt line needs a dispatch line and accepted, rejected and loss quantities.');
            }
            $settled = DB::table('job_work_receipt_lines as l')->join('job_work_receipts as r', 'r.id', '=', 'l.receipt_id')
                ->where('l.company_id', $context->companyId)->where('r.company_id', $context->companyId)
                ->where('l.dispatch_line_id', $source->id)->whereNull('r.reversed_at')
                ->selectRaw('COALESCE(SUM(l.accepted_qty + l.rejected_qty + l.loss_qty), 0) as qty')->value('qty');
            $quantity = LedgerAmount::units($line['accepted_qty']) + LedgerAmount::units($line['rejected_qty']) + LedgerAmount::units($line['loss_qty']);
            if ($quantity <= 0 || $quantity + LedgerAmount::units($settled) > LedgerAmount::units($source->qty_base)) {
                $this->fail('lines', 'Receipt quantity must be positive and within the unsettled dispatch balance.');
            }
            if ((float) $line['loss_qty'] / ($quantity / 10000) * 100 > (float) $policy['max_loss_percent']) {
                $this->fail('lines.loss_qty', 'Loss exceeds the approved job-work process threshold.');
            }
            if (LedgerAmount::units($line['rejected_qty']) > 0 && empty($slots['rejected_warehouse_id'])) {
                $this->fail('rejected_warehouse_id', 'Choose a separate quarantine warehouse for rejected goods.');
            }
        }
        if (isset($slots['outputs'])) {
            foreach ($slots['outputs'] as $line) {
                $this->stockLines([$line], $context);
            }
        }
        return ['document_type' => 'Job Work Inward', 'dispatch_id' => $dispatch->id, 'reference_no' => $dispatch->reference_no,
            'lines' => $slots['lines'], 'outputs' => $slots['outputs'] ?? [],
            'policy' => ['inventory_impact' => 'processed_material_receipt', 'ledger_impact' => 'actual_loss_value_if_any']];
    }

    private function productionCompletionPreview(array $slots, CompanyContext $context): array
    {
        $order = ProductionOrder::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)->find((int) $slots['production_order_id']);
        if (!$order || $order->status !== 'planned' || $slots['business_date'] < $order->business_date
            || LedgerAmount::units($slots['completed_qty']) > LedgerAmount::units($order->planned_qty)) {
            $this->fail('production_order_id', 'Choose a planned production order and a completion quantity within its plan.');
        }
        $plan = $this->productionPreview(['business_date' => $slots['business_date'], 'bom_id' => $order->bom_id,
            'warehouse_id' => $order->warehouse_id, 'planned_qty' => $slots['completed_qty']], $context);
        $bom = Bom::where('company_id', $context->companyId)->findOrFail($order->bom_id);
        $bomLines = DB::table('bom_lines')->where('company_id', $context->companyId)->where('bom_id', $bom->id)->orderBy('line_no')->get();
        $entered = collect($slots['components'] ?? [])->keyBy('bom_line_id');
        if (isset($slots['components']) && ($entered->count() !== $bomLines->count() || $entered->keys()->diff($bomLines->pluck('id'))->isNotEmpty())) {
            $this->fail('components', 'Provide every BOM component exactly once.');
        }
        $consume = [];
        foreach ($bomLines as $index => $line) {
            $consume[] = ['product_id' => $line->component_product_id, 'uom_id' => $line->uom_id,
                'qty' => $entered->get($line->id)['qty'] ?? $plan['components'][$index]['planned_qty']]
                + array_diff_key($entered->get($line->id, []), array_flip(['bom_line_id', 'qty']));
        }
        $products = $this->stockLines($consume, $context);
        $availability = app(InventoryAvailabilityService::class)->forCatalogBatch($context, $products, [(int) $order->warehouse_id]);
        $needed = [];
        foreach ($consume as $line) {
            $base = app(UomConversionService::class)->toBase($products->get($line['product_id']), (float) $line['qty'], (int) $line['uom_id']);
            $needed[$line['product_id']] = ($needed[$line['product_id']] ?? 0) + LedgerAmount::units($base);
        }
        foreach ($needed as $id => $quantity) {
            $stock = $availability[$id] ?? [];
            if (!empty($stock['unreconciled']) || $quantity > ($stock['on_hand'] ?? 0) - ($stock['reserved'] ?? 0)) {
                $this->fail('components', 'Production completion needs reconciled and available components after reservations.');
            }
        }
        $outputs = $slots['outputs'] ?? [['product_id' => $bom->product_id, 'uom_id' => $bom->output_uom_id,
            'qty' => $slots['completed_qty'], 'cost_weight' => '1.0000']];
        $allOutputs = [...$outputs, ...($slots['scrap_outputs'] ?? [])];
        $this->stockLines($allOutputs, $context);
        $mainQty = 0;
        $weight = 0;
        foreach ($allOutputs as $line) {
            if (in_array((int) $line['product_id'], $bomLines->pluck('component_product_id')->map(fn ($id) => (int) $id)->all(), true)) {
                $this->fail('outputs', 'Outputs must differ from consumed component products.');
            }
            $weight += LedgerAmount::units($line['cost_weight']);
        }
        foreach ($outputs as $line) {
            if ((int) $line['product_id'] === (int) $bom->product_id) {
                if ((int) $line['uom_id'] !== (int) $bom->output_uom_id) {
                    $this->fail('outputs', 'Primary output must use the BOM output unit.');
                }
                $mainQty += LedgerAmount::units($line['qty']);
            }
        }
        if ($mainQty !== LedgerAmount::units($slots['completed_qty']) || $weight <= 0) {
            $this->fail('outputs', 'Primary output must equal completed quantity and cost weights must have a positive total.');
        }
        return ['document_type' => 'Production Completion', 'reference_no' => $order->reference_no,
            'business_date' => $slots['business_date'], 'consumption' => $consume, 'outputs' => $outputs,
            'scrap_outputs' => $slots['scrap_outputs'] ?? [], 'direct_cost' => $slots['direct_cost'] ?? '0.0000',
            'overhead_cost' => $slots['overhead_cost'] ?? '0.0000', 'waste_qty' => $slots['waste_qty'] ?? '0.0000',
            'policy' => ['inventory_impact' => 'consume_components_and_receive_outputs',
                'ledger_impact' => 'domain_production_cost_allocation_at_approved_source_state']];
    }

    private function openingPreview(string $key, array $slots, CompanyContext $context): array
    {
        $batch = ImportBatch::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)->find((int) $slots['batch_id']);
        if (!$batch || $batch->status !== 'validated') {
            $this->fail('batch_id', 'Select an already validated, uncommitted opening import batch for this branch and year.');
        }
        $this->assertDate($batch->business_date, $context);
        $kinds = DB::table('import_rows')->where('batch_id', $batch->id)->distinct()->pluck('kind')->all();
        if ($key === 'setup.opening_stock.create.v1' && !in_array('stock', $kinds, true)) {
            $this->fail('batch_id', 'This opening batch does not contain stock.');
        }
        if ($key === 'setup.opening_balance.create.v1' && !array_intersect($kinds, ['journal', 'receivable', 'payable'])) {
            $this->fail('batch_id', 'This opening batch does not contain financial balances.');
        }
        if (DB::table('stock_movements')->where('company_id', $context->companyId)->exists()
            || DB::table('journal_entries')->where('company_id', $context->companyId)->where('status', 'posted')->exists()
            || DB::table('products')->where('company_id', $context->companyId)->where('qty', '!=', 0)->exists()
            || DB::table('chart_of_accounts')->where('company_id', $context->companyId)
                ->where(fn ($q) => $q->where('opening_balance', '!=', 0)->orWhere('current_balance', '!=', 0))->exists()) {
            $this->fail('batch_id', 'Opening imports require a clean company stock and accounting target.');
        }
        return ['document_type' => 'Reviewed Opening Import', 'batch_id' => $batch->id,
            'source_name' => $batch->source_name, 'business_date' => $batch->business_date,
            'expected' => $batch->expected_json, 'summary' => $batch->summary_json, 'kinds' => $kinds,
            'policy' => ['inventory_impact' => 'only_reviewed_batch_rows', 'ledger_impact' => 'reconciled_opening_entries',
                'whole_batch_commit' => true]];
    }

    private function inventoryCommand(string $method, array $payload, CompanyContext $context, int $actor, string $key): \Illuminate\Database\Eloquent\Model
    {
        $lines = array_map(static fn (array $line) => StockLine::fromArray($line), $payload['lines']);
        return app(InventoryMovementService::class)->{$method}(new StockMovementCommand(
            date: $payload['business_date'], lines: $lines, warehouseId: (int) $payload['warehouse_id'],
            toWarehouseId: !empty($payload['to_warehouse_id']) ? (int) $payload['to_warehouse_id'] : null,
            idempotencyKey: $key, reason: $payload['reason'], userId: $actor, context: $context,
            sourceType: 'ai_approved_transfer',
        ));
    }

    /** @return \Illuminate\Support\Collection<int, Product> */
    private function stockLines(array $lines, CompanyContext $context): \Illuminate\Support\Collection
    {
        $products = Product::where('company_id', $context->companyId)->where('is_active', true)
            ->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        foreach ($lines as $line) {
            $product = $products->get($line['product_id'] ?? 0);
            if (!$product || $product->type !== 'standard') {
                $this->fail('lines.product_id', 'Choose an active company stock product.');
            }
            if (!Unit::where(function ($q) use ($context) {
                $q->where('company_id', $context->companyId)->orWhereNull('company_id');
            })->where('id', $line['uom_id'] ?? 0)->where('is_active', true)->exists()) {
                $this->fail('lines.uom_id', 'Choose an active unit of this company.');
            }
            app(UomConversionService::class)->toBase($product, (float) ($line['qty'] ?? 0), (int) $line['uom_id']);
            foreach (['batch_id' => 'product_batches', 'product_batch_id' => 'product_batches', 'stock_identity_id' => 'stock_identities'] as $field => $table) {
                if (!empty($line[$field]) && !DB::table($table)->where('company_id', $context->companyId)
                    ->where('product_id', $product->id)->where('id', $line[$field])->exists()) {
                    $this->fail('lines.'.$field, 'The selected stock identity must belong to this product and company.');
                }
            }
            if (!empty($line['variant_id']) && !DB::table('product_variants')->where('company_id', $context->companyId)
                ->where('product_id', $product->id)->where('variant_id', $line['variant_id'])->exists()) {
                $this->fail('lines.variant_id', 'Choose a variant of the selected company product.');
            }
        }
        return $products;
    }

    private function sourceHash(string $key, array $payload, CompanyContext $context): string
    {
        if (isset($payload['production_order_id'])) {
            $order = ProductionOrder::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)->find((int) $payload['production_order_id']);
            if ($order) {
                $payload['bom_id'] = $order->bom_id;
                $payload['warehouse_id'] = $order->warehouse_id;
            }
        }
        $state = [
            'context' => [$context->companyId, $context->branchId, $context->financialYearId],
            'company' => (array) DB::table('companies')->where('id', $context->companyId)->first(),
            'year' => (array) DB::table('fiscal_years')->where('company_id', $context->companyId)->where('id', $context->financialYearId)->first(),
        ];
        $productIds = array_values(array_unique(array_filter([
            ...array_column($payload['lines'] ?? [], 'product_id'), ...array_column($payload['items'] ?? [], 'product_id'),
            ...array_column($payload['outputs'] ?? [], 'product_id'),
            ...array_column($payload['scrap_outputs'] ?? [], 'product_id'),
        ])));
        if (isset($payload['bom_id'])) {
            $productIds = array_values(array_unique([...$productIds,
                ...DB::table('bom_lines')->where('company_id', $context->companyId)->where('bom_id', $payload['bom_id'])->pluck('component_product_id')->all(),
                ...DB::table('boms')->where('company_id', $context->companyId)->where('id', $payload['bom_id'])->pluck('product_id')->all(),
            ]));
        }
        if (isset($payload['dispatch_id'])) {
            $productIds = array_values(array_unique([...$productIds,
                ...DB::table('job_work_dispatch_lines')->where('company_id', $context->companyId)
                    ->where('dispatch_id', $payload['dispatch_id'])->pluck('product_id')->all(),
            ]));
        }
        $tables = [
            'warehouses' => array_filter([$payload['warehouse_id'] ?? null, $payload['to_warehouse_id'] ?? null, $payload['rejected_warehouse_id'] ?? null]),
            'products' => $productIds,
            'chart_of_accounts' => array_filter([...array_column($payload['items'] ?? [], 'chart_of_account_id'), $payload['parent_id'] ?? null]),
            'boms' => array_filter([$payload['bom_id'] ?? null]),
            'job_work_orders' => array_filter([$payload['order_id'] ?? null]),
            'job_work_dispatches' => array_filter([$payload['dispatch_id'] ?? null]),
            'import_batches' => array_filter([$payload['batch_id'] ?? null]),
            'production_orders' => array_filter([$payload['production_order_id'] ?? null]),
        ];
        foreach ($tables as $table => $ids) {
            $state[$table] = DB::table($table)->where('company_id', $context->companyId)->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        foreach (['product_warehouse', 'stock_movement_lines', 'product_sales', 'product_batches', 'product_variants', 'stock_identities'] as $table) {
            $state[$table] = DB::table($table)->where('company_id', $context->companyId)->whereIn('product_id', $productIds)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $state['semantic_account_mappings'] = DB::table('semantic_account_mappings')->where('company_id', $context->companyId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        if (isset($payload['bom_id'])) {
            $state['bom_lines'] = DB::table('bom_lines')->where('company_id', $context->companyId)->where('bom_id', $payload['bom_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        if (isset($payload['dispatch_id'])) {
            $state['dispatch_lines'] = DB::table('job_work_dispatch_lines')->where('company_id', $context->companyId)->where('dispatch_id', $payload['dispatch_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $receiptIds = DB::table('job_work_receipts')->where('company_id', $context->companyId)->where('dispatch_id', $payload['dispatch_id'])->pluck('id');
            $state['receipt_lines'] = DB::table('job_work_receipt_lines')->where('company_id', $context->companyId)->whereIn('receipt_id', $receiptIds)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        if (isset($payload['batch_id'])) {
            foreach (['import_rows', 'import_mappings'] as $table) {
                // The parent import batch has already been explicitly scoped above.
                $state[$table] = $state['import_batches'] ? DB::table($table)->where('batch_id', $payload['batch_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() : [];
            }
        }
        if ($key === 'setup.account.create.v1') {
            $state['duplicate_accounts'] = DB::table('chart_of_accounts')->where('company_id', $context->companyId)
                ->where(fn ($q) => $q->where('code', $payload['code'])->orWhere('name', $payload['name']))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        // Raw database decimals retain their original precision in the snapshot.
        // They are hashed, never exposed as approved monetary payloads.
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function lockDependencies(array $payload, CompanyContext $context): void
    {
        if (isset($payload['production_order_id'])) {
            $order = ProductionOrder::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
                ->where('financial_year_id', $context->financialYearId)->whereKey($payload['production_order_id'])->lockForUpdate()->first();
            if ($order) {
                $payload['bom_id'] = $order->bom_id;
                $payload['warehouse_id'] = $order->warehouse_id;
            }
        }
        DB::table('fiscal_years')->where('company_id', $context->companyId)->where('id', $context->financialYearId)->lockForUpdate()->first();
        $ids = [...array_column($payload['lines'] ?? [], 'product_id'), ...array_column($payload['items'] ?? [], 'product_id')];
        $ids = [...$ids, ...array_column($payload['outputs'] ?? [], 'product_id'), ...array_column($payload['scrap_outputs'] ?? [], 'product_id')];
        if (isset($payload['dispatch_id'])) {
            $ids = [...$ids, ...DB::table('job_work_dispatch_lines')->where('company_id', $context->companyId)
                ->where('dispatch_id', $payload['dispatch_id'])->pluck('product_id')->all()];
        }
        if (isset($payload['bom_id'])) {
            $ids = [...$ids, ...DB::table('bom_lines')->where('company_id', $context->companyId)
                ->where('bom_id', $payload['bom_id'])->pluck('component_product_id')->all()];
        }
        DB::table('products')->where('company_id', $context->companyId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        DB::table('chart_of_accounts')->where('company_id', $context->companyId)
            ->whereIn('id', [...array_column($payload['items'] ?? [], 'chart_of_account_id'), $payload['parent_id'] ?? 0])->orderBy('id')->lockForUpdate()->get();
        DB::table('warehouses')->where('company_id', $context->companyId)
            ->whereIn('id', [$payload['warehouse_id'] ?? 0, $payload['to_warehouse_id'] ?? 0, $payload['rejected_warehouse_id'] ?? 0])->orderBy('id')->lockForUpdate()->get();
        foreach (['boms' => 'bom_id', 'job_work_orders' => 'order_id', 'job_work_dispatches' => 'dispatch_id', 'import_batches' => 'batch_id'] as $table => $field) {
            if (isset($payload[$field])) {
                DB::table($table)->where('company_id', $context->companyId)->where('id', $payload[$field])->lockForUpdate()->first();
            }
        }
    }

    private function assertAdministrativeAction(string $key, CompanyContext $context, int $actor): void
    {
        if (in_array($key, ['setup.account.create.v1', 'setup.opening_stock.create.v1', 'setup.opening_balance.create.v1'], true)
            && !app(CompanyContextResolver::class)->canManageFinancialYears($actor, $context->companyId)) {
            throw new AuthorizationException('Company administrator required for chart or opening setup.');
        }
        if (str_starts_with($key, 'production_order.')) {
            app(OperationPosting::class)->authorize('manufacturing.production', 'manufacturing.production.post', $context, $actor);
        }
        if (str_starts_with($key, 'job_work.')) {
            app(OperationPosting::class)->authorize('operations.job_work', $key === 'job_work.outward.v1' ? 'job_work.dispatch' : 'job_work.receive', $context, $actor);
        }
    }

    private function assertOperationalFeature(string $capability, CompanyContext $context): void
    {
        if (!config('operations.enabled', false)) {
            throw new AuthorizationException('ERP operations are disabled.');
        }
        app(\App\Services\Platform\CapabilityService::class)->assertEnabled($capability, $context);
    }

    private function warehouse(int $id, CompanyContext $context): Warehouse
    {
        $warehouse = Warehouse::where('company_id', $context->companyId)->where('branch_id', $context->branchId)->where('is_active', true)->find($id);
        if (!$warehouse || $warehouse->external_job_order_id) {
            $this->fail('warehouse_id', 'Choose an active internal warehouse in the selected company and branch.');
        }
        return $warehouse;
    }

    private function assertDate(string $date, CompanyContext $context): void
    {
        app(CompanyContextResolver::class)->assertPostingDate($context, $date);
    }

    private function companyContext(CompanyContext|BootstrapSetupPrincipal $context): CompanyContext
    {
        if (!$context instanceof CompanyContext) {
            throw new AuthorizationException('A resolved ERP company context is required.');
        }
        return $context;
    }

    private function normalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            } elseif (is_float($item)) {
                $value[$key] = LedgerAmount::decimal(LedgerAmount::units($item));
            } elseif (in_array($key, ['qty', 'unit_cost', 'price', 'debit', 'credit', 'planned_qty', 'completed_qty', 'direct_cost', 'overhead_cost', 'waste_qty', 'accepted_qty', 'rejected_qty', 'loss_qty', 'cost_weight'], true) && is_numeric($item)) {
                $value[$key] = LedgerAmount::decimal(LedgerAmount::units($item));
            }
        }
        return $value;
    }

    private function receiptRow(string $key, CompanyContext $context): \Illuminate\Database\Query\Builder
    {
        return DB::table('ai_extended_action_receipts')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)->where('idempotency_key', $key);
    }

    private function decodeReceipt(string $json): ActionExecutionReceipt
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return new ActionExecutionReceipt($value['resource_type'], (int) $value['resource_id'], $value['status'], $value['details']);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
