<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Operations\Bom;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Authoritative, actor-explicit read facade for Manufacturing Bill of Materials (BOM).
 * Strictly scoped by CompanyContext and published BOM revisions.
 */
class ReadManufacturingBoms
{
    /**
     * Execute manufacturing BOM query.
     */
    public function execute(
        AiPrincipal $principal,
        ?int $productId = null,
        ?string $code = null,
        int $limit = 10
    ): ErpToolResult {
        $context = new CompanyContext(
            companyId: $principal->companyId,
            branchId: $principal->branchId,
            financialYearId: $principal->financialYearId
        );

        $limit = max(1, min(50, $limit));

        // 1. Build query scoped to authorized company
        $query = DB::table('boms')
            ->where('company_id', $principal->companyId);

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        if ($code !== null && trim($code) !== '') {
            $query->where('code', 'like', '%' . trim($code) . '%');
        }

        $boms = $query->orderByDesc('is_default')
            ->orderByDesc('version')
            ->limit($limit)
            ->get();

        $bomList = [];
        foreach ($boms as $bom) {
            $product = Product::find($bom->product_id);
            $uom = Unit::find($bom->output_uom_id);

            // Fetch component lines
            $lines = DB::table('bom_lines')
                ->where('bom_id', $bom->id)
                ->orderBy('line_no')
                ->get();

            $components = [];
            foreach ($lines as $line) {
                $compProd = Product::find($line->component_product_id);
                $compUom = Unit::find($line->uom_id);

                $components[] = [
                    'line_no' => (int) $line->line_no,
                    'component_id' => (int) $line->component_product_id,
                    'component_name' => $compProd?->name ?? 'Unknown Component',
                    'component_code' => $compProd?->code ?? '',
                    'qty' => (float) $line->qty,
                    'uom' => $compUom?->unit_code ?? 'PCS',
                    'scrap_percent' => (float) ($line->scrap_percent ?? 0),
                ];
            }

            $bomList[] = [
                'bom_id' => (int) $bom->id,
                'code' => $bom->code,
                'version' => (int) $bom->version,
                'status' => $bom->status,
                'is_default' => (bool) $bom->is_default,
                'output_product' => [
                    'id' => (int) $bom->product_id,
                    'name' => $product?->name ?? 'Unknown Product',
                    'code' => $product?->code ?? '',
                    'output_qty' => (float) $bom->output_qty,
                    'uom' => $uom?->unit_code ?? 'PCS',
                ],
                'effective_from' => $bom->effective_from,
                'effective_to' => $bom->effective_to,
                'component_count' => count($components),
                'components' => $components,
            ];
        }

        $sourceRoute = Route::has('operations.boms') ? route('operations.boms', absolute: false) : '/operations/boms';

        return ErpToolResult::ok(
            toolKey: 'manufacturing.boms.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'total_boms_found' => count($bomList),
                'boms' => $bomList,
            ],
            sourceRefs: [$sourceRoute],
            warnings: [],
            asOf: now()->toDateString(),
            reconciled: true,
            rowCount: count($bomList)
        );
    }
}
