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
        Validator::make($data, ['negative_stock_policy' => 'nullable|in:allow,warn,block',
            'product_usage' => Product::USAGE_RULE,
            'category_id' => 'nullable|integer|min:1', 'tax_category_id' => 'nullable|integer|min:1',
            'hsn_code' => 'nullable|string|max:8'])->validate();
        if (!empty($data['category_id'])) app(CompanyWriteGuard::class)->owned(Category::class, $data['category_id'], $context, 'category_id');
        if (!empty($data['tax_category_id']) && !DB::table('tax_categories')->where('company_id', $context->companyId)->where('id', $data['tax_category_id'])->exists()) {
            throw ValidationException::withMessages(['tax_category_id' => 'Select a GST rate of this company.']);
        }
        if (!empty($data['hsn_code'])) {
            $gst = app(GstSetupService::class);
            $gst->validateCode(preg_replace('/\s+/', '', $data['hsn_code']), $gst->kindFor($data['type'] ?? 'standard'));
        }
    }

    public function validateCreation(array $attributes, array $metadata, CompanyContext $context, ?int $ignoreProductId = null): array
    {
        Validator::make($attributes, ['name' => 'required|string|max:255', 'code' => ['required', 'string', 'max:255', 'not_regex:/[<>]/'],
            'type' => 'required|in:standard,service,digital,combo', 'cost' => 'nullable|numeric|min:0', 'price' => 'nullable|numeric|min:0',
            'brand_id' => 'nullable|integer|min:0', 'tax_id' => 'nullable|integer|min:0', 'unit_id' => 'nullable|integer|min:0',
            'sale_unit_id' => 'nullable|integer|min:0', 'purchase_unit_id' => 'nullable|integer|min:0'])->validate();
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
