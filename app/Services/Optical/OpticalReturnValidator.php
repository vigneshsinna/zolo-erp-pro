<?php

declare(strict_types=1);

namespace App\Services\Optical;

use App\Models\Product_Sale;
use App\Models\ProductPurchase;
use App\Services\Platform\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Validates optical lens return lines against the original posted sale or purchase line.
 * Blocks returning a wrong power (e.g. +2.00 against -2.00), over-returning, or invalid QC dispositions.
 */
final class OpticalReturnValidator
{
    public const VALID_DISPOSITIONS = [
        'usable_stock',
        'quarantine',
        'scrap',
        'supplier_return',
    ];

    /**
     * @param array<string, mixed> $returnedSpec
     * @return array<string, mixed>
     */
    public function validateReturnLine(
        CompanyContext $context,
        string $kind,
        int $originalLineId,
        array $returnedSpec,
        float $returnedQty,
        string $disposition,
    ): array {
        if (!in_array($kind, ['sale', 'purchase'], true)) {
            throw ValidationException::withMessages(['kind' => 'Return kind must be sale or purchase.']);
        }
        if (!in_array($disposition, self::VALID_DISPOSITIONS, true)) {
            throw ValidationException::withMessages(['disposition' => 'Select a valid optical return QC disposition.']);
        }
        if ($returnedQty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Returned quantity must be positive.']);
        }

        $line = $kind === 'sale'
            ? Product_Sale::where('company_id', $context->companyId)->find($originalLineId)
            : ProductPurchase::where('company_id', $context->companyId)->find($originalLineId);

        if (!$line) {
            throw ValidationException::withMessages(['original_line_id' => 'Original posted document line not found in current company.']);
        }

        $eligibleQty = (float) $line->qty - (float) ($line->return_qty ?? 0);
        if ($returnedQty > $eligibleQty + 0.0001) {
            throw ValidationException::withMessages([
                'qty' => "Returned quantity ({$returnedQty}) exceeds remaining eligible quantity ({$eligibleQty}).",
            ]);
        }

        $stockDetails = is_array($line->stock_details_json ?? null)
            ? $line->stock_details_json
            : (is_string($line->stock_details_json ?? null)
                ? (json_decode($line->stock_details_json, true) ?: [])
                : []);

        $originalSpec = OpticalSpecNormalizer::normalizeSpec([
            'sph' => $line->sph ?? ($stockDetails['sph'] ?? null),
            'cyl' => $line->cyl ?? ($stockDetails['cyl'] ?? null),
            'axis' => $line->axis ?? ($stockDetails['axis'] ?? null),
            'addition' => $line->addition ?? ($stockDetails['addition'] ?? null),
            'base_curve' => $line->base ?? ($stockDetails['base_curve'] ?? ($stockDetails['base'] ?? null)),
            'dia' => $line->dia ?? ($stockDetails['dia'] ?? null),
            'lr' => $line->lr ?? ($stockDetails['lr'] ?? null),
        ])['canonical'];

        $suppliedNormalized = OpticalSpecNormalizer::normalizeSpec($returnedSpec)['canonical'];

        foreach (['sph', 'cyl', 'axis', 'addition', 'base_curve', 'dia', 'lr'] as $field) {
            if ($originalSpec[$field] !== null && $suppliedNormalized[$field] !== $originalSpec[$field]) {
                throw ValidationException::withMessages([
                    $field => "Returned lens {$field} ({$suppliedNormalized[$field]}) does not match the original posted line ({$originalSpec[$field]}).",
                ]);
            }
        }

        return [
            'verified' => true,
            'original_line_id' => (int) $line->id,
            'product_id' => (int) $line->product_id,
            'eligible_qty' => $eligibleQty,
            'returned_qty' => $returnedQty,
            'disposition' => $disposition,
            'canonical_spec' => $originalSpec,
        ];
    }
}
