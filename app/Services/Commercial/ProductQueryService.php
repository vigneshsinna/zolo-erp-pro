<?php

namespace App\Services\Commercial;

use App\Models\Product;
use App\Services\Platform\CompanyContext;
use App\Services\Tax\GstSetupService;
use Illuminate\Support\Facades\Schema;

class ProductQueryService
{
    public function search(string $term, CompanyContext $context, ?string $partyType = null, ?int $partyId = null): array
    {
        app(ProductAliasService::class)->party($partyType, $partyId, $context);
        $normalized = ProductAliasService::normalize($term);
        $rawTerm = trim($term);
        $escapedTerm = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $rawTerm);

        $base = Product::forCompany($context)->where('products.is_active', true)->whereIn('products.type', ['standard', 'service', 'digital'])
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->select([
                'products.id', 'products.name', 'products.code', 'products.type',
                'products.price', 'products.cost', 'products.tax_id', 'products.tax_category_id',
                'products.tax_method', 'products.unit_id', 'units.unit_name', 'units.unit_code',
                'products.is_batch', 'products.is_imei', 'products.is_variant',
                'products.base', 'products.addition', 'products.lr', 'products.dia', 'products.product_type_id'
            ]);

        if ($partyType === 'customer' || $partyType === 'supplier') {
            $base->forBill($partyType === 'customer' ? 'sale' : 'purchase');
        }

        if ($rawTerm === '') {
            return $this->formatResults($base->orderBy('products.code')->limit(20)->get(), $normalized, collect());
        }

        $aliases = Schema::hasTable('product_aliases')
            ? app(ProductAliasService::class)->visible($context, $partyType, $partyId)
                ->whereRaw("normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized).'%'])
                ->limit(50)->get()
            : collect();

        $rank = $aliases->groupBy('product_id')->map(fn ($rows) => $rows->min(fn ($alias) =>
            $alias->normalized_name === $normalized ? ($alias->party_type === 'company' ? 2 : 1) : 3));

        $query = $base->where(function ($query) use ($escapedTerm, $rank) {
            $query->whereRaw("products.code LIKE ? ESCAPE '!'", [$escapedTerm.'%'])
                ->orWhereRaw("products.name LIKE ? ESCAPE '!'", [$escapedTerm.'%']);
            if ($rank->isNotEmpty()) {
                $query->orWhereIn('products.id', $rank->keys());
            }
        });

        // Optimize candidate retrieval: order exact code/SKU matches first, bound SQL candidates to 80 rows
        $candidates = $query->orderByRaw(
            "CASE WHEN products.code = ? THEN 0 WHEN products.code LIKE ? THEN 1 ELSE 2 END, products.code",
            [$rawTerm, $escapedTerm.'%']
        )->limit(80)->get();

        return $this->formatResults($candidates, $normalized, $rank, $context);
    }

    private function formatResults($products, string $normalized, $rank, ?CompanyContext $context = null): array
    {
        $gstRates = app(GstSetupService::class)->currentRates();
        $exclusive = fn ($amount, $prod, $rate) => (int) ($prod->tax_method ?? 1) === 2 && $rate > 0 ? round($amount * 100 / (100 + $rate), 4) : $amount;
        $isOptical = $context !== null
            && (bool) config('ai_business_knowledge.opticals_enabled', false)
            && app(\App\Ai\Knowledge\IndustryContextResolver::class)->isOptical($context);

        return $products->map(function ($product) use ($rank, $normalized, $gstRates, $exclusive, $isOptical) {
            $taxVal = 0;
            if (!empty($product->tax_category_id) && isset($gstRates[$product->tax_category_id])) {
                $taxVal = (float) $gstRates[$product->tax_category_id]['rate'];
            } elseif (!empty($product->tax_id) && $product->tax) {
                $taxVal = (float) ($product->tax->rate ?? 0);
            }

            $price = $exclusive((float) ($product->price ?? 0), $product, $taxVal);
            $matchRank = ProductAliasService::normalize($product->code) === $normalized ? 0
                : ($rank->get($product->id) ?? (ProductAliasService::normalize($product->name) === $normalized ? 3 : 4));

            $unitText = (string) ($product->unit_name ?: $product->unit_code ?: '');

            $row = [
                'id' => (int) $product->id,
                'type' => (string) ($product->type ?? 'standard'),
                'name' => (string) $product->name,
                'code' => (string) $product->code,
                'price' => $price,
                'cost' => (float) ($product->cost ?? 0),
                'tax_id' => $product->tax_id ? (int) $product->tax_id : null,
                'tax_category_id' => $product->tax_category_id ? (int) $product->tax_category_id : null,
                'tax_method' => (int) ($product->tax_method ?? 1),
                'tax_rate' => $taxVal,
                'unit_id' => $product->unit_id ? (int) $product->unit_id : null,
                'unit' => $unitText,
                'unit_name' => (string) ($product->unit_name ?? ''),
                'unit_code' => (string) ($product->unit_code ?? ''),
                'is_batch' => (bool) ($product->is_batch ?? false),
                'is_imei' => (bool) ($product->is_imei ?? false),
                'is_variant' => (bool) ($product->is_variant ?? false),
                'value' => (string) ($product->code . ' (' . $product->name . ')'),
                'label' => (string) ($product->code . ' (' . $product->name . ')'),
                'match_rank' => $matchRank,
            ];
            if ($isOptical) {
                $row += [
                    'base' => $product->base,
                    'addition' => $product->addition,
                    'lr' => $product->lr,
                    'dia' => $product->dia,
                    'product_type_id' => $product->product_type_id ? (int) $product->product_type_id : null,
                ];
            }

            return $row;
        })->sortBy(fn ($item) => sprintf('%d:%s:%010d', $item['match_rank'], $item['code'], $item['id']))
          ->take(50)->values()->toArray();
    }
}

