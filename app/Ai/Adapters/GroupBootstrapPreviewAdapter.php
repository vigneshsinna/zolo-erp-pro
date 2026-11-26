<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Models\Company;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Preview adapter for Mode A company group creation (setup.group.create.v1).
 * Computes simulated group code and preview with ZERO database writes.
 */
final class GroupBootstrapPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $name = trim((string) ($slots['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Group name is required.']);
        }

        // Simulated uppercase group code
        $code = isset($slots['code']) && trim((string) $slots['code']) !== ''
            ? strtoupper(trim((string) $slots['code']))
            : $this->generateSimulatedCode($name);

        $companyIds = array_map('intval', (array) ($slots['company_ids'] ?? []));
        $assignedCompanies = [];
        if (!empty($companyIds)) {
            $assignedCompanies = Company::whereIn('id', $companyIds)
                ->whereNull('company_group_id')
                ->get(['id', 'code', 'legal_name'])
                ->toArray();
        }

        $normalizedPayload = [
            'name' => $name,
            'code' => $code,
            'company_ids' => $companyIds,
        ];

        $previewData = [
            'entity' => 'Company Group',
            'name' => $name,
            'code' => $code,
            'assigned_companies_count' => count($assignedCompanies),
            'assigned_companies' => $assignedCompanies,
            'membership' => [
                'admin_role' => 'Installation Administrator',
                'auto_admin_assignment' => true,
            ],
            'policy' => [
                'isolation' => 'Container for tenant companies',
                'cross_company_accounting' => 'Strictly isolated ledgers per company',
            ],
        ];

        // Deterministic source state hash across existing group codes and candidate companies
        $state = [
            'scope' => 'bootstrap_group_creation',
            'code' => $code,
            'existing_groups_count' => DB::table('company_groups')->count(),
            'candidate_companies' => $assignedCompanies,
        ];
        $sourceStateHash = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));

        return new ActionPreviewResult(
            payload: $normalizedPayload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
            warnings: [],
        );
    }

    private function generateSimulatedCode(string $name): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name)));
        if (strlen($base) < 3) {
            $base = 'GRP';
        }
        $candidate = substr($base, 0, 10);

        if (!DB::table('company_groups')->where('code', $candidate)->exists()) {
            return $candidate;
        }

        return substr($candidate, 0, 7) . rand(100, 999);
    }
}

