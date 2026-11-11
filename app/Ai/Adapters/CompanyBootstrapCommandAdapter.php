<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Company;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Command adapter for Mode A company creation (setup.company.create.v1).
 * Calls CompanyDirectoryService::createCompany exactly once in a single transaction.
 * Includes safe ERP domain reconciliation for companies committed before AI receipts are saved.
 */
final class CompanyBootstrapCommandAdapter implements ActionCommandAdapterInterface
{
    public function __construct(
        private readonly CompanyDirectoryService $directory,
    ) {}

    public function execute(
        ActionDefinition $definition,
        array $payload,
        CompanyContext|BootstrapSetupPrincipal $context,
        int $actorId,
        string $idempotencyKey,
        string $expectedSourceStateHash,
    ): ActionExecutionReceipt {
        // 1. Idempotency recovery: return existing execution receipt if already completed
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        // 2. Safe Domain Reconciliation: Check if company was already committed to the ERP database
        // (e.g. process died or connection timed out after CompanyDirectoryService committed but before AI receipt was saved)
        $reconciled = $this->reconcileCommittedCompany($payload, $actorId);
        if ($reconciled !== null) {
            return $reconciled;
        }

        // 3. Revalidate actor installation admin eligibility
        if (!$this->directory->isInstallationAdmin($actorId)) {
            throw new AuthorizationException('Installation administrator authority required to execute company bootstrap.');
        }

        // 4. Execute company provisioning through authoritative ERP service
        // CompanyDirectoryService::createCompany encapsulates its own DB::transaction
        $company = $this->directory->createCompany($actorId, $payload);

        $branch = $company->branches()->where('code', 'MAIN')->first();
        $year = $company->fiscalYears()->first();

        return new ActionExecutionReceipt(
            resourceType: 'company',
            resourceId: (int) $company->id,
            status: 'completed',
            details: [
                'company_id' => (int) $company->id,
                'code' => $company->code,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'main_branch_id' => $branch?->id,
                'fiscal_year_id' => $year?->id,
                'transition_action' => 'switch_context',
                'context_switch_url' => '/select-company',
                'workspace_url' => '/ai',
                'provisioned_resources' => [
                    'company',
                    'main_branch',
                    'first_fiscal_year',
                    'main_warehouse',
                    'chart_of_accounts',
                    'industry_settings',
                    'starter_masters',
                    'starter_series',
                ],
            ],
        );
    }

    public function recover(
        string $idempotencyKey,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ?ActionExecutionReceipt {
        $row = DB::table('ai_action_executions')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (!$row) {
            return null;
        }

        if ($row->status === 'completed') {
            $result = json_decode((string) $row->result_json, true, flags: JSON_THROW_ON_ERROR);

            return new ActionExecutionReceipt(
                resourceType: (string) ($row->erp_resource_type ?? $result['resource_type'] ?? 'company'),
                resourceId: (int) ($row->erp_resource_id ?? $result['resource_id'] ?? 0),
                status: 'completed',
                details: (array) ($result['details'] ?? []),
            );
        }

        // If the execution was recorded as executing or uncertain, check if the company was created
        if (!empty($row->company_id)) {
            $company = Company::find($row->company_id);
            if ($company) {
                return $this->buildReceiptFromCompany($company, isReconciled: true);
            }
        }

        return null;
    }

    /**
     * Safely reconcile a company that was committed to the ERP database prior to an AI crash.
     */
    public function reconcileCommittedCompany(array $payload, int $actorId): ?ActionExecutionReceipt
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $legalName = trim((string) ($payload['legal_name'] ?? ''));

        $company = null;
        if (!empty($code)) {
            $company = Company::where('code', $code)->first();
        }

        if (!$company && !empty($legalName)) {
            $company = Company::where('legal_name', $legalName)->latest('id')->first();
        }

        if (!$company) {
            return null;
        }

        // Verify the company is actually administered by this actor and was created via setup audit
        $hasMembership = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('user_id', $actorId)
            ->exists();

        $hasSetupAudit = DB::table('company_setup_audits')
            ->where('company_id', $company->id)
            ->where('action', 'company_created')
            ->where('actor_id', $actorId)
            ->exists();

        if ($hasMembership && $hasSetupAudit) {
            return $this->buildReceiptFromCompany($company, isReconciled: true);
        }

        return null;
    }

    private function buildReceiptFromCompany(Company $company, bool $isReconciled = false): ActionExecutionReceipt
    {
        $branch = $company->branches()->where('code', 'MAIN')->first();
        $year = $company->fiscalYears()->first();

        return new ActionExecutionReceipt(
            resourceType: 'company',
            resourceId: (int) $company->id,
            status: 'completed',
            details: [
                'company_id' => (int) $company->id,
                'code' => $company->code,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'main_branch_id' => $branch?->id,
                'fiscal_year_id' => $year?->id,
                'transition_action' => 'switch_context',
                'context_switch_url' => '/select-company',
                'workspace_url' => '/ai',
                'reconciled_from_erp' => $isReconciled,
                'provisioned_resources' => [
                    'company',
                    'main_branch',
                    'first_fiscal_year',
                    'main_warehouse',
                    'chart_of_accounts',
                    'industry_settings',
                    'starter_masters',
                    'starter_series',
                ],
            ],
        );
    }
}
