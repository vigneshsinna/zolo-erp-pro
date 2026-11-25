<?php

declare(strict_types=1);

namespace App\Services\Optical;

use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluates multi-store transfer authorization and GST registration boundaries.
 * Distinct GST registrations across branches cannot use simple internal stock transfers.
 */
final class OpticalTransferPolicyService
{
    /**
     * @return array{
     *     allowed: bool,
     *     transfer_mode: string,
     *     reason: ?string,
     *     source_branch_id: ?int,
     *     destination_branch_id: ?int,
     *     source_gstin: ?string,
     *     destination_gstin: ?string
     * }
     */
    public function evaluate(
        CompanyContext $context,
        int $actorId,
        int $sourceWarehouseId,
        int $destinationWarehouseId,
    ): array {
        $source = Warehouse::where('company_id', $context->companyId)->where('is_active', true)->find($sourceWarehouseId);
        $destination = Warehouse::where('company_id', $context->companyId)->where('is_active', true)->find($destinationWarehouseId);

        if (!$source || !$destination) {
            return [
                'allowed' => false,
                'transfer_mode' => 'blocked',
                'reason' => 'unauthorized_or_inactive_warehouse',
                'source_branch_id' => $source?->branch_id ? (int) $source->branch_id : null,
                'destination_branch_id' => $destination?->branch_id ? (int) $destination->branch_id : null,
                'source_gstin' => null,
                'destination_gstin' => null,
            ];
        }

        if ($source->id === $destination->id) {
            return [
                'allowed' => false,
                'transfer_mode' => 'blocked',
                'reason' => 'same_source_and_destination',
                'source_branch_id' => $source->branch_id ? (int) $source->branch_id : null,
                'destination_branch_id' => $destination->branch_id ? (int) $destination->branch_id : null,
                'source_gstin' => null,
                'destination_gstin' => null,
            ];
        }

        $srcBranch = $source->branch_id ? (int) $source->branch_id : $context->branchId;
        $dstBranch = $destination->branch_id ? (int) $destination->branch_id : $context->branchId;

        $authorizedBranches = $this->authorizedBranches($context, $actorId);
        if (!in_array($srcBranch, $authorizedBranches, true) || !in_array($dstBranch, $authorizedBranches, true)) {
            return [
                'allowed' => false,
                'transfer_mode' => 'blocked',
                'reason' => 'unauthorized_branch_transfer',
                'source_branch_id' => $srcBranch,
                'destination_branch_id' => $dstBranch,
                'source_gstin' => null,
                'destination_gstin' => null,
            ];
        }

        if ($srcBranch === $dstBranch) {
            return [
                'allowed' => true,
                'transfer_mode' => 'intra_branch_transfer',
                'reason' => null,
                'source_branch_id' => $srcBranch,
                'destination_branch_id' => $dstBranch,
                'source_gstin' => null,
                'destination_gstin' => null,
            ];
        }

        $srcGstin = $this->activeBranchGstin($context->companyId, $srcBranch);
        $dstGstin = $this->activeBranchGstin($context->companyId, $dstBranch);

        if ($srcGstin !== null && $dstGstin !== null && strtoupper($srcGstin) !== strtoupper($dstGstin)) {
            return [
                'allowed' => false,
                'transfer_mode' => 'tax_route_required',
                'reason' => 'cross_gst_registration_requires_tax_route',
                'source_branch_id' => $srcBranch,
                'destination_branch_id' => $dstBranch,
                'source_gstin' => $srcGstin,
                'destination_gstin' => $dstGstin,
            ];
        }

        return [
            'allowed' => true,
            'transfer_mode' => 'internal_same_gstin',
            'reason' => null,
            'source_branch_id' => $srcBranch,
            'destination_branch_id' => $dstBranch,
            'source_gstin' => $srcGstin,
            'destination_gstin' => $dstGstin,
        ];
    }

    /** @return list<int> */
    public function authorizedBranches(CompanyContext $context, int $actorId): array
    {
        $branches = [$context->branchId];
        if (config('ai_business_knowledge.opticals_cross_store_enabled', false)
            && Schema::hasTable('company_user_branches')) {
            $assigned = DB::table('company_user_branches')
                ->where('company_id', $context->companyId)
                ->where('user_id', $actorId)
                ->pluck('branch_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $branches = array_values(array_unique([...$branches, ...$assigned]));
        }

        return $branches;
    }

    private function activeBranchGstin(int $companyId, int $branchId): ?string
    {
        if (!Schema::hasTable('tax_registrations')) {
            return null;
        }
        $gstin = DB::table('tax_registrations')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->value('gstin');

        return $gstin ? trim((string) $gstin) : null;
    }
}
