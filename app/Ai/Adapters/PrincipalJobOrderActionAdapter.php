<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Customer;
use App\Models\Product;
use App\Services\JobWork\PrincipalJobService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrincipalJobOrderActionAdapter implements ActionPreviewAdapterInterface, ActionCommandAdapterInterface
{
    public function preview(
        ActionDefinition $definition, array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $context = $this->context($context);
        app(CompanyContextResolver::class)->assertPostingDate($context, $slots['business_date']);

        $customer = Customer::forCompany($context)->where('is_active', true)->find((int) $slots['customer_id']);
        $service = Product::forCompany($context)->where('type', 'service')->where('is_active', true)
            ->find((int) $slots['service_product_id']);

        if (!$customer || !$service) {
            throw ValidationException::withMessages(['customer_id' => 'Select an active customer and service product in the current company.']);
        }

        $rate = (float) $slots['rate'];
        if ($rate <= 0) {
            throw ValidationException::withMessages(['rate' => 'Rate must be positive.']);
        }

        $maxLossPercent = isset($slots['max_loss_percent']) ? (float) $slots['max_loss_percent'] : 0.0;
        if ($maxLossPercent < 0 || $maxLossPercent > 100) {
            throw ValidationException::withMessages(['max_loss_percent' => 'Maximum loss percent must be between 0 and 100.']);
        }

        return new ActionPreviewResult(
            payload: $slots,
            preview: [
                'document_type' => 'Customer Job Work Order (Inward)',
                'customer' => ['id' => $customer->id, 'name' => $customer->name],
                'service_product' => ['id' => $service->id, 'name' => $service->name],
                'process' => $slots['process'],
                'rate' => number_format($rate, 2, '.', ''),
                'max_loss_percent' => $maxLossPercent,
                'business_date' => $slots['business_date'],
                'inventory_impact' => 'Customer material is NOT owned company stock.',
                'approval_required' => true,
            ],
            sourceStateHash: $this->hash($context, $customer, $service, $rate),
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
                throw ValidationException::withMessages(['source_state' => 'Customer or service product changed. Re-verify proposal.']);
            }

            $orderId = app(PrincipalJobService::class)->order($payload, $context, $actorId);

            DB::table('idempotency_keys')->insert([
                'company_id' => $context->companyId,
                'key' => 'principal-job-order:'.$idempotencyKey,
                'request_hash' => hash('sha256', (string) json_encode($payload)),
                'response_type' => 'principal_job_order',
                'response_ref' => $orderId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return new ActionExecutionReceipt('principal_job_order', $orderId, 'completed', [
                'order_id' => $orderId,
                'customer_id' => $payload['customer_id'],
                'process' => $payload['process'],
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
            ->where('key', 'principal-job-order:'.$idempotencyKey)
            ->where('response_type', 'principal_job_order')
            ->first();

        if (!$record) return null;
        return new ActionExecutionReceipt('principal_job_order', (int) $record->response_ref, 'completed', [
            'order_id' => (int) $record->response_ref,
            'inventory_impact' => 'none_customer_owned',
        ]);
    }

    private function hash(CompanyContext $context, Customer $customer, Product $service, float $rate): string
    {
        return hash('sha256', json_encode([
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'fy_id' => $context->financialYearId,
            'customer' => ['id' => $customer->id, 'active' => $customer->is_active, 'updated' => $customer->updated_at?->timestamp],
            'service' => ['id' => $service->id, 'active' => $service->is_active, 'updated' => $service->updated_at?->timestamp],
            'rate' => $rate,
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
