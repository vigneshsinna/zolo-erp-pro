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
use App\Models\Operations\JobWorkOrder;
use App\Models\Supplier;
use App\Services\JobWork\JobWorkService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class JobWorkOrderActionAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(
        ActionDefinition $definition, array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $context = $this->context($context);
        app(CompanyContextResolver::class)->assertPostingDate($context, $slots['business_date']);
        if (!empty($slots['expected_return_date']) && $slots['expected_return_date'] < $slots['business_date']) {
            throw ValidationException::withMessages(['expected_return_date' => 'Return date cannot precede order date.']);
        }
        $supplier = Supplier::forCompany($context)->where('is_active', true)
            ->find((int) $slots['job_worker_party_id']);
        $process = DB::table('process_types')->where('company_id', $context->companyId)
            ->where('is_active', true)->where('id', $slots['process_type_id'])->first();
        if (!$supplier || !$process) {
            throw ValidationException::withMessages(['job_work_order' => 'Select an active company job worker and processing type.']);
        }
        return new ActionPreviewResult(
            payload: $slots,
            preview: [
                'document_type' => 'Job Work Order',
                'job_worker' => ['id' => $supplier->id, 'name' => $supplier->name],
                'process_type_id' => (int) $process->id,
                'business_date' => $slots['business_date'],
                'expected_return_date' => $slots['expected_return_date'] ?? null,
                'inventory_impact' => 'No stock movement until a separately approved material dispatch.',
                'new_external_location' => true,
                'approval_required' => true,
            ],
            sourceStateHash: $this->hash($context, $supplier, $process),
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
            DB::table('suppliers')->where('company_id', $context->companyId)
                ->where('id', $payload['job_worker_party_id'])->lockForUpdate()->first();
            DB::table('process_types')->where('company_id', $context->companyId)
                ->where('id', $payload['process_type_id'])->lockForUpdate()->first();
            $preview = $this->preview($definition, $payload, $context);
            if (!hash_equals($expectedSourceStateHash, $preview->sourceStateHash)) {
                throw ValidationException::withMessages(['source_state' => 'Job worker or process changed. Review the proposal again.']);
            }
            $order = app(JobWorkService::class)->order($payload, $idempotencyKey, $context, $actorId);
            return $this->receipt($order);
        }, 3);
    }

    public function recover(
        string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        if (!$context instanceof CompanyContext) return null;
        $record = DB::table('operation_requests')
            ->where('company_id', $context->companyId)->where('key', $idempotencyKey)
            ->where('source_type', 'job_work_order')->first();
        if (!$record) return null;
        $order = JobWorkOrder::visibleIn($context)->where('financial_year_id', $context->financialYearId)
            ->find((int) $record->source_id);
        return $order ? $this->receipt($order) : null;
    }

    private function receipt(JobWorkOrder $order): ActionExecutionReceipt
    {
        return new ActionExecutionReceipt('job_work_order', (int) $order->id, 'completed', [
            'reference_no' => $order->reference_no,
            'external_warehouse_id' => $order->external_warehouse_id,
            'inventory_movement' => 'none_until_dispatch',
            'approval_required_for_dispatch' => true,
        ]);
    }

    private function hash(CompanyContext $context, Supplier $supplier, object $process): string
    {
        return hash('sha256', json_encode([
            'scope' => [$context->companyId, $context->branchId, $context->financialYearId],
            'supplier' => $supplier->only(['id', 'name', 'is_active', 'address', 'updated_at']),
            'process' => (array) $process,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function context(CompanyContext|BootstrapSetupPrincipal $context): CompanyContext
    {
        if (!config('ai_business_knowledge.job_work_enabled', false)) {
            throw ValidationException::withMessages(['action' => 'AI job-work execution is disabled.']);
        }
        if (!$context instanceof CompanyContext) {
            throw new \InvalidArgumentException('Company context required for job-work action.');
        }
        return $context;
    }
}
