<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductImportCommandAdapter implements ActionCommandAdapterInterface
{
    public function execute(ActionDefinition $definition, array $payload, CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId, string $idempotencyKey, string $expectedSourceStateHash): ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) {
            throw new \InvalidArgumentException('Product import requires company context.');
        }
        if ($receipt = $this->recover($idempotencyKey, $context)) {
            return $receipt;
        }
        $principal = new AiPrincipal($actorId, $context->companyId, $context->branchId, $context->financialYearId);
        $planner = app(ProductImportPlanner::class);
        $preview = $planner->show($principal, $payload['batch_id']);
        if (!hash_equals($expectedSourceStateHash, hash('sha256', $preview['review_hash']))) {
            throw ValidationException::withMessages(['source_state' => 'Import source changed. Rebuild the preview.']);
        }
        DB::transaction(function () use ($principal, $payload, $idempotencyKey): void {
            $batch = DB::table('ai_import_batches')->where('company_id', $principal->companyId)
                ->where('actor_id', $principal->actorId)->where('id', $payload['batch_id'])->lockForUpdate()->first();
            if (!$batch || ($batch->execution_key && $batch->execution_key !== $idempotencyKey)) {
                throw ValidationException::withMessages(['batch_id' => 'Import batch is already bound to another execution.']);
            }
            DB::table('ai_import_batches')->where('company_id', $principal->companyId)->where('id', $batch->id)
                ->update(['execution_key' => $idempotencyKey]);
        });
        $result = $planner->commit($principal, $payload['batch_id'], $payload['accepted_rows'], $payload['review_hash']);
        return $this->receipt($result);
    }

    public function recover(string $idempotencyKey, CompanyContext|BootstrapSetupPrincipal $context): ?ActionExecutionReceipt
    {
        if (!$context instanceof CompanyContext) {
            return null;
        }
        $batch = DB::table('ai_import_batches')->where('company_id', $context->companyId)->where('branch_id', $context->branchId)
            ->where('financial_year_id', $context->financialYearId)->where('execution_key', $idempotencyKey)
            ->whereIn('status', ['completed', 'partial'])->first();
        if (!$batch) {
            return null;
        }
        return $this->receipt(app(ProductImportPlanner::class)->show(
            new AiPrincipal((int) $batch->actor_id, $context->companyId, $context->branchId, $context->financialYearId), $batch->id));
    }

    private function receipt(array $result): ActionExecutionReceipt
    {
        $importId = (int) ($result['rows'][0]['import_id'] ?? 0);
        return new ActionExecutionReceipt('product_import', $importId, $result['status'], [
            'batch_id' => $result['id'], 'source_hash' => $result['source_hash'], 'receipts' => $result['receipts'],
            'summary' => $result['status'] === 'completed' ? 'Selected product rows imported.' : 'Import partially completed. Review failed rows before resuming.',
        ]);
    }
}
