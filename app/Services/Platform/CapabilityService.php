<?php

namespace App\Services\Platform;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CapabilityService
{
    public function enabled(string $key, CompanyContext|int|null $company = null): bool
    {
        return (bool) ($this->snapshot($this->companyId($company))[$key]['enabled'] ?? false);
    }

    public function config(string $key, mixed $default = null, CompanyContext|int|null $company = null): mixed
    {
        return $this->snapshot($this->companyId($company))[$key]['config'] ?? $default;
    }

    public function dependencies(string $key): array
    {
        $definition = CapabilityCatalog::DEFINITIONS[$key] ?? null;
        if (!$definition) {
            throw ValidationException::withMessages(['capability' => 'Unknown capability.']);
        }
        return $definition[1];
    }

    public function assertEnabled(string $key, CompanyContext|int|null $company = null): void
    {
        if (!$this->enabled($key, $company)) {
            throw new AuthorizationException('Capability is disabled: '.$key);
        }
    }

    public function forNavigation(CompanyContext|int|null $company = null): array
    {
        if ($company === null && !request()->attributes->has(CompanyContext::class)) {
            try {
                $result = app(\App\Http\Middleware\ResolveCompanyContext::class)->handle(request(),
                    fn ($request) => $this->forNavigation($request->attributes->get(CompanyContext::class)));
                // FY setup redirects are handled by context-protected routes; no optional menu is shown before setup.
                return is_array($result) ? $result : [];
            } catch (\Throwable) {
                return [];
            }
        }
        $company ??= request()->attributes->get(CompanyContext::class);
        return array_keys(array_filter($this->snapshot($this->companyId($company)), fn ($state) => $state['enabled']));
    }

    public function enable(string $key, array $config = [], ?CompanyContext $context = null, ?int $actor = null): void
    {
        $this->change($context, $actor, function (array $states) use ($key, $config) {
            $this->dependencies($key);
            $states[$key] = ['enabled' => true, 'config' => $this->validateConfig($key, $config)];
            return $states;
        });
    }

    public function disable(string $key, ?CompanyContext $context = null, ?int $actor = null): void
    {
        $this->change($context, $actor, function (array $states) use ($key) {
            $this->dependencies($key);
            $states[$key]['enabled'] = false;
            return $states;
        });
    }

    public function applyProfile(string $key, ?CompanyContext $context = null, ?int $actor = null): void
    {
        $this->change($context, $actor, function () use ($key) {
            $profile = DB::table('business_profiles')->where('key', $key)->first();
            if (!$profile) {
                (new \Database\Seeders\CapabilitySeeder)->run();
                $profile = DB::table('business_profiles')->where('key', $key)->first();
            }
            if (!$profile) {
                throw ValidationException::withMessages(['profile' => 'Unknown business profile.']);
            }
            $states = [];
            foreach (DB::table('business_profile_capabilities as preset')
                ->join('capabilities', 'capabilities.id', '=', 'preset.capability_id')
                ->where('profile_id', $profile->id)->get() as $row) {
                $states[$row->key] = [
                    'enabled' => (bool) $row->default_enabled,
                    'config' => $this->validateConfig($row->key, json_decode($row->default_config_json ?? '{}', true) ?: []),
                ];
            }
            return $states;
        }, profileKey: $key);
    }

    /** Import is additive and never overrides an explicit company decision. */
    public function importLegacy(?CompanyContext $context = null, ?int $actor = null): void
    {
        $this->change($context, $actor, fn (array $states) => $states, importOnly: true);
    }

    public function snapshot(int $companyId): array
    {
        $states = DB::transactionLevel() > 0 ? $this->load($companyId)
            : Cache::remember($this->cacheKey($companyId), 300, fn () => $this->load($companyId));
        $licenses = app(LicenseService::class);
        // Cache configured states; license entitlements, activation gates, and dependency checks always apply on read.
        foreach ($states as $key => &$state) {
            if (!$licenses->allowsCapability($key, $companyId)
                || !$this->dependenciesEnabled($key, $states)
                || (!$this->optionalActivationReady() && !$this->exemptFromActivationGate($key))) {
                $state['enabled'] = false;
            }
        }
        unset($state);
        return $states;
    }

    public function forgetCompany(int $companyId): void
    {
        Cache::forget($this->cacheKey($companyId));
    }

    /**
     * Synchronize company_capabilities with developer-assigned module licenses (preserving saved config).
     *
     * @param list<string> $licensedKeys
     */
    public function syncFromLicense(int $companyId, array $licensedKeys, int $actor): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('capabilities')
            || !\Illuminate\Support\Facades\Schema::hasTable('company_capabilities')) {
            return;
        }
        $ids = DB::table('capabilities')->pluck('id', 'key');
        foreach (CapabilityCatalog::DEFINITIONS as $key => $definition) {
            if (!isset($ids[$key])) {
                continue;
            }
            $enabled = ($definition[2] ?? false) || in_array($key, $licensedKeys, true);
            $existing = DB::table('company_capabilities')
                ->where('company_id', $companyId)
                ->where('capability_id', $ids[$key])
                ->first();
            $newlyEnabled = $enabled && !$existing?->enabled;
            DB::table('company_capabilities')->updateOrInsert(
                ['company_id' => $companyId, 'capability_id' => $ids[$key]],
                [
                    'enabled' => $enabled,
                    'config_json' => $existing?->config_json ?? json_encode(null),
                    'enabled_at' => $newlyEnabled ? now() : $existing?->enabled_at,
                    'enabled_by' => $newlyEnabled ? $actor : $existing?->enabled_by,
                    'created_at' => $existing?->created_at ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
        DB::afterCommit(fn () => $this->forgetCompany($companyId));
    }

    private function load(int $companyId): array
    {
        $legacy = app(LegacyModuleAdapter::class)->forCompany($companyId);
        $states = [];
        foreach (CapabilityCatalog::DEFINITIONS as $key => $definition) {
            $states[$key] = ['enabled' => ($definition[2] ?? false) || in_array($key, $legacy, true)
                || in_array($key, CapabilityCatalog::SWITCHABLE, true), 'config' => null];
        }
        foreach (DB::table('company_capabilities as state')->join('capabilities', 'capabilities.id', '=', 'state.capability_id')
            ->where('company_id', $companyId)->get() as $row) {
            $states[$row->key] = ['enabled' => (bool) $row->enabled, 'config' => json_decode($row->config_json ?? 'null', true)];
        }
        return $states;
    }

    private function dependenciesEnabled(string $key, array $states): bool
    {
        foreach ($this->dependencies($key) as $dependency) {
            if (empty($states[$dependency]['enabled']) || !$this->dependenciesEnabled($dependency, $states)) {
                return false;
            }
        }
        return true;
    }

    private function change(?CompanyContext $context, ?int $actor, callable $change, bool $importOnly = false, ?string $profileKey = null): void
    {
        $resolver = app(CompanyContextResolver::class);
        $actor ??= auth()->id();
        $context = $resolver->forActor($context, $actor);
        if (!$resolver->canManageFinancialYears($actor, $context->companyId)) {
            throw new AuthorizationException('Company administrator required to configure capabilities.');
        }
        DB::transaction(function () use ($context, $actor, $change, $importOnly, $profileKey) {
            \App\Models\Company::whereKey($context->companyId)->lockForUpdate()->firstOrFail();
            $previousStates = $this->load($context->companyId);
            $states = $change($previousStates);
            $licenses = app(LicenseService::class);
            $isDeveloper = MenuSwitches::isDeveloper();
            foreach (CapabilityCatalog::DEFINITIONS as $key => $definition) {
                if (($definition[2] ?? false) && empty($states[$key]['enabled'])) {
                    throw ValidationException::withMessages(['capability' => 'Core capabilities cannot be disabled.']);
                }
                if (!$importOnly && !$isDeveloper && !empty($states[$key]['enabled']) && !$licenses->allowsCapability($key, $context->companyId)) {
                    if ($profileKey !== null) {
                        $states[$key]['enabled'] = false;
                    } else {
                        throw new AuthorizationException('Module "'.$definition[0].'" is not licensed for this company. Ask your platform developer to enable it.');
                    }
                }
                if (!$importOnly && !$this->optionalActivationReady() && !$this->exemptFromActivationGate($key)
                    && !empty($states[$key]['enabled']) && empty($previousStates[$key]['enabled'])) {
                    throw ValidationException::withMessages(['capability' => 'Optional activation waits for full Phase 1 isolation acceptance.']);
                }
                if (!empty($states[$key]['enabled']) && !$this->dependenciesEnabled($key, $states)) {
                    if ($profileKey !== null && !$isDeveloper) {
                        $states[$key]['enabled'] = false;
                    } else {
                        throw ValidationException::withMessages(['capability' => $key.' requires '.implode(', ', $definition[1]).'.']);
                    }
                }
            }
            $ids = DB::table('capabilities')->pluck('id', 'key');
            foreach ($states as $key => $state) {
                $existing = DB::table('company_capabilities')->where('company_id', $context->companyId)->where('capability_id', $ids[$key])->first();
                $newlyEnabled = $state['enabled'] && !$existing?->enabled;
                DB::table('company_capabilities')->updateOrInsert([
                    'company_id' => $context->companyId, 'capability_id' => $ids[$key],
                ], [
                    'enabled' => $state['enabled'], 'config_json' => json_encode($state['config']),
                    'enabled_at' => $newlyEnabled ? now() : $existing?->enabled_at,
                    'enabled_by' => $newlyEnabled ? $actor : $existing?->enabled_by,
                    'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
            if ($profileKey && \Illuminate\Support\Facades\Schema::hasTable('company_industry_settings')) {
                app(\App\Services\Industry\IndustryProfileService::class)->installDefaults($profileKey, $context, $actor);
            }
            if ($previousStates !== $states && \Illuminate\Support\Facades\Schema::hasTable('company_setup_audits')) {
                DB::table('company_setup_audits')->insert(['company_id' => $context->companyId, 'actor_id' => $actor,
                    'action' => 'capabilities_changed', 'before_json' => json_encode($previousStates, JSON_THROW_ON_ERROR),
                    'after_json' => json_encode($states, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
            DB::afterCommit(fn () => Cache::forget($this->cacheKey($context->companyId)));
        }, 3);
    }

    private function validateConfig(string $key, array $config): array
    {
        $rules = CapabilityCatalog::CONFIGURATION[$key] ?? [];
        if (array_diff(array_keys($config), array_keys($rules))) {
            throw ValidationException::withMessages(['config' => 'Unsupported capability configuration field.']);
        }
        return Validator::make($config, $rules)->validate();
    }

    private function companyId(CompanyContext|int|null $company): int
    {
        if ($company instanceof CompanyContext) {
            return $company->companyId;
        }
        if ($company !== null) {
            return $company;
        }
        return app(CompanyContextResolver::class)->forActor()->companyId;
    }

    private function cacheKey(int $companyId): string
    {
        $connection = DB::connection();
        $identity = [$connection->getName(), $connection->getConfig('host'), $connection->getConfig('port'), $connection->getDatabaseName()];
        return 'capabilities:'.hash('sha256', json_encode($identity)).':'.$companyId;
    }

    private function exemptFromActivationGate(string $key): bool
    {
        return (CapabilityCatalog::DEFINITIONS[$key][2] ?? false) || in_array($key, CapabilityCatalog::SWITCHABLE, true);
    }

    protected function optionalActivationReady(): bool
    {
        return (bool) config('app.erp_optional_activation_ready', CapabilityCatalog::OPTIONAL_ACTIVATION_READY);
    }
}
