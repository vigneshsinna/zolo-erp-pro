<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionCommandAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionExecutionReceipt;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyDirectoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Command adapter for Mode A company creation (setup.company.create.v1).
 * Calls CompanyDirectoryService::createCompany exactly once in a single transaction.
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
        // Idempotency: recover existing execution receipt if already completed
        $existing = $this->recover($idempotencyKey, $context);
        if ($existing !== null) {
            return $existing;
        }

        // Revalidate actor installation admin eligibility
        if (!$this->directory->isInstallationAdmin($actorId)) {
            throw new AuthorizationException('Installation administrator authority required to execute company bootstrap.');
        }

        // Execute company provisioning through authoritative ERP service
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
            ->where('status', 'completed')
            ->first();

        if (!$row) {
            return null;
        }

        $result = json_decode($row->result_json, true, flags: JSON_THROW_ON_ERROR);

        return new ActionExecutionReceipt(
            resourceType: (string) ($row->erp_resource_type ?? $result['resource_type'] ?? 'company'),
            resourceId: (int) ($row->erp_resource_id ?? $result['resource_id'] ?? 0),
            status: 'completed',
            details: (array) ($result['details'] ?? []),
        );
    }
}

