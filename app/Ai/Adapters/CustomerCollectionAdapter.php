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
use App\Models\Accounting\AccountOpenItem;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\CollectionRecord;
use App\Models\Accounting\JournalEntry;
use App\Models\Customer;
use App\Services\Accounting\CollectionService;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\VoucherService;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use App\Support\LedgerAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Draft reminders never send messages. A receipt posts and allocates only after sealed approval. */
final class CustomerCollectionAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(ActionDefinition $definition, array $slots, CompanyContext|BootstrapSetupPrincipal $context): ActionPreviewResult
    {
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('A company context is required.');
        app(CompanyContextResolver::class)->assertPostingDate($context, $slots['date']);
        $customer = Customer::where('company_id', $context->companyId)->where('is_active', true)->find($slots['customer_id']);
        $item = AccountOpenItem::where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('party_type', 'customer')->where('party_id', $slots['customer_id'])->find($slots['open_item_id']);
        if (!$customer || !$item || LedgerAmount::units($item->original_amount) <= 0) {
            throw ValidationException::withMessages(['open_item_id' => 'Select an open customer invoice in the selected company and branch.']);
        }
        $report = app(FinancialReportService::class)->ageing($context, 'customer', $slots['date'], ['party_id' => $customer->id]);
        $row = collect($report['rows'])->first(fn ($row) => (int) $row['item']->id === (int) $item->id);
        $open = LedgerAmount::units($row['open_amount'] ?? 0);
        if ($open <= 0) throw ValidationException::withMessages(['open_item_id' => 'This invoice has no open amount on the selected date.']);
        $payload = ['customer_id' => (int) $customer->id, 'open_item_id' => (int) $item->id,
            'date' => $slots['date'], 'note' => $slots['note'] ?? 'Customer collection'];
        $preview = ['customer_name' => $customer->name, 'invoice_reference' => $item->document_no,
            'open_amount' => LedgerAmount::decimal($open), 'external_sending' => 'none'];
        if ($definition->key === 'payment_receipt.create.v1') {
            $amount = LedgerAmount::units($slots['amount'] ?? 0);
            $account = ChartOfAccount::where('company_id', $context->companyId)->where('is_active', true)
                ->whereIn('control_type', ['cash', 'bank'])->find($slots['settlement_account_id'] ?? 0);
            if (!$account || $amount <= 0 || $amount > $open) {
                throw ValidationException::withMessages(['amount' => 'Select a cash/bank account and a positive receipt within the invoice balance.']);
            }
            $payload += ['amount' => LedgerAmount::decimal($amount), 'settlement_account_id' => (int) $account->id];
            $preview += ['document_type' => 'Customer Payment Receipt', 'amount' => $payload['amount'], 'settlement_account' => $account->name];
        } else {
            $preview += ['document_type' => 'Payment Reminder Draft',
                'draft_text' => 'Payment reminder for '.$item->document_no.': open balance '.LedgerAmount::decimal($open).'.',
                'policy' => 'Creates an internal reminder plan. Review and send through the ERP communication workflow.'];
        }
        return new ActionPreviewResult($payload, $preview, $this->hash($payload, $context));
    }

    public function execute(ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context, int $actorId, string $idempotencyKey, string $expectedSourceStateHash): ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('A company context is required.');
        $context = app(CompanyContextResolver::class)->forActor($context, $actorId);
        return DB::transaction(function () use ($definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash) {
            app(CompanyWriteGuard::class)->begin($context, $payload['date']);
            if ($receipt = $this->recover($idempotencyKey, $context)) return $receipt;
            DB::table('account_open_items')->where('company_id', $context->companyId)->where('id', $payload['open_item_id'])->lockForUpdate()->first();
            if (!hash_equals($expectedSourceStateHash, $this->hash($payload, $context))) {
                throw ValidationException::withMessages(['source_state' => 'Invoice balance or settlement account changed. Prepare a new proposal.']);
            }
            $fresh = $this->preview($definition, $payload, $context);
            if ($definition->key === 'payment_receipt.create.v1') {
                $item = AccountOpenItem::where('company_id', $context->companyId)->findOrFail($payload['open_item_id']);
                $resource = app(VoucherService::class)->post([
                    'voucher_type' => 'receipt', 'entry_date' => $payload['date'], 'description' => $payload['note'],
                    'idempotency_key' => hash('sha256', $idempotencyKey), 'reference_mode' => 'against_reference',
                    'items' => [
                        ['chart_of_account_id' => $payload['settlement_account_id'], 'debit' => $payload['amount'], 'credit' => '0'],
                        ['chart_of_account_id' => $item->account_id, 'debit' => '0', 'credit' => $payload['amount'],
                            'partner_type' => 'customer', 'partner_id' => $payload['customer_id']],
                    ],
                    'allocations' => [['open_item_id' => $item->id, 'line_index' => 1, 'amount' => $payload['amount']]],
                ], $context);
                $type = 'payment_receipt';
            } else {
                $resource = app(CollectionService::class)->save([
                    'open_item_id' => $payload['open_item_id'], 'kind' => 'reminder', 'status' => 'planned',
                    'action_date' => $payload['date'], 'notes' => $fresh->preview['draft_text'],
                    'idempotency_key' => hash('sha256', $idempotencyKey),
                ], $context);
                $type = 'payment_reminder';
            }
            DB::table('idempotency_keys')->insert(['company_id' => $context->companyId, 'key' => $idempotencyKey,
                'request_hash' => CanonicalActionPayload::hash($payload), 'response_type' => $type, 'response_ref' => $resource->id,
                'created_at' => now(), 'updated_at' => now()]);
            return new ActionExecutionReceipt($type, (int) $resource->id, 'completed', ['external_sending' => 'none']);
        });
    }

    public function recover(string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context): ?ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) return null;
        $row = DB::table('idempotency_keys')->where('company_id', $context->companyId)->where('key', $idempotencyKey)->first();
        if (!$row || !in_array($row->response_type, ['payment_receipt', 'payment_reminder'], true)) return null;
        $class = $row->response_type === 'payment_receipt' ? JournalEntry::class : CollectionRecord::class;
        $exists = $class::where('company_id', $context->companyId)->where('branch_id', $context->branchId)->whereKey($row->response_ref)->exists();
        return $exists ? new ActionExecutionReceipt($row->response_type, (int) $row->response_ref, 'completed', ['external_sending' => 'none']) : null;
    }

    private function hash(array $payload, CompanyContext $context): string
    {
        $state = [];
        foreach (['account_open_items' => ['id' => $payload['open_item_id']], 'customers' => ['id' => $payload['customer_id']],
            'fiscal_years' => ['id' => $context->financialYearId],
            'chart_of_accounts' => []] as $table => $where) {
            $q = DB::table($table)->where('company_id', $context->companyId)->where($where)->orderBy('id');
            if ($table === 'chart_of_accounts') $q->whereIn('id', array_filter([$payload['settlement_account_id'] ?? null,
                DB::table('account_open_items')->where('company_id', $context->companyId)->where('id', $payload['open_item_id'])->value('account_id')]));
            $state[$table] = $q->get()->map(fn ($row) => (array) $row)->all();
        }
        $report = app(FinancialReportService::class)->ageing($context, 'customer', $payload['date'], ['party_id' => $payload['customer_id']]);
        $row = collect($report['rows'])->first(fn ($row) => (int) $row['item']->id === $payload['open_item_id']);
        $state['open_amount'] = (string) ($row['open_amount'] ?? 0);
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
