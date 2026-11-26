<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\JobWork\PrincipalJobService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrincipalJobReturnActionAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(
        ActionDefinition $definition, array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $context = $this->context($context);
        app(CompanyContextResolver::class)->assertPostingDate($context, $slots['business_date']);

        $orderId = (int) $slots['order_id'];
        $order = DB::table('principal_job_orders')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)
            ->where('id', $orderId)
            ->first();

        if (!$order) {
            throw ValidationException::withMessages(['order_id' => 'Principal job order not found in current company/branch/FY.']);
        }

        $balance = app(PrincipalJobService::class)->balance($orderId);
        $withUs = (float) $balance['with_us'];

        $lines = $slots['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'At least one return material line is required.']);
        }

        $totalReturnQty = 0.0;
        $totalLossQty = 0.0;
        foreach ($lines as $i => $line) {
            $qty = (float) ($line['qty'] ?? 0);
            $loss = (float) ($line['loss_qty'] ?? 0);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => 'Returned quantity must be positive.']);
            }
            if ($loss < 0) {
                throw ValidationException::withMessages(["lines.{$i}.loss_qty" => 'Loss quantity cannot be negative.']);
            }
            $totalReturnQty += $qty;
            $totalLossQty += $loss;
        }

        $totalSettling = $totalReturnQty + $totalLossQty;
        if ($totalSettling > $withUs + 0.00005) {
            throw ValidationException::withMessages(['lines' => "Total returned ({$totalSettling}) cannot exceed remaining material with us ({$withUs})."]);
        }

        $maxLossPercent = (float) ($order->max_loss_percent ?? 0);
        if ($maxLossPercent > 0 && $totalSettling > 0) {
            $lossPercent = ($totalLossQty / $totalSettling) * 100;
            if ($lossPercent > $maxLossPercent + 0.0001) {
                throw ValidationException::withMessages(['lines' => "Loss percentage ({$lossPercent}%) exceeds permitted maximum ({$maxLossPercent}%)."]);
            }
        }

        return new ActionPreviewResult(
            payload: $slots,
            preview: [
                'document_type' => 'Customer Material Return Challan (Inward Job Work)',
                'order_id' => $orderId,
                'customer_id' => (int) $order->customer_id,
                'business_date' => $slots['business_date'],
                'total_returned_qty' => $totalReturnQty,
                'total_loss_qty' => $totalLossQty,
                'remaining_with_us' => (float) max(0.0, $withUs - $totalSettling),
                'inventory_impact' => 'Customer material returned under Delivery Challan (Rule 55).',
                'approval_required' => true,
            ],
            sourceStateHash: $this->hash($context, $order, $balance, $slots),
        );
    }

    public function execute(
        ActionDefinition $definition, array $payload,
        CompanyContext|BootstrapSetupPrincipal $context, int $actorId,
        string $idempotencyKey, string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        $context = $this->context($context);
        return DB::transaction(function () use ($definition, $payload, $context, $actorId, $idempotencyKey, $expectedSourceStateHash): ActionExecutionReceipt {
            DB::table('companies')->where('id', $context->companyId)->lockForUpdate()->firstOrFail();
            if ($existing = $this->recover($idempotencyKey, $context)) return $existing;

            $preview = $this->preview($definition, $payload, $context);
            if (!hash_equals($expectedSourceStateHash, $preview->sourceStateHash)) {
                throw ValidationException::withMessages(['source_state' => 'Principal job material balance changed. Re-verify proposal.']);
            }

            $orderId = (int) $payload['order_id'];
            app(PrincipalJobService::class)->returnGoods($orderId, $payload, $context, $actorId);

            DB::table('idempotency_keys')->insert([
                'company_id' => $context->companyId,
                'key' => 'principal-job-return:'.$idempotencyKey,
                'request_hash' => hash('sha256', (string) json_encode($payload)),
                'response_type' => 'principal_job_return',
                'response_ref' => $orderId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return new ActionExecutionReceipt('principal_job_return', $orderId, 'completed', [
                'order_id' => $orderId,
                'returned_qty' => $preview->preview['total_returned_qty'],
                'loss_qty' => $preview->preview['total_loss_qty'],
                'inventory_impact' => 'none_customer_owned',
            ]);
        }, 3);
    }

    public function recover(
        string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        if (!$context instanceof CompanyContext) return null;
        $record = DB::table('idempotency_keys')
            ->where('company_id', $context->companyId)
            ->where('key', 'principal-job-return:'.$idempotencyKey)
            ->where('response_type', 'principal_job_return')
            ->first();

        if (!$record) return null;
        return new ActionExecutionReceipt('principal_job_return', (int) $record->response_ref, 'completed', [
            'order_id' => (int) $record->response_ref,
            'inventory_impact' => 'none_customer_owned',
        ]);
    }

    private function hash(CompanyContext $context, object $order, array $balance, array $slots): string
    {
        return hash('sha256', json_encode([
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'fy_id' => $context->financialYearId,
            'order_id' => $order->id,
            'balance' => $balance,
            'lines' => $slots['lines'],
        ], JSON_THROW_ON_ERROR));
    }

    private function context(CompanyContext|BootstrapSetupPrincipal $context): CompanyContext
    {
        if (!config('ai_business_knowledge.job_work_enabled', false)) {
            throw ValidationException::withMessages(['action' => 'AI customer job work is disabled.']);
        }
        if (!$context instanceof CompanyContext) throw new \InvalidArgumentException('Company context required.');
        return $context;
    }
}
