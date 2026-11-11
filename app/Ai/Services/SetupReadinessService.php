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
 * Evaluates core prerequisites required before first commercial transaction/bill.
 */
final class SetupReadinessService
{
    public function evaluate(CompanyContext $context, int $actorId): array
    {
        $companyId = $context->companyId;
        $company = Company::find($companyId);

        $checklist = [];

        // 1. Main Branch
        $hasBranch = $company && $company->branches()->where('is_active', true)->exists();
        $checklist['main_branch'] = [
            'title' => 'Active Branch',
            'mandatory' => true,
            'passed' => $hasBranch,
            'details' => $hasBranch ? 'Active branch configured' : 'No active branch found',
        ];

        // 2. Open Fiscal Year
        $hasOpenFy = $company && $company->fiscalYears()->where('status', 'open')->where('is_closed', false)->exists();
        $checklist['fiscal_year'] = [
            'title' => 'Open Financial Year',
            'mandatory' => true,
            'passed' => $hasOpenFy,
            'details' => $hasOpenFy ? 'Active open financial year present' : 'No open financial year found',
        ];

        // 3. Chart of Accounts
        $hasCoa = ChartOfAccount::where('company_id', $companyId)->exists();
        $checklist['chart_of_accounts'] = [
            'title' => 'Chart of Accounts',
            'mandatory' => true,
            'passed' => $hasCoa,
            'details' => $hasCoa ? 'Standard ledger accounts provisioned' : 'Chart of accounts missing',
        ];

        // 4. Warehouse / Location
        $hasWarehouse = Warehouse::where('company_id', $companyId)->where('is_active', true)->exists();
        $checklist['warehouse'] = [
            'title' => 'Warehouse & Storage',
            'mandatory' => true,
            'passed' => $hasWarehouse,
            'details' => $hasWarehouse ? 'Storage location assigned' : 'No active warehouse assigned',
        ];

        // 5. Default Biller
        $hasBiller = Schema::hasTable('billers') && DB::table('billers')->where('company_id', $companyId)->where('is_active', true)->exists();
        $checklist['default_biller'] = [
            'title' => 'Company Biller Profile',
            'mandatory' => true,
            'passed' => $hasBiller,
            'details' => $hasBiller ? 'Company billing profile ready' : 'Default biller profile missing',
        ];

        // 6. Customer Group
        $hasCustGroup = Schema::hasTable('customer_groups') && DB::table('customer_groups')->where('company_id', $companyId)->exists();
        $checklist['customer_group'] = [
            'title' => 'Customer Pricing Group',
            'mandatory' => true,
            'passed' => $hasCustGroup,
            'details' => $hasCustGroup ? 'Default customer group ready' : 'Customer groups missing',
        ];

        // 7. Tax / GST Registration
        $hasTax = false;
        if (Schema::hasTable('tax_registrations')) {
            $hasTax = DB::table('tax_registrations')->where('company_id', $companyId)->where('status', 'active')->exists();
        }
        if (!$hasTax && !empty($company?->settings_json['gstin'])) {
            $hasTax = true;
        }
        $checklist['tax_registration'] = [
            'title' => 'Tax & GST Registration',
            'mandatory' => false,
            'passed' => $hasTax,
            'details' => $hasTax ? 'Tax registration on file' : 'GST / tax registration not yet configured',
        ];

        // 8. Products
        $hasProducts = Product::where('company_id', $companyId)->where('is_active', true)->exists();
        $checklist['products'] = [
            'title' => 'Product Catalogue',
            'mandatory' => false,
            'passed' => $hasProducts,
            'details' => $hasProducts ? 'Products available in catalog' : 'No products created yet',
        ];

        // 9. Customers / Parties
        $hasCustomers = Schema::hasTable('customers') && DB::table('customers')->where('company_id', $companyId)->where('is_active', true)->exists();
        $checklist['customers'] = [
            'title' => 'Customer Directory',
            'mandatory' => false,
            'passed' => $hasCustomers,
            'details' => $hasCustomers ? 'Customers registered' : 'No customers on file',
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
        if (!$hasCustomers) {
            $recommendations[] = 'Add customers or suppliers to generate tax invoices.';
        }
        if (!$hasTax) {
            $recommendations[] = 'Configure GST registration if applicable under statutory thresholds.';
        }

        return [
            'company_id' => $companyId,
            'company_name' => $company?->legal_name ?? 'Company',
            'is_ready_for_billing' => $isReadyForBilling,
            'readiness_score' => $readinessScore,
            'checklist' => $checklist,
            'recommendations' => $recommendations,
        ];
    }
}

