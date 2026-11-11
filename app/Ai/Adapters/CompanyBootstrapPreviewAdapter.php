<?php

declare(strict_types=1);

namespace App\Ai\Adapters;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Industry\IndustryCatalog;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Preview adapter for Mode A company creation (setup.company.create.v1).
 * Computes simulated starter entities and defaults with ZERO database writes.
 */
final class CompanyBootstrapPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(
        ActionDefinition $definition,
        array $slots,
        CompanyContext|BootstrapSetupPrincipal $context,
    ): ActionPreviewResult {
        $legalName = trim((string) ($slots['legal_name'] ?? ''));
        $tradeName = trim((string) ($slots['trade_name'] ?? ''));
        $effectiveName = $tradeName !== '' ? $tradeName : $legalName;

        // Simulated uppercase company code
        $code = isset($slots['code']) && trim((string) $slots['code']) !== ''
            ? strtoupper(trim((string) $slots['code']))
            : $this->generateSimulatedCode($effectiveName, 'companies');

        // Industry & Subtype defaults
        $industry = (string) ($slots['industry'] ?? 'general_trading');
        $subtypes = IndustryCatalog::SUBTYPES[$industry] ?? ['trading' => 'Trading & Distribution'];
        $subtype = isset($slots['subtype']) && array_key_exists($slots['subtype'], $subtypes)
            ? (string) $slots['subtype']
            : (string) array_key_first($subtypes);

        // Dates & FY naming
        $fyStart = (string) ($slots['fy_start'] ?? date('Y-04-01'));
        $fyEnd = (string) ($slots['fy_end'] ?? date('Y-03-31', strtotime('+1 year', strtotime($fyStart))));
        $fyName = $this->formatFiscalYearName($fyStart, $fyEnd);

        // Simulated warehouse
        $warehouseName = $effectiveName . ' - Main';

        // Normalized payload with defaults
        $normalizedPayload = array_merge($slots, [
            'legal_name' => $legalName,
            'trade_name' => $tradeName !== '' ? $tradeName : null,
            'code' => $code,
            'industry' => $industry,
            'subtype' => $subtype,
            'timezone' => (string) ($slots['timezone'] ?? 'Asia/Kolkata'),
            'fy_start' => $fyStart,
            'fy_end' => $fyEnd,
        ]);

        $previewData = [
            'company' => [
                'legal_name' => $legalName,
                'trade_name' => $tradeName !== '' ? $tradeName : null,
                'code' => $code,
                'industry' => $industry,
                'industry_title' => ucfirst(str_replace('_', ' ', $industry)),
                'subtype' => $subtype,
                'subtype_title' => $subtypes[$subtype] ?? ucfirst($subtype),
                'timezone' => $normalizedPayload['timezone'],
                'state_code' => $slots['state_code'] ?? null,
                'currency' => 'INR',
            ],
            'simulated_starter_resources' => [
                'main_branch' => [
                    'code' => 'MAIN',
                    'name' => 'Main branch',
                    'branch_type' => 'branch',
                ],
                'first_fiscal_year' => [
                    'name' => $fyName,
                    'start_date' => $fyStart,
                    'end_date' => $fyEnd,
                    'status' => 'open',
                ],
                'main_warehouse' => [
                    'name' => $warehouseName,
                    'branch_code' => 'MAIN',
                    'is_active' => true,
                ],
                'chart_of_accounts' => [
                    'standard_chart' => true,
                    'industry_profile' => $industry,
                    'includes_starter_cash_and_bank' => true,
                ],
                'starter_masters' => [
                    'customer_group' => 'General',
                    'product_category' => 'General',
                    'default_biller' => $effectiveName,
                    'document_numbering_series' => 'Automated standard Optech series',
                ],
                'compliance' => [
                    'state_code' => $slots['state_code'] ?? null,
                    'gst_slabs_configured' => !empty($slots['gstin']) || (($slots['registration_type'] ?? '') === 'unregistered'),
                    'registration_type' => $slots['registration_type'] ?? 'regular',
                ],
            ],
            'transition_context' => [
                'next_step' => 'Approve to create company atomically via CompanyDirectoryService',
                'post_creation_action' => 'switch_context',
                'context_switch_url' => '/select-company',
            ],
        ];

        $sourceStateHash = hash('sha256', 'BOOTSTRAP_NEW_COMPANY:' . ($slots['company_group_id'] ?? 'NONE') . ':' . $code);

        $warnings = [];
        if (empty($slots['state_code'])) {
            $warnings[] = 'State code was omitted; interstate GST tax categorization may require manual configuration later.';
        }

        return new ActionPreviewResult(
            payload: $normalizedPayload,
            preview: $previewData,
            sourceStateHash: $sourceStateHash,
            warnings: $warnings,
        );
    }

    private function generateSimulatedCode(string $name, string $table): string
    {
        $base = substr(strtoupper(Str::slug($name, '_')), 0, 40) ?: 'CO';
        $code = $base;

        try {
            for ($i = 2; DB::table($table)->where('code', $code)->exists(); $i++) {
                $code = $base . '_' . $i;
            }
        } catch (\Throwable) {
            // Safe fallback in test or isolated contexts
            $code = $base;
        }

        return $code;
    }

    private function formatFiscalYearName(string $start, string $end): string
    {
        $from = substr($start, 0, 4);
        $to = substr($end, 0, 4);

        return $from === $to ? 'FY ' . $from : 'FY ' . $from . '-' . substr($to, 2);
    }
}

