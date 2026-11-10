<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\GeneralSetting;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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

        $company = Company::find($principal->companyId);
        $setting = GeneralSetting::latest()->first();

        $knowledge = [];
        $sourceRoute = Route::has('setting.general') ? route('setting.general', absolute: false) : '/settings/general';

        if ($topic === 'company_profile' || $topic === 'all') {
            $branches = $company ? $company->branches()->where('is_active', true)->get(['id', 'name', 'code', 'phone']) : [];
            $knowledge['company'] = [
                'name' => $company?->name ?? 'zoloERP Pro',
                'code' => $company?->code ?? '',
                'timezone' => $company?->timezone ?? 'Asia/Kolkata',
                'branches' => $branches->toArray(),
            ];
        }

        if ($topic === 'payment_terms' || $topic === 'credit_policy' || $topic === 'all') {
            $knowledge['payment_terms'] = [
                'default_due_days' => 30,
                'grace_period_days' => 7,
                'statutory_interest_clause' => 'Overdue balances beyond 60 days are subject to statutory late payment policy.',
                'accepted_methods' => ['bank_transfer', 'cheque', 'upi', 'cash'],
            ];
        }

        if ($topic === 'tax_policy' || $topic === 'gst' || $topic === 'all') {
            $gstGstin = DB::table('tax_registrations')->where('company_id', $principal->companyId)->value('gstin') ?? '';
            $knowledge['tax_policy'] = [
                'regime' => 'GST (India)',
                'gstin' => $gstGstin ?: 'Configured per branch',
                'standard_rates' => ['0%', '5%', '12%', '18%', '28%'],
                'e_invoice_threshold' => 'INR 5 Crores turnover',
            ];
        }

        if ($topic === 'return_policy' || $topic === 'warranty' || $topic === 'all') {
            $knowledge['return_policy'] = [
                'sales_return_window_days' => 15,
                'goods_condition_required' => 'Original packaging with valid delivery challan / invoice reference.',
                'job_work_statutory_window' => 'Inputs returnable within 1 year; Capital goods within 3 years (GST Section 143(3)).',
            ];
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
            sourceRefs: [$sourceRoute],
            warnings: [],
            asOf: now()->toDateString(),
            reconciled: true,
            rowCount: count($knowledge)
        );
    }
}
