<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Adjustment;
use App\Models\Product;
use App\Models\ProductAdjustment;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Accounting\SettlementAccountService;
use App\Services\Commercial\PostingAccounts;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Operations\OperationPosting;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\DocumentNumberService;
use App\Support\LedgerAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Narrow, audited wrappers for legacy master/adjustment writes that lack a service entry point. */
final class ExtendedMasterService
{
    public function accountPreview(array $slots, CompanyContext $context): array
    {
        if (trim($slots['name']) === '' || trim($slots['code']) === '') {
            $this->fail('name', 'Account code and name must not be blank.');
        }
        if (ChartOfAccount::where('company_id', $context->companyId)
            ->where(fn ($q) => $q->where('code', trim($slots['code']))->orWhere('name', trim($slots['name'])))->exists()) {
            $this->fail('code', 'A company account already uses this code or name.');
        }
        if (!empty($slots['parent_id'])) {
            $parent = ChartOfAccount::where('company_id', $context->companyId)->find((int) $slots['parent_id']);
            if (!$parent || !$parent->is_active || $parent->type !== $slots['type'] || $parent->control_type !== 'none'
                || DB::table('journal_items')->where('company_id', $context->companyId)->where('chart_of_account_id', $parent->id)->exists()
                || DB::table('semantic_account_mappings')->where('company_id', $context->companyId)->where('account_id', $parent->id)->exists()) {
                $this->fail('parent_id', 'Choose an active company group account of the same type without postings or a posting-role mapping.');
            }
        }
        return ['document_type' => 'Chart of Accounts Setup', 'account' => $slots,
            'policy' => ['opening_balance' => '0.0000', 'ledger_impact' => 'none', 'control_type' => 'none']];
    }

    public function createAccount(array $slots, CompanyContext $context, int $actor): ChartOfAccount
    {
        $this->accountPreview($slots, $context);
        app(CompanyWriteGuard::class)->begin($context, null);
        $account = ChartOfAccount::forceCreate([
            'company_id' => $context->companyId, 'code' => trim($slots['code']), 'name' => trim($slots['name']),
            'type' => $slots['type'], 'sub_type' => $slots['sub_type'], 'description' => $slots['description'] ?? null,
            'parent_id' => $slots['parent_id'] ?? null, 'opening_balance' => '0.0000', 'current_balance' => '0.0000',
            'control_type' => 'none', 'allow_manual_posting' => true, 'is_system' => false, 'is_active' => true,
        ]);
        app(SettlementAccountService::class)->syncFromLedger($account, $context);
        app(OperationPosting::class)->audit('ai_account_setup', $account->id, $context, $actor, $slots);
        return $account;
    }

    public function pricePreview(array $slots, CompanyContext $context): array
    {
        $today = CarbonImmutable::now(DB::table('companies')->where('id', $context->companyId)->value('timezone') ?: 'UTC')->toDateString();
        if ($slots['effective_date'] !== $today) {
            $this->fail('effective_date', 'This ERP price list takes effect immediately. Use today as the effective date.');
        }
        $changes = [];
        foreach ($slots['items'] as $line) {
            $product = Product::where('company_id', $context->companyId)->where('is_active', true)->find((int) $line['product_id']);
            if (!$product) {
                $this->fail('items.product_id', 'Select an active product of the current company.');
            }
            $changes[] = ['product_id' => $product->id, 'code' => $product->code, 'name' => $product->name,
                'old_price' => LedgerAmount::decimal(LedgerAmount::units((string) $product->price)),
                'new_price' => LedgerAmount::decimal(LedgerAmount::units($line['price']))];
        }
        return ['document_type' => 'Price List Update', 'effective_date' => $today, 'reason' => $slots['reason'],
            'changes' => $changes, 'policy' => ['ledger_impact' => 'none', 'historical_document_impact' => 'none']];
    }

    public function updatePrices(array $slots, CompanyContext $context, int $actor): Product
    {
        $preview = $this->pricePreview($slots, $context);
        app(CompanyWriteGuard::class)->begin($context, $slots['effective_date']);
        $first = null;
        foreach ($slots['items'] as $line) {
            $product = Product::where('company_id', $context->companyId)->whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
            $product->forceFill(['price' => $line['price']])->save();
            $first ??= $product;
        }
        app(OperationPosting::class)->audit('ai_price_list_update', $first->id, $context, $actor, $preview);
        return $first;
    }

    public function adjustStock(array $slots, CompanyContext $context, int $actor, string $key): Adjustment
    {
        app(CompanyWriteGuard::class)->begin($context, $slots['business_date']);
        $numbers = app(DocumentNumberService::class);
        $reservation = $numbers->reserve('adjustment', $context, $slots['business_date'], $actor);
        $adjustment = Adjustment::forceCreate([
            'company_id' => $context->companyId,
            'reference_no' => $reservation->formatted_number, 'warehouse_id' => $slots['warehouse_id'],
            'total_qty' => array_sum(array_map(static fn ($line) => abs((float) $line['qty']), $slots['lines'])),
            'item' => count($slots['lines']), 'note' => $slots['reason'], 'created_at' => $slots['business_date'],
        ]);
        $numbers->assign($reservation, $adjustment);
        $lines = [];
        foreach ($slots['lines'] as $line) {
            $lines[] = StockLine::fromArray($line);
            ProductAdjustment::forceCreate([
                'company_id' => $context->companyId, 'adjustment_id' => $adjustment->id,
                'product_id' => $line['product_id'], 'qty' => abs((float) $line['qty']),
                'variant_id' => $line['variant_id'] ?? null, 'action' => LedgerAmount::units($line['qty']) > 0 ? '+' : '-',
            ]);
        }
        $movement = app(InventoryMovementService::class)->adjust(new StockMovementCommand(
            date: $slots['business_date'], lines: $lines, warehouseId: (int) $slots['warehouse_id'],
            sourceType: 'ai_adjustment', sourceId: $adjustment->id, sourceNo: $adjustment->reference_no,
            idempotencyKey: $key, reason: $slots['reason'], userId: $actor, context: $context,
        ));
        $value = LedgerAmount::units($movement->lines()->sum('value'));
        if ($value !== 0) {
            $accounts = app(PostingAccounts::class);
            $inventory = $accounts->account('inventory', $context, $actor);
            $variance = $accounts->account('variance', $context, $actor);
            $amount = LedgerAmount::decimal(abs($value));
            app(AccountingPostingService::class)->postJournalEntry([
                'entry_date' => $slots['business_date'], 'reference_type' => 'adjustment', 'reference_id' => $adjustment->id,
                'reference_no' => $adjustment->reference_no, 'description' => $slots['reason'], 'created_by' => $actor,
                'posting_key' => $key, 'idempotency_key' => $key,
            ], [
                ['chart_of_account_id' => $value > 0 ? $inventory : $variance, 'debit' => $amount],
                ['chart_of_account_id' => $value > 0 ? $variance : $inventory, 'credit' => $amount],
            ], $context);
        }
        app(OperationPosting::class)->audit('ai_stock_adjustment', $adjustment->id, $context, $actor,
            ['stock_movement_id' => $movement->id, 'movement_value' => LedgerAmount::decimal($value), 'reason' => $slots['reason']]);
        return $adjustment;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
