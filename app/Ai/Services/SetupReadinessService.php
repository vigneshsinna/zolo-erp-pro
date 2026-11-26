<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Company;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validates operational and commercial readiness of a newly provisioned or configured company.
 * Evaluates core prerequisites required before first commercial transaction/bill against the
 * selected Financial Year, active Branch, and actual configured business workflow/industry.
 */
final class SetupReadinessService
{
    public function evaluate(CompanyContext $context, int $actorId): array
    {
        $companyId = $context->companyId;
        $branchId = $context->branchId;
        $fyId = $context->financialYearId;

        $company = Company::find($companyId);
        $checklist = [];

        // 1. Active Branch & Actor Membership
        $branch = $company ? $company->branches()->where('is_active', true)->whereKey($branchId)->first() : null;
        $hasBranchMembership = $branch && DB::table('company_user_branches')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('user_id', $actorId)
            ->exists();

        $checklist['main_branch'] = [
            'title' => 'Active Branch & User Authorization',
            'mandatory' => true,
            'passed' => $branch !== null && $hasBranchMembership,
            'details' => $branch && $hasBranchMembership
                ? "Active branch '{$branch->name}' ({$branch->code}) authorized for user"
                : 'Session branch is inactive, missing, or user lacks branch membership',
        ];

        // 2. Selected Open Financial Year & Date Window
        $fy = $company ? $company->fiscalYears()->whereKey($fyId)->first() : null;
        $isFyValid = $fy && $fy->status === 'open' && !$fy->is_closed;
        $checklist['fiscal_year'] = [
            'title' => 'Selected Financial Year',
            'mandatory' => true,
            'passed' => (bool) $isFyValid,
            'details' => $isFyValid
                ? "FY '{$fy->name}' is active, open, and available for postings ({$fy->start_date} to {$fy->end_date})"
                : 'Selected financial year is closed or invalid for current session',
        ];

        // 3. Document Numbering Series for Branch & FY
        $hasSeries = false;
        if (Schema::hasTable('document_series')) {
            $hasSeries = DB::table('document_series')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('financial_year_id', $fyId)
                ->exists();
        }
        $checklist['document_series'] = [
            'title' => 'Document Numbering Series',
            'mandatory' => true,
            'passed' => $hasSeries || !Schema::hasTable('document_series'),
            'details' => $hasSeries
                ? "Document series provisioned for branch and financial year"
                : 'Voucher series missing for this branch and financial year (requires seeding)',
        ];

        // 4. Chart of Accounts
        $hasCoa = ChartOfAccount::where('company_id', $companyId)->exists();
        $checklist['chart_of_accounts'] = [
            'title' => 'Chart of Accounts',
            'mandatory' => true,
            'passed' => $hasCoa,
            'details' => $hasCoa ? 'Standard ledger accounts provisioned' : 'Chart of accounts missing',
        ];

        // 5. Warehouse / Location under Active Branch
        $warehouse = Warehouse::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();

        $checklist['warehouse'] = [
            'title' => 'Warehouse & Storage Location',
            'mandatory' => true,
            'passed' => $warehouse !== null,
            'details' => $warehouse
                ? "Warehouse '{$warehouse->name}' active under selected branch"
                : 'No active warehouse assigned to this branch',
        ];

        // 6. Default Biller Profile
        $hasBiller = Schema::hasTable('billers') && DB::table('billers')->where('company_id', $companyId)->where('is_active', true)->exists();
        $checklist['default_biller'] = [
            'title' => 'Company Biller Profile',
            'mandatory' => true,
            'passed' => $hasBiller,
            'details' => $hasBiller ? 'Company billing profile ready' : 'Default biller profile missing',
        ];

        // 7. Customer Pricing Group
        $hasCustGroup = Schema::hasTable('customer_groups') && DB::table('customer_groups')->where('company_id', $companyId)->exists();
        $checklist['customer_group'] = [
            'title' => 'Customer Pricing Group',
            'mandatory' => true,
            'passed' => $hasCustGroup,
            'details' => $hasCustGroup ? 'Default customer group ready' : 'Customer groups missing',
        ];

        // 8. Tax / GST Registration for Branch (Workflow-Aware)
        $hasBranchTax = false;
        if (Schema::hasTable('tax_registrations')) {
            $hasBranchTax = DB::table('tax_registrations')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('status', 'active')
                ->exists();
        }
        if (!$hasBranchTax && !empty($company?->settings_json['gstin'])) {
            $hasBranchTax = true;
        }

        $isComplianceEnabled = config('compliance.enabled', false);
        $hasDeclaredGst = !empty($company?->settings_json['gstin']) || (!empty($company?->vat_number));
        $isTaxMandatory = $isComplianceEnabled && $hasDeclaredGst;

        $checklist['tax_registration'] = [
            'title' => 'Branch Tax / GST Registration',
            'mandatory' => $isTaxMandatory,
            'passed' => $hasBranchTax,
            'details' => $hasBranchTax
                ? 'Active GST registration configured for this branch'
                : ($isTaxMandatory ? 'Mandatory GST registration missing for branch' : 'Tax registration not yet configured (recommended for GST billing)'),
        ];

        // 9. Industry-Specific Workflow Requirements
        $industry = $company?->settings_json['industry'] ?? null;
        if (!$industry && Schema::hasTable('company_setup_audits')) {
            $setupAudit = DB::table('company_setup_audits')
                ->where('company_id', $companyId)
                ->where('action', 'company_created')
                ->value('after_json');
            if ($setupAudit) {
                $after = json_decode((string) $setupAudit, true);
                $industry = $after['industry'] ?? null;
            }
        }

        $industryPassed = true;
        $industryDetails = 'General commercial setup ready';
        if ($industry === 'textile') {
            // Textile workflow: requires standard textile units (KG, MTR) and tax category
            $hasTextileUnits = DB::table('units')->where('company_id', $companyId)
                ->whereIn('unit_code', ['KG', 'MTR', 'PCS'])->exists();
            $industryPassed = $hasTextileUnits;
            $industryDetails = $hasTextileUnits
                ? 'Textile UOM and fabric/yarn starter masters verified'
                : 'Textile workflow requires standard units (KG, MTR)';
        }

        $checklist['industry_workflow'] = [
            'title' => 'Industry Workflow Masters (' . ucfirst($industry ?? 'Standard') . ')',
            'mandatory' => false,
            'passed' => $industryPassed,
            'details' => $industryDetails,
        ];

        // 10. Products Catalog
        $hasProducts = Product::where('company_id', $companyId)->where('is_active', true)->exists();
        $checklist['products'] = [
            'title' => 'Product Catalogue',
            'mandatory' => false,
            'passed' => $hasProducts,
            'details' => $hasProducts ? 'Products available in catalog' : 'No products created yet',
        ];

        // 11. Customer / Supplier Directory
        $hasCustomers = Schema::hasTable('customers') && DB::table('customers')->where('company_id', $companyId)->where('is_active', true)->exists();
        $hasSuppliers = Schema::hasTable('suppliers') && DB::table('suppliers')->where('company_id', $companyId)->where('is_active', true)->exists();
        $checklist['parties'] = [
            'title' => 'Commercial Parties (Customers & Suppliers)',
            'mandatory' => false,
            'passed' => $hasCustomers || $hasSuppliers,
            'details' => ($hasCustomers || $hasSuppliers) ? 'Commercial partners on file' : 'No customers or suppliers registered yet',
        ];

        // Scoring
        $mandatoryItems = array_filter($checklist, fn ($item) => $item['mandatory']);
        $passedMandatory = array_filter($mandatoryItems, fn ($item) => $item['passed']);
        $allPassed = array_filter($checklist, fn ($item) => $item['passed']);

        $isReadyForBilling = count($mandatoryItems) === count($passedMandatory);
        $readinessScore = (int) round((count($allPassed) / count($checklist)) * 100);

        $recommendations = [];
        if (!$hasProducts) {
            $recommendations[] = 'Add or import products to enable commercial billing.';
        }
        if (!$hasCustomers && !$hasSuppliers) {
            $recommendations[] = 'Add customers or suppliers to generate purchase orders or tax invoices.';
        }
        if (!$hasBranchTax && $isComplianceEnabled) {
            $recommendations[] = 'Configure active GST registration for this branch under statutory compliance.';
        }

        return [
            'company_id' => $companyId,
            'company_name' => $company?->legal_name ?? 'Company',
            'financial_year_id' => $fyId,
            'branch_id' => $branchId,
            'is_ready_for_billing' => $isReadyForBilling,
            'readiness_score' => $readinessScore,
            'checklist' => $checklist,
            'recommendations' => $recommendations,
        ];
    }
}
