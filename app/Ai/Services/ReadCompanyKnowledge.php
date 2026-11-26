<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Services\Platform\CompanyContext;
use App\Services\Tax\GstSetupService;

/**
 * Authoritative read facade for Company Knowledge, Policies, and Settings (P4).
 * Grounds Copilot answers in verified company configurations and terms.
 */
class ReadCompanyKnowledge
{
    public function execute(
        AiPrincipal $principal,
        string $topic = 'company_profile',
        ?string $query = null
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        $company = Company::findOrFail($principal->companyId);
        $knowledge = [];
        $warnings = [];

        if (in_array($topic, ['company_profile', 'all'], true)) {
            $knowledge['company'] = [
                'name' => $company->trade_name ?: $company->legal_name,
                'code' => $company->code,
                'timezone' => $company->timezone,
                'branches' => $company->branches()->whereKey($principal->branchId)->where('is_active', true)
                    ->get(['id', 'name', 'code'])->toArray(),
            ];
        }

        if (in_array($topic, ['tax_policy', 'gst', 'all'], true)) {
            $registration = app(GstSetupService::class)->registration($context);
            if ($registration !== null) {
                $knowledge['tax_policy'] = [
                    'gstin' => $registration->gstin,
                    'registration_type' => $registration->registration_type,
                ];
            } else {
                $warnings[] = 'No active GST registration was found for this branch.';
            }
        }

        if (in_array($topic, ['return_policy', 'all'], true)) {
            $policy = $company->settings_json['return_policy'] ?? null;
            if (isset($policy['days'])) {
                $knowledge['return_policy'] = ['approval_window_days' => $policy['days']];
            } else {
                $warnings[] = 'No return approval window is configured.';
            }
        }

        if (in_array($topic, ['payment_terms', 'credit_policy', 'warranty', 'all'], true)) {
            $warnings[] = 'No verified company-wide payment, credit or warranty terms are available for this query.';
        }

        return ErpToolResult::ok(
            toolKey: 'knowledge.query.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'topic' => $topic,
                'knowledge' => $knowledge,
            ],
            sourceRefs: [],
            warnings: $warnings,
            asOf: now()->toDateString(),
            reconciled: true,
            rowCount: count($knowledge)
        );
    }
}
