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
use App\Models\Operations\JobWorkDispatch;
use App\Models\Operations\JobWorkOrder;
use App\Models\Operations\JobWorkReceipt;
use App\Models\Product;
use App\Services\Commercial\CommercialPricing;
use App\Services\JobWork\JobWorkService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bills actual processed receipts using the existing job-work and commercial posting owners. */
final class JobWorkServiceBillActionAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(
        ActionDefinition $definition, array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $context = $this->context($context);
        $date = (string) $slots['business_date'];
        app(CompanyContextResolver::class)->assertPostingDate($context, $date);
        $receipt = JobWorkReceipt::visibleIn($context)
            ->where('financial_year_id', $context->financialYearId)
            ->find((int) $slots['receipt_id']);
        if (!$receipt || !$receipt->posted_at || $receipt->reversed_at || $receipt->service_purchase_id
            || ($receipt->receipt_kind ?? 'process') === 'material_return') {
            throw ValidationException::withMessages(['receipt_id' => 'Select an actual posted, unbilled processed job-work receipt.']);
        }
        if ($date < $receipt->business_date) {
            throw ValidationException::withMessages(['business_date' => 'Supplier bill cannot predate processed receipt.']);
        }
        $dispatch = JobWorkDispatch::visibleIn($context)
            ->where('financial_year_id', $context->financialYearId)->find($receipt->dispatch_id);
        $order = $dispatch ? JobWorkOrder::visibleIn($context)
            ->where('financial_year_id', $context->financialYearId)->find($dispatch->job_work_order_id) : null;
        if (!$dispatch || !$order || $dispatch->reversed_at || $order->reversed_at) {
            throw ValidationException::withMessages(['receipt_id' => 'Job order/dispatch evidence is unavailable or reversed.']);
        }
        $ids = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['product_id'], $slots['items'])));
        if (in_array(0, $ids, true)) {
            throw ValidationException::withMessages(['items' => 'Choose a service catalog item.']);
        }
        $products = Product::forCompany($context)->whereIn('id', $ids)
            ->where('type', 'service')->where('is_active', true)->get();
        if ($products->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Processing charges must use active service catalog items.']);
        }
        PurchaseBillPreviewAdapter::assertInvoiceUnique($context, (int) $order->job_worker_party_id,
            (string) $slots['supplier_invoice_no']);

        // Reuse the ERP's pricing and tax service. The model never chooses a GST rate or journal.
        $pricing = app(CommercialPricing::class)->preview([
            'business_date' => $date,
            'supplier_id' => (int) $order->job_worker_party_id,
            'warehouse_id' => (int) $receipt->warehouse_id,
            'items' => $slots['items'],
        ], purchase: true, context: $context);
        $hash = $this->sourceHash($context, $receipt, $dispatch, $order, $ids);
        return new ActionPreviewResult(
            payload: $slots,
            preview: [
                'document_type' => 'Job Work Processing Service Bill',
                'job_work_receipt_id' => (int) $receipt->id,
                'job_work_order' => $order->reference_no,
                'job_work_dispatch' => $dispatch->reference_no,
                'job_worker_supplier_id' => (int) $order->job_worker_party_id,
                'service_items' => $pricing['items'],
                'grand_total' => $pricing['grand_total'],
                'tax_total' => $pricing['total_tax'] ?? null,
                'charge_mode' => $slots['charge_mode'],
                'supplier_invoice_no' => $slots['supplier_invoice_no'],
                'supplier_invoice_date' => $slots['supplier_invoice_date'],
                'inventory_costing' => $slots['charge_mode'] === 'capitalise'
                    ? 'Existing JobWorkCosting allocates eligible charges to inventory/COGS.'
                    : 'Expense treatment requested; review business policy before approval.',
                'approval_required' => true, 'posted' => false,
            ],
            sourceStateHash: $hash,
        );
    }

    public function execute(
        ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId, string $idempotencyKey, string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        $context = $this->context($context);
        return DB::transaction(function () use ($definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash): ActionExecutionReceipt {
            DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->firstOrFail();
            if ($existing = $this->recover($idempotencyKey, $context)) return $existing;
            $receipt = JobWorkReceipt::visibleIn($context)->where('financial_year_id', $context->financialYearId)
                ->whereKey((int) $payload['receipt_id'])->lockForUpdate()->first();
            if (!$receipt) {
                throw ValidationException::withMessages(['receipt_id' => 'Receipt is unavailable in this financial year.']);
            }
            DB::table('job_work_dispatches')->where('company_id', $context->companyId)
                ->where('id', $receipt->dispatch_id)->lockForUpdate()->first();
            $preview = $this->preview($definition, $payload, $context);
            if (!hash_equals($expectedSourceStateHash, $preview->sourceStateHash)) {
                throw ValidationException::withMessages(['source_state' => 'Receipt, job worker or tax context changed. Rebuild the proposal.']);
            }
            $completed = app(JobWorkService::class)->serviceBill(
                (int) $payload['receipt_id'], $payload, $idempotencyKey, $context, $actorId);
            return $this->result($completed);
        }, 3);
    }

    public function recover(
        string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        if (!$context instanceof CompanyContext) return null;
        $record = DB::table('operation_requests')->where('company_id', $context->companyId)
            ->where('key', $idempotencyKey)->where('source_type', 'job_work_bill')->first();
        if (!$record) return null;
        $receipt = JobWorkReceipt::visibleIn($context)->where('financial_year_id', $context->financialYearId)
            ->find((int) $record->source_id);
        return $receipt && $receipt->service_purchase_id ? $this->result($receipt) : null;
    }

    private function result(JobWorkReceipt $receipt): ActionExecutionReceipt
    {
        if (!$receipt->service_purchase_id) {
            throw new \LogicException('Job-work service did not produce a linked supplier bill.');
        }
        return new ActionExecutionReceipt('purchase_bill', (int) $receipt->service_purchase_id, 'completed', [
            'job_work_receipt_id' => (int) $receipt->id,
            'source' => 'JobWorkService::serviceBill',
            'costing' => 'posted_by_authoritative_purchase_and_job_work_services',
        ]);
    }

    private function sourceHash(
        CompanyContext $context, JobWorkReceipt $receipt, JobWorkDispatch $dispatch,
        JobWorkOrder $order, array $ids,
    ): string {
        $taxAndProduct = PurchaseBillPreviewAdapter::computeSourceStateHash(
            $context, (int) $order->job_worker_party_id, (int) $receipt->warehouse_id, $ids, null, null);
        return hash('sha256', json_encode([
            'tax_and_products' => $taxAndProduct,
            'receipt' => $receipt->only(['id', 'dispatch_id', 'posted_at', 'reversed_at', 'service_purchase_id', 'updated_at', 'receipt_kind']),
            'dispatch' => $dispatch->only(['id', 'job_work_order_id', 'posted_at', 'reversed_at', 'updated_at']),
            'order' => $order->only(['id', 'job_worker_party_id', 'posted_at', 'reversed_at', 'updated_at']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function context(CompanyContext|BootstrapSetupPrincipal $context): CompanyContext
    {
        if (!config('ai_business_knowledge.job_work_enabled', false)) {
            throw ValidationException::withMessages(['action' => 'AI job-work billing is disabled.']);
        }
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('Company context required.');
        return $context;
    }
}
