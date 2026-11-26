<?php

declare(strict_types=1);

namespace App\Ai\Knowledge;

use App\Services\Industry\IndustryCatalog;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative, fail-closed industry profile and branch role resolver for AI & Optical capabilities.
 * Never uses general_settings heuristics or cross-company inference.
 */
final class IndustryContextResolver
{
    /**
     * @return array{
     *     company_id: int,
     *     branch_id: int,
     *     profile: string,
     *     subtype: string,
     *     branch_role: ?string,
     *     is_optical: bool,
     *     has_lab_capability: bool,
     *     profile_revision_hash: string,
     *     settings: array<string, mixed>
     * }
     */
    public function resolve(CompanyContext $context): array
    {
        $profile = 'general_trading';
        $subtype = 'trading';
        $settings = IndustryCatalog::SETTINGS['general_trading'];
        $updatedAt = null;

        if (Schema::hasTable('company_industry_settings')) {
            $row = DB::table('company_industry_settings')
                ->where('company_id', $context->companyId)
                ->first();

            if ($row && !empty($row->profile_key)) {
                $profile = (string) $row->profile_key;
                $subtype = (string) ($row->subtype ?: array_key_first(IndustryCatalog::SUBTYPES[$profile] ?? ['trading' => []]));
                $decoded = $row->settings_json ? json_decode((string) $row->settings_json, true) : null;
                $settings = is_array($decoded) ? $decoded : IndustryCatalog::settings($profile, $subtype);
                $updatedAt = $row->updated_at ?? null;
            }
        }

        $branchRole = null;
        $branchRevision = 0;
        if (Schema::hasTable('company_branch_industry_roles')) {
            $branchRow = DB::table('company_branch_industry_roles')
                ->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('is_active', true)
                ->first();

            if ($branchRow) {
                $branchRole = (string) $branchRow->role_key;
                $branchRevision = (int) $branchRow->revision;
                if (!empty($branchRow->settings_json)) {
                    $branchSettings = json_decode((string) $branchRow->settings_json, true);
                    if (is_array($branchSettings)) {
                        $settings = array_replace_recursive($settings, $branchSettings);
                    }
                }
            }
        }

        $isOptical = in_array($profile, ['opticals', 'optical'], true);
        $hasLabCapability = $isOptical
            && (bool) config('ai_business_knowledge.opticals_lab_enabled', false)
            && ($subtype === 'lens_manufacturing' || $branchRole === 'rx_lab');

        $revisionPayload = [
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'profile' => $profile,
            'subtype' => $subtype,
            'branch_role' => $branchRole,
            'branch_revision' => $branchRevision,
            'updated_at' => $updatedAt,
        ];

        return [
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'profile' => $profile,
            'subtype' => $subtype,
            'branch_role' => $branchRole,
            'is_optical' => $isOptical,
            'has_lab_capability' => $hasLabCapability,
            'profile_revision_hash' => hash('sha256', json_encode($revisionPayload, JSON_THROW_ON_ERROR)),
            'settings' => $settings,
        ];
    }

    public function isOptical(CompanyContext $context): bool
    {
        return $this->resolve($context)['is_optical'];
    }

    public function hasLabCapability(CompanyContext $context): bool
    {
        return $this->resolve($context)['has_lab_capability'];
    }
}
