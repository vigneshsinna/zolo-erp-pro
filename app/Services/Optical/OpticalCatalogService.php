<?php

declare(strict_types=1);

namespace App\Services\Optical;

use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Commercial\ProductAliasService;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Platform\CompanyContext;
use App\Support\LedgerAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Company-scoped optical lens identity registration and exact/candidate resolution.
 * Never substitutes signed powers (-2.00 vs +2.00 vs -2.25) or guesses ambiguous legacy SKUs.
 */
final class OpticalCatalogService
{
    public function registerIdentity(CompanyContext $context, Product $product, array $spec, ?int $variantId = null): object
    {
        if ((int) $product->company_id !== $context->companyId) {
            throw ValidationException::withMessages(['product_id' => 'Product does not belong to the current company.']);
        }

        $normalized = OpticalSpecNormalizer::normalizeSpec([
            'lens_family' => $spec['lens_family'] ?? $product->name,
            'lens_material' => $spec['lens_material'] ?? null,
            'refractive_index' => $spec['refractive_index'] ?? null,
            'coating' => $spec['coating'] ?? null,
            'lens_form' => $spec['lens_form'] ?? 'finished_stock',
            'sph' => $spec['sph'] ?? null,
            'cyl' => $spec['cyl'] ?? null,
            'axis' => $spec['axis'] ?? null,
            'addition' => $spec['addition'] ?? $product->addition,
            'base_curve' => $spec['base_curve'] ?? ($spec['base'] ?? $product->base),
            'dia' => $spec['dia'] ?? $product->dia,
            'lr' => $spec['lr'] ?? $product->lr,
        ]);

        $c = $normalized['canonical'];
        $existing = DB::table('optical_lens_identities')
            ->where('company_id', $context->companyId)
            ->where('spec_signature', $normalized['signature'])
            ->first();

        if ($existing && (int) $existing->product_id !== (int) $product->id) {
            throw ValidationException::withMessages([
                'optical_spec' => 'An identical optical specification signature is already registered to another product SKU.',
            ]);
        }

        $payload = [
            'company_id' => $context->companyId,
            'product_id' => (int) $product->id,
            'variant_id' => $variantId,
            'lens_family' => (string) ($c['lens_family'] ?? $product->name),
            'lens_material' => $c['lens_material'],
            'refractive_index' => $c['refractive_index'],
            'coating' => $c['coating'],
            'lens_form' => (string) ($c['lens_form'] ?? 'finished_stock'),
            'sph' => $c['sph'],
            'cyl' => $c['cyl'],
            'axis' => $c['axis'],
            'addition' => $c['addition'],
            'base_curve' => $c['base_curve'],
            'dia' => $c['dia'],
            'lr' => $c['lr'],
            'spec_signature' => $normalized['signature'],
            'raw_spec_json' => json_encode($normalized['raw'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('optical_lens_identities')->where('id', $existing->id)->update($payload);
            return DB::table('optical_lens_identities')->where('id', $existing->id)->first();
        }

        $id = DB::table('optical_lens_identities')->insertGetId($payload + ['created_at' => now()]);
        return DB::table('optical_lens_identities')->where('id', $id)->first();
    }

    /**
     * Resolve exact optical lens identity or return candidate diffs.
     *
     * @param array<string, mixed> $opticalSpec
     * @return array{
     *     status: 'exact'|'candidates'|'ambiguous_legacy_catalog'|'none',
     *     product: ?Product,
     *     identity: ?object,
     *     candidates: array<int, array<string, mixed>>,
     *     message?: string
     * }
     */
    public function resolveExactOrCandidates(
        CompanyContext $context,
        string $query = '',
        array $opticalSpec = [],
        ?int $warehouseId = null,
    ): array {
        $trimmed = trim($query);
        $hasSpec = $this->hasAnyOpticalFilter($opticalSpec);
        $normalizedReq = $hasSpec ? OpticalSpecNormalizer::normalizeSpec($opticalSpec)['canonical'] : [];

        $baseQuery = Product::where('products.company_id', $context->companyId)
            ->where('products.is_active', true)
            ->where(function ($q) {
                $q->whereNull('products.type')->orWhereNotIn('products.type', ['combo']);
            });

        $aliasProductIds = collect();
        if ($trimmed !== '' && Schema::hasTable('product_aliases')) {
            $normAlias = ProductAliasService::normalize($trimmed);
            if ($normAlias !== '') {
                $aliasProductIds = ProductAlias::where('company_id', $context->companyId)
                    ->where('normalized_name', $normAlias)
                    ->pluck('product_id')
                    ->unique();
            }
        }

        if ($trimmed !== '') {
            $baseQuery->where(function ($q) use ($trimmed, $aliasProductIds): void {
                $q->where('products.code', $trimmed)
                    ->orWhere('products.name', 'LIKE', '%' . addcslashes($trimmed, '\\%_') . '%')
                    ->orWhere('products.code', 'LIKE', '%' . addcslashes($trimmed, '\\%_') . '%');
                if ($aliasProductIds->isNotEmpty()) {
                    $q->orWhereIn('products.id', $aliasProductIds->all());
                }
            });
        }

        $products = $baseQuery->orderBy('products.id')->limit(50)->get();
        if ($products->isEmpty()) {
            return ['status' => 'none', 'product' => null, 'identity' => null, 'candidates' => []];
        }

        $identities = Schema::hasTable('optical_lens_identities')
            ? DB::table('optical_lens_identities')
                ->where('company_id', $context->companyId)
                ->where('is_active', true)
                ->whereIn('product_id', $products->pluck('id')->all())
                ->get()
                ->groupBy('product_id')
            : collect();

        $stockByProduct = [];
        if ($warehouseId !== null && $warehouseId > 0) {
            $stockByProduct = app(InventoryAvailabilityService::class)
                ->forCatalogBatch($context, $products, [$warehouseId]);
        }

        $exactMatches = [];
        $familyCandidates = [];
        $unmappedLegacyHits = 0;

        foreach ($products as $product) {
            $rows = $identities->get($product->id, collect());
            if ($rows->isEmpty()) {
                if ($hasSpec && $this->hasPowerConstraint($normalizedReq)) {
                    $unmappedLegacyHits++;
                    continue;
                }
                $familyCandidates[] = $this->formatCandidate($product, null, [], $stockByProduct[$product->id] ?? []);
                continue;
            }

            foreach ($rows as $row) {
                $candidateSpec = [
                    'lens_family' => $row->lens_family,
                    'lens_material' => $row->lens_material,
                    'refractive_index' => $row->refractive_index,
                    'coating' => $row->coating,
                    'lens_form' => $row->lens_form,
                    'sph' => $row->sph,
                    'cyl' => $row->cyl,
                    'axis' => $row->axis,
                    'addition' => $row->addition,
                    'base_curve' => $row->base_curve,
                    'dia' => $row->dia,
                    'lr' => $row->lr,
                ];

                if ($hasSpec) {
                    $diffs = OpticalSpecNormalizer::diffFields($normalizedReq, $candidateSpec);
                    if ($diffs === []) {
                        $exactMatches[] = ['product' => $product, 'identity' => $row, 'spec' => $candidateSpec];
                    } else {
                        // Never surface opposing power sign or different power as a match if user asked for specific SPH/CYL
                        $powerMismatch = isset($diffs['sph']) || isset($diffs['cyl']) || isset($diffs['addition']);
                        if (!$powerMismatch) {
                            $familyCandidates[] = $this->formatCandidate($product, $row, $diffs, $stockByProduct[$product->id] ?? []);
                        }
                    }
                } else {
                    $familyCandidates[] = $this->formatCandidate($product, $row, [], $stockByProduct[$product->id] ?? []);
                }
            }
        }

        if (count($exactMatches) === 1) {
            return [
                'status' => 'exact',
                'product' => $exactMatches[0]['product'],
                'identity' => $exactMatches[0]['identity'],
                'candidates' => [$this->formatCandidate($exactMatches[0]['product'], $exactMatches[0]['identity'], [], $stockByProduct[$exactMatches[0]['product']->id] ?? [])],
            ];
        }

        if (count($exactMatches) > 1) {
            $candidates = array_map(
                fn ($m) => $this->formatCandidate($m['product'], $m['identity'], [], $stockByProduct[$m['product']->id] ?? []),
                $exactMatches
            );
            return [
                'status' => 'candidates',
                'product' => null,
                'identity' => null,
                'candidates' => $candidates,
                'message' => 'Multiple SKUs matched this specification. Select the exact SKU.',
            ];
        }

        if ($familyCandidates !== []) {
            if (count($familyCandidates) === 1 && !$hasSpec && $trimmed !== '') {
                $only = $products->firstWhere('id', $familyCandidates[0]['product_id']);
                $onlyIdentity = $identities->get($only->id)?->first();
                return [
                    'status' => 'exact',
                    'product' => $only,
                    'identity' => $onlyIdentity,
                    'candidates' => $familyCandidates,
                ];
            }

            return [
                'status' => 'candidates',
                'product' => null,
                'identity' => null,
                'candidates' => array_slice($familyCandidates, 0, 20),
                'message' => 'Select the exact coating, refractive index, or diameter from candidates.',
            ];
        }

        if ($unmappedLegacyHits > 0) {
            return [
                'status' => 'ambiguous_legacy_catalog',
                'product' => null,
                'identity' => null,
                'candidates' => [],
                'message' => 'Matching legacy SKU has no canonical power identity mapped. Map optical_lens_identities before power-level allocation.',
            ];
        }

        return ['status' => 'none', 'product' => null, 'identity' => null, 'candidates' => []];
    }

    private function hasAnyOpticalFilter(array $spec): bool
    {
        foreach (OpticalSpecNormalizer::IDENTITY_FIELDS as $field) {
            if (isset($spec[$field]) && $spec[$field] !== null && trim((string) $spec[$field]) !== '') {
                return true;
            }
        }
        return false;
    }

    private function hasPowerConstraint(array $canonical): bool
    {
        return ($canonical['sph'] ?? null) !== null
            || ($canonical['cyl'] ?? null) !== null
            || ($canonical['addition'] ?? null) !== null;
    }

    private function formatCandidate(Product $product, ?object $identity, array $diffs, array $stock): array
    {
        $onHand = isset($stock['on_hand']) ? LedgerAmount::decimal((int) $stock['on_hand']) : null;
        $reserved = isset($stock['reserved']) ? LedgerAmount::decimal((int) $stock['reserved']) : null;
        $available = isset($stock['on_hand']) ? LedgerAmount::decimal(((int) $stock['on_hand']) - ((int) ($stock['reserved'] ?? 0))) : null;

        return [
            'product_id' => (int) $product->id,
            'code' => (string) $product->code,
            'name' => (string) $product->name,
            'unit_id' => $product->unit_id ? (int) $product->unit_id : null,
            'optical_spec' => $identity ? [
                'lens_family' => $identity->lens_family,
                'lens_material' => $identity->lens_material,
                'refractive_index' => $identity->refractive_index,
                'coating' => $identity->coating,
                'lens_form' => $identity->lens_form,
                'sph' => $identity->sph,
                'cyl' => $identity->cyl,
                'axis' => $identity->axis,
                'addition' => $identity->addition,
                'base_curve' => $identity->base_curve,
                'dia' => $identity->dia,
                'lr' => $identity->lr,
                'spec_signature' => $identity->spec_signature,
            ] : null,
            'diff_fields' => $diffs,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $available,
        ];
    }
}
