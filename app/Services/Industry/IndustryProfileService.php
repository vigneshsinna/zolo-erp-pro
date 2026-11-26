<?php

namespace App\Services\Industry;

use App\Models\Product;
use App\Services\ERP\CompanyWriteGuard;
use App\Services\Operations\OperationPosting;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

class IndustryProfileService
{
    public function apply(string $profile, ?string $subtype, CompanyContext $context, int $actor): array
    {
        app(OperationPosting::class)->authorize('core.inventory', 'profiles.manage', $context, $actor);
        $resolver = app(CompanyContextResolver::class);
        $resolver->forActor($context, $actor);
        if (!$resolver->canManageFinancialYears($actor, $context->companyId)) throw new \Illuminate\Auth\Access\AuthorizationException('Company administrator required.');
        $choices = IndustryCatalog::SUBTYPES[$profile] ?? null;
        $subtype ??= $choices ? array_key_first($choices) : null;
        if (!$choices || !array_key_exists($subtype, $choices)) throw ValidationException::withMessages(['profile' => 'Select a supported profile and subtype.']);
        return DB::transaction(function () use ($profile, $subtype, $choices, $context, $actor) {
            app(CapabilityService::class)->applyProfile($profile, $context, $actor);
            foreach ($choices[$subtype] as $capability) app(CapabilityService::class)->enable($capability, [], $context, $actor);
            $this->useSubtype($profile, $subtype, $context->companyId);
            app(OperationPosting::class)->audit('profile_apply', $context->companyId, $context, $actor, compact('profile', 'subtype'));
            return $this->settings($context);
        }, 3);
    }

    /** Called within CapabilityService's profile transaction, including the existing setup API. */
    public function installDefaults(string $profile, CompanyContext $context, int $actor): void
    {
        $subtype = array_key_first(IndustryCatalog::SUBTYPES[$profile]);
        DB::table('company_industry_settings')->updateOrInsert(['company_id' => $context->companyId], [
            'profile_key' => $profile, 'subtype' => $subtype,
            'settings_json' => json_encode(IndustryCatalog::settings($profile, $subtype), JSON_THROW_ON_ERROR), 'updated_by' => $actor, 'updated_at' => now(), 'created_at' => now(),
        ]);
        DB::table('product_attribute_definitions')->where('company_id', $context->companyId)->update(['enabled' => false]);
        foreach (IndustryCatalog::ATTRIBUTES[$profile] as $key => [$label, $type]) DB::table('product_attribute_definitions')->updateOrInsert([
            'company_id' => $context->companyId, 'key' => $key], ['label' => $label, 'type' => $type, 'profile_key' => $profile, 'enabled' => true]);
        $this->useSubtype($profile, $subtype, $context->companyId);
        self::ensureProcesses($context->companyId, $profile);
        self::alignProcesses($context->companyId, $profile);
        self::ensureUnits($context->companyId, $profile);
        // Presets never change existing unit factors, stock flags, product values or company valuation policies.
    }

    /** Records the business type with its settings and shows only the profile attributes that type uses. */
    public function useSubtype(string $profile, string $subtype, int $companyId): void
    {
        DB::table('company_industry_settings')->where('company_id', $companyId)->update(['subtype' => $subtype,
            'settings_json' => json_encode(IndustryCatalog::settings($profile, $subtype), JSON_THROW_ON_ERROR)]);
        $hidden = IndustryCatalog::SUBTYPE_HIDDEN_ATTRIBUTES[$profile][$subtype] ?? [];
        foreach (array_keys(IndustryCatalog::ATTRIBUTES[$profile]) as $key) {
            DB::table('product_attribute_definitions')->where('company_id', $companyId)->where('key', $key)->update(['enabled' => !in_array($key, $hidden, true)]);
        }
    }

    /** Adds the industry's preset processes the company does not have yet; existing ones (and their edits) are kept. */
    public static function ensureProcesses(int $companyId, string $profile): void
    {
        if (!Schema::hasTable('process_types')) return;
        foreach (IndustryCatalog::PROCESSES[$profile] ?? [] as [$code, $name, $loss, $conversion]) {
            if (!DB::table('process_types')->where('company_id', $companyId)->where('code', $code)->exists()) {
                DB::table('process_types')->insert(['company_id' => $companyId, 'code' => $code, 'name' => $name,
                    'is_active' => $code !== 'BLEACHING', 'max_loss_percent' => $loss, 'conversion' => $conversion, 'quantity_basis' => $profile === 'timber' ? 'volume_cbm' : 'base_qty',
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Offers only this industry's preset processes: untouched presets of other industries (same code and name) are
     * deactivated, this industry's are reactivated. Orders already using a process keep it; custom processes are never touched.
     */
    public static function alignProcesses(int $companyId, string $profile): void
    {
        if (!Schema::hasTable('process_types')) return;
        $own = array_column(IndustryCatalog::PROCESSES[$profile] ?? [], 1, 0);
        foreach (IndustryCatalog::PROCESSES as $processes) {
            foreach ($processes as [$code, $name]) {
                // Bleaching starts disabled until its conversion/loss policy is configured and activated.
                if ($code === 'BLEACHING' && isset($own[$code])) continue;
                DB::table('process_types')->where('company_id', $companyId)->where('code', $code)->where('name', $name)
                    ->update(['is_active' => ($own[$code] ?? null) === $name, 'updated_at' => now()]);
            }
        }
    }

    /** Adds the industry's base units (e.g. MTR for textile) to the company when no unit with that code or name exists. */
    public static function ensureUnits(int $companyId, string $profile): int
    {
        if (!Schema::hasTable('units') || !Schema::hasColumn('units', 'company_id') || !Schema::hasColumn('units', 'unit_code')) return 0;
        $columns = array_flip(Schema::getColumnListing('units')); $added = 0;
        foreach (IndustryCatalog::SETTINGS[$profile]['units'] ?? [] as $code) {
            [$name, $aliases] = IndustryCatalog::UNITS[$code] ?? [ucfirst(strtolower($code)), []];
            $known = array_map('strtolower', [$code, $name, ...$aliases]);
            $exists = DB::table('units')->where('company_id', $companyId)->get(['unit_code', 'unit_name'])
                ->contains(fn ($unit) => in_array(strtolower((string) $unit->unit_code), $known, true) || in_array(strtolower((string) ($unit->unit_name ?? '')), $known, true));
            if ($exists) continue;
            DB::table('units')->insert(array_intersect_key(['company_id' => $companyId, 'unit_code' => $code, 'unit_name' => $name, 'name' => $name, 'base_unit' => null,
                'operator' => '*', 'operation_value' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], $columns));
            $added++;
        }
        return $added;
    }

    /** Industry wording on shared screens (textile: Godown, Material DC, Job Work GRN); $default when the industry has none. */
    public static function label(string $key, string $default): string
    {
        try {
            $request = request();
            if (!$request->attributes->has('industry_labels')) {
                $context = $request->attributes->get(CompanyContext::class) ?? app(CompanyContextResolver::class)->forActor();
                $request->attributes->set('industry_labels', app(\App\Support\Database\SchemaCache::class)->hasTable(DB::connection(), 'company_industry_settings')
                    ? (app(self::class)->settings($context)['settings']['labels'] ?? []) : []);
            }
            return $request->attributes->get('industry_labels')[$key] ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /** The active company's resolved profile key (e.g. 'textile', 'opticals', 'general_trading'). */
    public static function currentProfileKey(?CompanyContext $context = null): string
    {
        try {
            $request = request();
            $context ??= $request?->attributes->get(CompanyContext::class);
            $companyId = $context?->companyId;
            if (!$companyId && function_exists('session')) {
                $companyId = session('company_id');
            }
            if (!$companyId && auth()->check()) {
                try {
                    $companyId = app(CompanyContextResolver::class)->forActor()?->companyId;
                } catch (\Throwable) {}
            }

            $cacheKey = 'industry_profile_key_' . ($companyId ?? 'none');
            if ($request && $request->attributes->has($cacheKey)) {
                return (string) $request->attributes->get($cacheKey);
            }

            if ($companyId && Schema::hasTable('company_industry_settings')) {
                $profile = DB::table('company_industry_settings')->where('company_id', $companyId)->value('profile_key');
                if ($profile) {
                    $request?->attributes->set($cacheKey, $profile);
                    return $profile;
                }
            }
            // Standalone or legacy database fallback where company_industry_settings table does not exist
            if (Schema::hasTable('general_settings')) {
                $general = DB::table('general_settings')->first();
                if ($general && (
                    stripos($general->site_title ?? '', 'lenz') !== false ||
                    stripos($general->company_name ?? '', 'lenz') !== false ||
                    stripos($general->site_title ?? '', 'optic') !== false ||
                    stripos($general->company_name ?? '', 'optic') !== false
                )) {
                    $request?->attributes->set($cacheKey, 'opticals');
                    return 'opticals';
                }
            }
            if (Schema::hasTable('product_types') && Schema::hasColumn('products', 'base')) {
                $lensQuery = DB::table('product_types')->where(function ($q) {
                    $q->where('name', 'like', '%Progressive%')
                      ->orWhere('name', 'like', '%Bifocal%')
                      ->orWhere('name', 'like', '%Single Vision%')
                      ->orWhere('name', 'like', '%KBF%');
                });
                if ($companyId && Schema::hasColumn('product_types', 'company_id')) {
                    $lensQuery->where('company_id', $companyId);
                }
                if ($lensQuery->exists()) {
                    $request?->attributes->set($cacheKey, 'opticals');
                    return 'opticals';
                }
            }
            $request?->attributes->set($cacheKey, 'general_trading');
            return 'general_trading';
        } catch (\Throwable) {
            return 'general_trading';
        }
    }

    public static function isOptical(?CompanyContext $context = null, mixed $documentOrItem = null): bool
    {
        $profile = static::currentProfileKey($context);
        if (in_array($profile, ['opticals', 'optical', 'lens', 'optician'], true)) {
            return true;
        }

        try {
            $companyId = $context?->companyId ?: (function_exists('session') ? session('company_id') : null);
            if ($companyId && Schema::hasTable('companies')) {
                $company = DB::table('companies')->where('id', $companyId)->first(['legal_name', 'trade_name']);
                if ($company && (
                    stripos($company->legal_name ?? '', 'lenz') !== false ||
                    stripos($company->trade_name ?? '', 'lenz') !== false ||
                    stripos($company->legal_name ?? '', 'optic') !== false ||
                    stripos($company->trade_name ?? '', 'optic') !== false
                )) {
                    return true;
                }
            }
        } catch (\Throwable) {}

        if ($documentOrItem !== null) {
            if (is_object($documentOrItem)) {
                if (!empty($documentOrItem->end_user_name) || !empty($documentOrItem->fitting_charge) || !empty($documentOrItem->tinting_cost)) {
                    return true;
                }
                if (isset($documentOrItem->sph) && ($documentOrItem->sph !== null && $documentOrItem->sph !== '')) {
                    return true;
                }
                if (isset($documentOrItem->productSales)) {
                    foreach ($documentOrItem->productSales as $item) {
                        if (!empty($item->sph) || !empty($item->cyl) || !empty($item->axis) || !empty($item->addition) || !empty($item->lr)) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    public static function isTextile(?CompanyContext $context = null): bool
    {
        return static::currentProfileKey($context) === 'textile';
    }

    public function settings(CompanyContext $context): array
    {
        $row = DB::table('company_industry_settings')->where('company_id', $context->companyId)->first();
        return $row ? ['profile' => $row->profile_key, 'subtype' => $row->subtype,
            'settings' => json_decode($row->settings_json, true, 512, JSON_THROW_ON_ERROR)] : ['profile' => 'general_trading', 'subtype' => 'trading', 'settings' => IndustryCatalog::SETTINGS['general_trading']];
    }

    public function attributes(int $productId, CompanyContext $context, int $actor, ?array $values = null): array
    {
        app(CompanyWriteGuard::class)->context($context, $actor);
        app(\App\Services\Commercial\CommercialPermission::class)->assert($values === null ? 'products-index' : 'products-edit', $context, $actor);
        $product = Product::forCompany($context)->findOrFail($productId);
        return DB::transaction(function () use ($product, $values, $context, $actor) {
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $definitions = DB::table('product_attribute_definitions')->where('company_id', $context->companyId)->where('enabled', true)->get()->keyBy('key');
            if ($values !== null) {
                if (array_diff(array_keys($values), $definitions->keys()->all())) throw ValidationException::withMessages(['attributes' => 'Only enabled profile attributes can be written.']);
                foreach ($values as $key => $value) {
                    $definition = $definitions[$key];
                    $rule = match ($definition->type) { 'number' => 'numeric|min:0|max:999999999', 'integer' => 'integer|min:0|max:1200', default => 'string|max:255' };
                    validator(['value' => $value], ['value' => 'required|'.$rule])->validate();
                    DB::table('product_attribute_values')->updateOrInsert(['product_id' => $product->id, 'definition_id' => $definition->id],
                        ['company_id' => $context->companyId, 'value_json' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                }
                app(OperationPosting::class)->audit('product_attributes', $product->id, $context, $actor, $values);
            }
            $saved = DB::table('product_attribute_values')->where('company_id', $context->companyId)->where('product_id', $product->id)->pluck('value_json', 'definition_id');
            return $definitions->map(fn ($definition) => ['key' => $definition->key, 'label' => $definition->label, 'type' => $definition->type,
                'value' => isset($saved[$definition->id]) ? json_decode($saved[$definition->id], true) : null])->values()->all();
        });
    }
}
