<?php

namespace App\Services\ERP;

use App\Models\{Brand, Category, Company, Product, Tax, Unit};
use App\Services\Inventory\UomConversionService;
use App\Services\Platform\CompanyContext;
use App\Services\Tax\GstSetupService;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

/** Shared master validation and creation; opening stock, variants and uploads retain their existing owners. */
class ProductMasterService
{
    public function validateMetadata(array $data, CompanyContext $context): void
    {
        Validator::make($data, [
            'negative_stock_policy' => 'nullable|in:allow,warn,block',
            'product_usage' => Product::USAGE_RULE,
            'category_id' => 'nullable|integer|min:1',
            'tax_category_id' => 'nullable|integer|min:1',
            'product_type_id' => 'nullable|integer|min:1',
            'hsn_code' => 'nullable|string|max:8',
        ])->validate();

        if (!empty($data['category_id'])) {
            app(CompanyWriteGuard::class)->owned(Category::class, $data['category_id'], $context, 'category_id');
        }

        $taxCat = null;
        if (!empty($data['tax_category_id'])) {
            $taxCat = DB::table('tax_categories')->where('company_id', $context->companyId)->where('id', $data['tax_category_id'])->first();
            if (!$taxCat) {
                throw ValidationException::withMessages(['tax_category_id' => 'Select a GST rate of this company.']);
            }
        }

        if (!empty($data['product_type_id']) && \Illuminate\Support\Facades\Schema::hasTable('product_types')) {
            $ptypeExists = DB::table('product_types')->where('company_id', $context->companyId)->where('id', $data['product_type_id'])->exists();
            if (!$ptypeExists) {
                throw ValidationException::withMessages(['product_type_id' => 'Select an item type of this company.']);
            }
        }

        $cleanHsn = !empty($data['hsn_code']) ? preg_replace('/\s+/', '', (string) $data['hsn_code']) : null;
        $productType = $data['type'] ?? 'standard';

        if ($cleanHsn !== null && $cleanHsn !== '') {
            $gst = app(GstSetupService::class);
            $gst->validateCode($cleanHsn, $gst->kindFor($productType));

            // Goods vs Services cross-validation
            if (in_array($productType, ['standard', 'combo'], true) && str_starts_with($cleanHsn, '99')) {
                throw ValidationException::withMessages(['hsn_code' => 'SAC code (starting with 99) is for services. For goods, enter a 4, 6 or 8 digit HSN code.']);
            }

            // Classification consistency with GST category
            if ($taxCat) {
                // Non-GST supply is strictly alcoholic liquor (Ch 22) and 5 petroleum products (Ch 27)
                if ($taxCat->supply_type === 'non_gst') {
                    $prefix = substr($cleanHsn, 0, 2);
                    if (!in_array($prefix, ['22', '27'], true)) {
                        throw ValidationException::withMessages([
                            'tax_category_id' => 'Non-GST supply is only applicable to alcoholic liquor (Chapter 22) and specified petroleum fuels (Chapter 27). For standard goods, choose a taxable or exempt GST rate.',
                        ]);
                    }
                }

                // Garment price-band rate consistency
                if (stripos($taxCat->name, 'garment') !== false) {
                    $prefix = substr($cleanHsn, 0, 2);
                    if (!in_array($prefix, ['61', '62', '63', '64'], true)) {
                        throw ValidationException::withMessages([
                            'hsn_code' => 'Garment price-band rates apply to apparel and made-up textile articles (HSN Chapters 61, 62, 63, 64). HSN '.$cleanHsn.' does not belong to apparel.',
                        ]);
                    }
                }
            }
        }
    }

    public function validateCreation(array $attributes, array $metadata, CompanyContext $context, ?int $ignoreProductId = null): array
    {
        Validator::make($attributes, [
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:255', 'not_regex:/[<>]/'],
            'type' => 'required|in:standard,service,digital,combo',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'profit_margin' => 'nullable|numeric',
            'profit_margin_type' => 'nullable|in:percentage,flat',
            'brand_id' => 'nullable|integer|min:0',
            'tax_id' => 'nullable|integer|min:0',
            'unit_id' => 'nullable|integer|min:0',
            'sale_unit_id' => 'nullable|integer|min:0',
            'purchase_unit_id' => 'nullable|integer|min:0',
            'barcode_symbology' => 'nullable|string|in:C128,C39,UPCA,UPCE,EAN8,EAN13',
            'base' => 'nullable|string|max:50',
            'addition' => 'nullable|string|max:50',
            'lr' => 'nullable|string|max:10',
            'dia' => 'nullable|string|max:20',
        ])->validate();
        $attributes['code'] = trim($attributes['code']);
        if ($attributes['code'] === '' || trim($attributes['name']) === '') throw ValidationException::withMessages(['code' => 'Name and code must not be blank.']);
        if (Product::forCompany($context)->whereRaw('LOWER(code) = ?', [mb_strtolower($attributes['code'])])->where('is_active', true)
            ->when($ignoreProductId, fn ($q) => $q->where('id', '!=', $ignoreProductId))->exists()) {
            throw ValidationException::withMessages(['code' => 'An active item already uses this code in this company.']);
        }
        $this->validateMetadata($metadata + $attributes, $context);
        $guard = app(CompanyWriteGuard::class);
        foreach (['brand_id' => Brand::class, 'tax_id' => Tax::class, 'unit_id' => Unit::class,
            'sale_unit_id' => Unit::class, 'purchase_unit_id' => Unit::class] as $field => $model) {
            if (empty($attributes[$field])) continue;
            $table = (new $model)->getTable();
            if (app(\App\Support\Database\SchemaCache::class)->hasColumn(DB::connection(), $table, 'company_id')) {
                $guard->owned($model, $attributes[$field], $context, $field);
            } elseif (!$model::whereKey($attributes[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Select an existing master.']);
            }
        }
        if ($attributes['type'] === 'standard') {
            if (empty($attributes['unit_id'])) throw ValidationException::withMessages(['unit_id' => 'Choose a base unit.']);
            $prototype = (new Product)->forceFill(['company_id' => $context->companyId, 'unit_id' => $attributes['unit_id']]);
            foreach (['sale_unit_id', 'purchase_unit_id'] as $field) {
                if (empty($attributes[$field])) $attributes[$field] = $attributes['unit_id'];
                try { app(UomConversionService::class)->toBase($prototype, 1, (int) $attributes[$field]); }
                catch (\InvalidArgumentException $error) { throw ValidationException::withMessages([$field => $error->getMessage()]); }
            }
        }
        app(GstSetupService::class)->productCode($metadata['hsn_code'] ?? null,
            empty($attributes['category_id']) ? null : (int) $attributes['category_id'], $attributes['type'], $context);
        return $attributes;
    }

    public function applyMetadata(Product $product, array $data, CompanyContext $context): void
    {
        $this->validateMetadata($data, $context);
        $attributes = [];
        if (array_key_exists('tax_category_id', $data)) $attributes['tax_category_id'] = !empty($data['tax_category_id']) ? (int) $data['tax_category_id'] : null;
        if ($product->wasRecentlyCreated) {
            $attributes['hsn_code'] = app(GstSetupService::class)->productCode($data['hsn_code'] ?? null,
                $product->category_id ? (int) $product->category_id : null, $product->type, $context);
        } elseif (array_key_exists('hsn_code', $data)) $attributes['hsn_code'] = !empty($data['hsn_code']) ? preg_replace('/\s+/', '', $data['hsn_code']) : null;
        if (array_key_exists('negative_stock_policy', $data)) $attributes['negative_stock_policy'] = $data['negative_stock_policy'] ?: null;
        if (array_key_exists('product_type_id', $data)) $attributes['product_type_id'] = !empty($data['product_type_id']) ? (int) $data['product_type_id'] : null;

        // Optical specifications persistence and reset
        $isOpticalExplicitlyOff = isset($data['is_optical']) && empty($data['is_optical']);
        if ($isOpticalExplicitlyOff) {
            $attributes['base'] = null;
            $attributes['addition'] = null;
            $attributes['lr'] = null;
            $attributes['dia'] = null;
            $attributes['product_type_id'] = null;
        } else {
            if (array_key_exists('base', $data)) $attributes['base'] = $data['base'] ?: null;
            if (array_key_exists('addition', $data)) $attributes['addition'] = $data['addition'] ?: null;
            if (array_key_exists('lr', $data)) $attributes['lr'] = $data['lr'] ?: null;
            if (array_key_exists('dia', $data)) $attributes['dia'] = $data['dia'] ?: null;
        }

        if ($attributes) $product->forceFill($attributes)->save();
    }

    public function create(array $attributes, array $metadata, CompanyContext $context): Product
    {
        return DB::transaction(function () use ($attributes, $metadata, $context) {
            Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $attributes = $this->validateCreation($attributes, $metadata, $context);
            // Keep the existing normal-form representation across quick entry and import as well.
            $attributes['name'] = preg_replace('/[\n\r]/', '<br>', htmlspecialchars(trim($attributes['name']), ENT_QUOTES));
            $product = (new Product)->fill($attributes);
            $product->forceFill(['company_id' => $context->companyId, 'qty' => 0, 'is_active' => true])->save();
            $this->applyMetadata($product, $metadata, $context);
            return $product;
        });
    }

    /** Update an existing product's master attributes and GST/stock metadata inside company lock. */
    public function update(Product $product, array $attributes, array $metadata, CompanyContext $context): Product
    {
        return DB::transaction(function () use ($product, $attributes, $metadata, $context) {
            Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $locked = Product::forCompany($context)->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $attributes = $this->validateCreation($attributes, $metadata, $context, $locked->id);
            $attributes['name'] = preg_replace('/[\n\r]/', '<br>', htmlspecialchars(trim($attributes['name']), ENT_QUOTES));
            $locked->fill($attributes)->save();
            $this->applyMetadata($locked, $metadata, $context);
            return $locked->fresh();
        });
    }
}
