<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\Currency;
use App\Services\Platform\CompanyContext;
use App\Services\Tax\GstProjectionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Authoritative, actor-explicit read facade for GST & Tax breakdown.
 * Wraps GstProjectionService strictly within CompanyContext and active FY boundaries.
 */
class ReadGstSummary
{
    public function __construct(
        protected GstProjectionService $gstService
    ) {}

    public function execute(
        AiPrincipal $principal,
        ?string $from = null,
        ?string $to = null
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        $year = FiscalYear::where('company_id', $principal->companyId)->find($principal->financialYearId);
        $warnings = [];
        $dateAdjusted = false;

        $timezone = Company::whereKey($principal->companyId)->value('timezone') ?: config('app.timezone');
        $today = now($timezone)->toDateString();

        if ($year) {
            $startY = $year->start_date->toDateString();
            $endY = $year->end_date->toDateString();

            if ($from === null) {
                $firstOfMonth = now($timezone)->startOfMonth()->toDateString();
                $from = max($startY, min($endY, $firstOfMonth));
            } elseif ($from < $startY || $from > $endY) {
                $clamped = max($startY, min($endY, $from));
                $warnings[] = "Requested start date {$from} falls outside active financial year '{$year->name}' ({$startY} to {$endY}). Adjusted to {$clamped}.";
                $from = $clamped;
                $dateAdjusted = true;
            }

            if ($to === null) {
                $to = max($startY, min($endY, $today));
            } elseif ($to < $startY || $to > $endY) {
                $clamped = max($startY, min($endY, $to));
                $warnings[] = "Requested end date {$to} falls outside active financial year '{$year->name}' ({$startY} to {$endY}). Adjusted to {$clamped}.";
                $to = $clamped;
                $dateAdjusted = true;
            }
        } else {
            $from = $from ?? now($timezone)->startOfMonth()->toDateString();
            $to = $to ?? $today;
        }

        if ($from > $to) {
            $temp = $from;
            $from = $to;
            $to = $temp;
        }

        $company = Company::find($principal->companyId);
        $currencyCode = 'INR';
        if ($company && $company->base_currency_id) {
            $currencyCode = Currency::find($company->base_currency_id)?->code ?? 'INR';
        }

        // Fetch projections from GstProjectionService
        $rows = $this->gstService->report($context, $from, $to);
        $summary = $this->gstService->summary($rows);

        $totalTaxable = BigDecimal::zero();
        $totalCgst = BigDecimal::zero();
        $totalSgst = BigDecimal::zero();
        $totalIgst = BigDecimal::zero();
        $totalCess = BigDecimal::zero();

        $sectionsBreakdown = [];
        foreach ($summary as $bucket => $fields) {
            $taxable = BigDecimal::of((string) ($fields['taxable_value'] ?? 0));
            $cgst = BigDecimal::of((string) ($fields['cgst'] ?? 0));
            $sgst = BigDecimal::of((string) ($fields['sgst'] ?? 0));
            $igst = BigDecimal::of((string) ($fields['igst'] ?? 0));
            $cess = BigDecimal::of((string) ($fields['cess'] ?? 0));

            $totalTaxable = $totalTaxable->plus($taxable);
            $totalCgst = $totalCgst->plus($cgst);
            $totalSgst = $totalSgst->plus($sgst);
            $totalIgst = $totalIgst->plus($igst);
            $totalCess = $totalCess->plus($cess);

            $sectionsBreakdown[$bucket] = [
                'taxable_value' => (string) $taxable->toScale(2, RoundingMode::HalfUp),
                'cgst' => (string) $cgst->toScale(2, RoundingMode::HalfUp),
                'sgst' => (string) $sgst->toScale(2, RoundingMode::HalfUp),
                'igst' => (string) $igst->toScale(2, RoundingMode::HalfUp),
                'cess' => (string) $cess->toScale(2, RoundingMode::HalfUp),
            ];
        }

        $totalTaxLiability = $totalCgst->plus($totalSgst)->plus($totalIgst)->plus($totalCess);

        $sourceRoute = \Illuminate\Support\Facades\Route::has('compliance.gst-returns')
            ? route('compliance.gst-returns', absolute: false)
            : '/compliance/gst/returns';

        return ErpToolResult::ok(
            toolKey: 'tax.gst_summary.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'period' => [
                    'from' => $from,
                    'to' => $to,
                    'date_adjusted' => $dateAdjusted,
                ],
                'currency' => $currencyCode,
                'total_taxable_value' => (string) $totalTaxable->toScale(2, RoundingMode::HalfUp),
                'total_cgst' => (string) $totalCgst->toScale(2, RoundingMode::HalfUp),
                'total_sgst' => (string) $totalSgst->toScale(2, RoundingMode::HalfUp),
                'total_igst' => (string) $totalIgst->toScale(2, RoundingMode::HalfUp),
                'total_tax_liability' => (string) $totalTaxLiability->toScale(2, RoundingMode::HalfUp),
                'sections_count' => count($sectionsBreakdown),
                'sections' => $sectionsBreakdown,
            ],
            sourceRefs: [$sourceRoute],
            warnings: $warnings,
            asOf: $to,
            reconciled: true
        );
    }
}
