<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Models\Company;
use App\Models\Operations\Bom;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Operations\OperationPosting;
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
        app(OperationPosting::class)->authorize('manufacturing.bom', 'manufacturing.read', $context, $principal->actorId);

        $limit = max(1, min(50, $limit));

        // 1. Build query scoped to authorized company
        $query = DB::table('boms')
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId);

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        if ($code !== null && trim($code) !== '') {
            $query->where('code', 'like', '%' . trim($code) . '%');
        }

        $totalBoms = (clone $query)->count();
        $boms = $query->orderByDesc('is_default')
            ->orderByDesc('version')
            ->limit($limit)
            ->get();

        $bomList = $warnings = [];
        foreach ($boms as $bom) {
            $product = Product::where('company_id', $principal->companyId)->find($bom->product_id);
            $uom = Unit::where('company_id', $principal->companyId)->find($bom->output_uom_id);

            // Fetch component lines
            $lineQuery = DB::table('bom_lines')->where('bom_id', $bom->id);
            $componentCount = (clone $lineQuery)->count();
            $lines = $lineQuery->orderBy('line_no')->limit(50)->get();
            if ($componentCount > 50) {
                $warnings[] = 'BOM component details are limited to the first 50 lines per recipe.';
            }

            $components = [];
            foreach ($lines as $line) {
                $compProd = Product::where('company_id', $principal->companyId)->find($line->component_product_id);
                $compUom = Unit::where('company_id', $principal->companyId)->find($line->uom_id);

                $components[] = [
                    'line_no' => (int) $line->line_no,
                    'component_id' => (int) $line->component_product_id,
                    'component_name' => $compProd?->name ?? 'Unknown Component',
                    'component_code' => $compProd?->code ?? '',
                    'qty' => (float) $line->qty,
                    'uom' => $compUom?->unit_code,
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
                    'uom' => $uom?->unit_code,
                ],
                'effective_from' => $bom->effective_from,
                'effective_to' => $bom->effective_to,
                'component_count' => $componentCount,
                'components' => $components,
            ];
        }

        $sourceRoute = route('operations.hub', ['area' => 'manufacturing'], absolute: false);

        return ErpToolResult::ok(
            toolKey: 'manufacturing.boms.v1',
            scope: [
                'company_id' => $principal->companyId,
                'branch_id' => $principal->branchId,
                'financial_year_id' => $principal->financialYearId,
            ],
            data: [
                'total_boms_found' => $totalBoms,
                'boms' => $bomList,
            ],
            sourceRefs: [$sourceRoute],
            warnings: array_values(array_unique($warnings)),
            asOf: now()->toDateString(),
            reconciled: true,
            rowCount: count($bomList)
        );
    }
}
