<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\Platform\CompanyLicense;
use App\Models\Platform\CompanyLicenseAudit;
use App\Models\Platform\CompanyLicenseModule;
use App\Models\Platform\SaasPlan;
use App\Services\Industry\IndustryCatalog;
use App\Support\Database\SchemaCache;
use Carbon\CarbonImmutable;
use Database\Seeders\SaasPlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Multi-tenant SaaS licensing and module entitlement engine for single-DB multi-company deployments.
 *
 * Enforces subscription lifecycle (trial, active, grace, suspended, expired), all 45 CapabilityCatalog
 * modules (37 ERP + 8 AI) with dependency resolution and per-module expiry, ERP quotas (users,
 * branches, warehouses, monthly invoices), and AI quotas (credits, media pages, concurrent runs,
 * daily token limit, read-only safety lock).
 */
class LicenseService
{
    public const STATUSES = ['trial', 'active', 'grace', 'suspended', 'expired', 'cancelled'];
    public const CYCLES = ['trial', 'monthly', 'yearly', 'lifetime', 'custom'];

    public const MODULE_GROUPS = [
        'core' => 'Core ERP Modules',
        'inventory' => 'Inventory & Tracking',
        'sales' => 'Sales, POS & Channels',
        'manufacturing' => 'Manufacturing & BOM',
        'operations' => 'Operations & Verticals',
        'service' => 'Service, Repair & Warranty',
        'hrm' => 'Human Resources (HRM)',
        'printing' => 'Printing & Hardware',
        'communications' => 'Communications',
        'integrations' => 'API & Integrations',
        'ai' => 'AI Operating Layer & Copilot',
    ];

    public function tablesReady(): bool
    {
        $conn = DB::connection();
        $schema = app(SchemaCache::class);
        return $schema->hasTable($conn, 'saas_plans')
            && $schema->hasTable($conn, 'company_licenses')
            && $schema->hasTable($conn, 'company_license_modules');
    }

    public function ensurePlansSeeded(): void
    {
        if (!app(SchemaCache::class)->hasTable(DB::connection(), 'saas_plans')) {
            return;
        }
        if (!DB::table('saas_plans')->exists()) {
            (new SaasPlanSeeder)->run();
        }
    }

    /**
     * Structured catalog of all 45 CapabilityCatalog modules (37 ERP + 8 AI) with group, dependencies, profiles, and enforcement scope.
     *
     * @return list<array<string, mixed>>
     */
    public function moduleCatalog(): array
    {
        $profileMap = [];
        foreach (CapabilityCatalog::PROFILES as $profileKey => [$profileName, $caps]) {
            foreach ($caps as $cap) {
                $profileMap[$cap][] = $profileName;
            }
        }
        foreach (IndustryCatalog::SUBTYPES as $profileKey => $subtypes) {
            $profileName = CapabilityCatalog::PROFILES[$profileKey][0] ?? $profileKey;
            foreach ($subtypes as $caps) {
                foreach ($caps as $cap) {
                    $profileMap[$cap][] = $profileName;
                }
            }
        }

        $items = [];
        foreach (CapabilityCatalog::DEFINITIONS as $key => $definition) {
            $groupKey = explode('.', $key)[0];
            $items[] = [
                'key' => $key,
                'name' => $definition[0],
                'group_key' => $groupKey,
                'group_label' => self::MODULE_GROUPS[$groupKey] ?? ucfirst($groupKey),
                'dependencies' => $definition[1] ?? [],
                'is_core' => (bool) ($definition[2] ?? false),
                'is_switchable' => in_array($key, CapabilityCatalog::SWITCHABLE, true),
                'profiles' => array_values(array_unique($profileMap[$key] ?? [])),
            ];
        }

        return $items;
    }

    public function snapshot(int $companyId): array
    {
        if (!$this->tablesReady()) {
            return $this->unconstrainedSnapshot($companyId);
        }

        return DB::transactionLevel() > 0
            ? $this->buildSnapshot($companyId)
            : Cache::remember($this->cacheKey($companyId), 300, fn () => $this->buildSnapshot($companyId));
    }

    public function allowsCapability(string $key, int $companyId): bool
    {
        if (!isset(CapabilityCatalog::DEFINITIONS[$key])) {
            return false;
        }
        $snap = $this->snapshot($companyId);
        if ($snap['is_unconstrained']) {
            return true;
        }
        if ($snap['is_blocked']) {
            return false;
        }

        return (bool) ($snap['modules'][$key]['licensed'] ?? false);
    }

    public function isBlocked(int $companyId): bool
    {
        return (bool) ($this->snapshot($companyId)['is_blocked'] ?? false);
    }

    /** Live uncached quota counts for the company. */
    public function quotaUsage(int $companyId): array
    {
        $conn = DB::connection();
        $schema = app(SchemaCache::class);
        $company = Company::find($companyId);
        $tz = $company && in_array($company->timezone, timezone_identifiers_list(), true)
            ? $company->timezone
            : config('app.timezone', 'UTC');
        $monthStart = CarbonImmutable::now($tz)->startOfMonth()->startOfDay()->toDateTimeString();
        $monthEnd = CarbonImmutable::now($tz)->endOfMonth()->endOfDay()->toDateTimeString();

        $users = 0;
        if ($schema->hasTable($conn, 'company_user') && $schema->hasTable($conn, 'users')) {
            $query = DB::table('company_user')
                ->join('users', 'users.id', '=', 'company_user.user_id')
                ->where('company_user.company_id', $companyId)
                ->where('users.is_deleted', false)
                ->where('users.is_active', true);
            if ($schema->hasColumn($conn, 'users', 'is_developer')) {
                $query->where('users.is_developer', false);
            }
            $users = (int) $query->distinct()->count('company_user.user_id');
        }

        $branches = $schema->hasTable($conn, 'company_branches')
            ? (int) DB::table('company_branches')->where('company_id', $companyId)->where('is_active', true)->count()
            : 0;

        $warehouses = 0;
        if ($schema->hasTable($conn, 'warehouses') && $schema->hasColumn($conn, 'warehouses', 'company_id')) {
            $wQuery = DB::table('warehouses')->where('company_id', $companyId);
            if ($schema->hasColumn($conn, 'warehouses', 'is_active')) {
                $wQuery->where('is_active', true);
            }
            if ($schema->hasColumn($conn, 'warehouses', 'external_job_order_id')) {
                $wQuery->whereNull('external_job_order_id');
            }
            $warehouses = (int) $wQuery->count();
        }

        $monthlyInvoices = 0;
        if ($schema->hasTable($conn, 'sales') && $schema->hasColumn($conn, 'sales', 'company_id')) {
            $sQuery = DB::table('sales')
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd]);
            if ($schema->hasColumn($conn, 'sales', 'deleted_at')) {
                $sQuery->whereNull('deleted_at');
            }
            if ($schema->hasColumn($conn, 'sales', 'reversed_at')) {
                $sQuery->whereNull('reversed_at');
            }
            $monthlyInvoices = (int) $sQuery->count();
        }

        $aiCreditsUsed = 0;
        if ($schema->hasTable($conn, 'ai_credit_ledger') && $schema->hasColumn($conn, 'ai_credit_ledger', 'company_id')) {
            $aiCreditsUsed = abs((int) DB::table('ai_credit_ledger')
                ->where('company_id', $companyId)
                ->where('kind', 'usage_debit')
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('amount_credits'));
        }

        $aiMediaPagesUsed = 0;
        if ($schema->hasTable($conn, 'ai_media_assets') && $schema->hasColumn($conn, 'ai_media_assets', 'company_id')) {
            $mQuery = DB::table('ai_media_assets')
                ->where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd]);
            if ($schema->hasColumn($conn, 'ai_media_assets', 'deleted_at')) {
                $mQuery->whereNull('deleted_at');
            }
            $aiMediaPagesUsed = (int) $mQuery->selectRaw('COALESCE(SUM(COALESCE(page_count, 1)), 0) as total_pages')->value('total_pages');
        }

        $aiConcurrentRuns = 0;
        if ($schema->hasTable($conn, 'ai_runs') && $schema->hasColumn($conn, 'ai_runs', 'company_id')) {
            $aiConcurrentRuns = (int) DB::table('ai_runs')
                ->where('company_id', $companyId)
                ->whereIn('status', ['queued', 'running', 'processing'])
                ->count();
        }

        $aiDailyTokensUsed = 0;
        if ($schema->hasTable($conn, 'ai_usage_counters') && $schema->hasColumn($conn, 'ai_usage_counters', 'company_id')) {
            $todayDate = CarbonImmutable::now($tz)->toDateString();
            $aiDailyTokensUsed = (int) DB::table('ai_usage_counters')
                ->where('company_id', $companyId)
                ->where('date', $todayDate)
                ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total_tokens')
                ->value('total_tokens');
        }

        return [
            'users' => $users,
            'branches' => $branches,
            'warehouses' => $warehouses,
            'monthly_invoices' => $monthlyInvoices,
            'ai_credits_used' => $aiCreditsUsed,
            'ai_media_pages_used' => $aiMediaPagesUsed,
            'ai_concurrent_runs' => $aiConcurrentRuns,
            'ai_daily_tokens_used' => $aiDailyTokensUsed,
        ];
    }

    /** Normalized AI subscription entitlement payload compatible with SubscriptionEntitlementResolver. */
    public function aiEntitlement(int $companyId): array
    {
        $snap = $this->snapshot($companyId);
        $aiModules = [];
        foreach (CapabilityCatalog::DEFINITIONS as $key => $def) {
            if (str_starts_with($key, 'ai.')) {
                $aiModules[$key] = !empty($snap['modules'][$key]['licensed']);
            }
        }
        $copilotActive = $snap['is_usable'] && !empty($aiModules['ai.copilot']);

        return [
            'company_id' => $companyId,
            'is_active' => $copilotActive,
            'is_blocked' => $snap['is_blocked'],
            'status' => $snap['status'],
            'plan_sku' => $snap['plan']['code'] ?? ($snap['is_unconstrained'] ? 'enterprise' : 'custom'),
            'included_monthly_credits' => $snap['ai_limits']['monthly_credits'] ?? null,
            'monthly_media_pages' => $snap['ai_limits']['monthly_media_pages'] ?? null,
            'max_concurrent_runs' => $snap['ai_limits']['max_concurrent_runs'] ?? null,
            'daily_token_limit' => $snap['ai_limits']['daily_token_limit'] ?? null,
            'read_only' => ($snap['ai_limits']['read_only'] ?? true) || empty($aiModules['ai.actions']),
            'expires_at' => $snap['expires_at'],
            'modules' => $aiModules,
        ];
    }

    public function assertLicenseActive(int $companyId): void
    {
        $snap = $this->snapshot($companyId);
        if ($snap['is_blocked']) {
            throw new AuthorizationException($snap['block_reason'] ?: 'Company license is not active.');
        }
    }

    public function assertCanAddUser(int $companyId): void
    {
        $this->assertQuota($companyId, 'users', 'User seat limit reached for this company license.');
    }

    public function assertCanAddBranch(int $companyId): void
    {
        $this->assertQuota($companyId, 'branches', 'Branch limit reached for this company license.');
    }

    public function assertCanAddWarehouse(int $companyId): void
    {
        $this->assertQuota($companyId, 'warehouses', 'Warehouse / godown limit reached for this company license.');
    }

    public function assertCanIssueInvoice(int $companyId): void
    {
        $this->assertQuota($companyId, 'monthly_invoices', 'Monthly sales invoice quota reached for this company license.');
    }

    private function assertQuota(int $companyId, string $resource, string $baseMessage): void
    {
        $snap = $this->snapshot($companyId);
        if ($snap['is_unconstrained']) {
            return;
        }
        $this->assertLicenseActive($companyId);
        $limit = $snap['limits'][$resource] ?? null;
        if ($limit === null) {
            return;
        }
        $used = $this->quotaUsage($companyId)[$resource] ?? 0;
        if ($used >= $limit) {
            throw ValidationException::withMessages([
                'license' => $baseMessage." ({$used}/{$limit} used). Upgrade plan or increase quota in Developer Portal.",
            ]);
        }
    }

    public function assignPlan(int $companyId, int|string|null $planIdOrCode, array $options, int $actor): CompanyLicense
    {
        $this->ensurePlansSeeded();
        $company = Company::findOrFail($companyId);
        $plan = $this->resolvePlan($planIdOrCode);

        return DB::transaction(function () use ($company, $plan, $options, $actor) {
            $existing = CompanyLicense::where('company_id', $company->id)->lockForUpdate()->first();
            $before = $existing?->toArray();

            $cycle = (string) ($options['billing_cycle'] ?? $plan?->billing_cycle ?? 'monthly');
            if (!in_array($cycle, self::CYCLES, true)) {
                $cycle = 'monthly';
            }
            $startsAt = $options['starts_at'] ?? CarbonImmutable::now('UTC')->toDateString();
            $expiresAt = array_key_exists('expires_at', $options)
                ? ($options['expires_at'] ?: null)
                : $this->defaultExpiryDate($cycle, $startsAt, $plan?->trial_days ?? 14);
            $graceDays = $plan?->grace_days ?? 7;
            $graceEndsAt = array_key_exists('grace_ends_at', $options)
                ? ($options['grace_ends_at'] ?: null)
                : ($expiresAt ? CarbonImmutable::parse($expiresAt)->addDays($graceDays)->toDateString() : null);

            $status = (string) ($options['status'] ?? ($cycle === 'trial' ? 'trial' : 'active'));
            if (!in_array($status, self::STATUSES, true)) {
                $status = 'active';
            }

            $license = CompanyLicense::updateOrCreate(
                ['company_id' => $company->id],
                [
                    'saas_plan_id' => $plan?->id,
                    'license_key' => $existing?->license_key ?: $this->generateLicenseKey(),
                    'status' => $status,
                    'billing_cycle' => $cycle,
                    'starts_at' => $startsAt,
                    'expires_at' => $expiresAt,
                    'grace_ends_at' => $graceEndsAt,
                    'suspended_at' => $status === 'suspended' ? now() : null,
                    'suspension_reason' => $status === 'suspended' ? ($options['suspension_reason'] ?? 'Suspended by developer') : null,
                    'max_users' => $this->nullableInt($options['max_users'] ?? null),
                    'max_branches' => $this->nullableInt($options['max_branches'] ?? null),
                    'max_warehouses' => $this->nullableInt($options['max_warehouses'] ?? null),
                    'max_monthly_invoices' => $this->nullableInt($options['max_monthly_invoices'] ?? null),
                    'ai_monthly_credits' => $this->nullableQuotaInt($options['ai_monthly_credits'] ?? null),
                    'ai_monthly_media_pages' => $this->nullableQuotaInt($options['ai_monthly_media_pages'] ?? null),
                    'ai_max_concurrent_runs' => $this->nullableQuotaInt($options['ai_max_concurrent_runs'] ?? null),
                    'ai_daily_token_limit' => $this->nullableQuotaInt($options['ai_daily_token_limit'] ?? null),
                    'ai_read_only' => array_key_exists('ai_read_only', $options) ? $this->nullableBool($options['ai_read_only']) : null,
                    'notes' => $options['notes'] ?? $existing?->notes,
                    'managed_by' => $actor,
                ]
            );

            if (empty($options['keep_overrides'])) {
                CompanyLicenseModule::where('company_id', $company->id)->delete();
            }

            if ($status !== 'suspended' && $company->status !== 'active') {
                $company->forceFill(['status' => 'active'])->save();
            }

            $this->syncCapabilitiesToCompany($company->id, $actor);

            $this->recordAudit($company->id, $plan?->id, $actor, 'plan_assigned',
                'Assigned '.($plan ? $plan->name : 'Custom')." plan ({$status}, {$cycle})",
                $before, $license->fresh()->toArray());

            DB::afterCommit(fn () => $this->forgetCompany($company->id));

            return $license->fresh(['plan']);
        });
    }

    /**
     * Save explicit licensed modules for a company against its plan (or custom if no plan).
     *
     * @param list<string> $enabledKeys
     * @param array<string, ?string> $expiries key => YYYY-MM-DD|null
     */
    public function saveCompanyModules(int $companyId, array $enabledKeys, array $expiries, int $actor, ?string $notes = null): array
    {
        $this->ensurePlansSeeded();
        $company = Company::findOrFail($companyId);

        foreach ($enabledKeys as $key) {
            if (!isset(CapabilityCatalog::DEFINITIONS[$key])) {
                throw ValidationException::withMessages(['modules' => "Unknown capability key: {$key}."]);
            }
        }

        // Expand dependencies and core capabilities automatically so dependencies stay valid.
        $target = [];
        foreach (CapabilityCatalog::DEFINITIONS as $key => $def) {
            if (($def[2] ?? false) || in_array($key, $enabledKeys, true)) {
                $this->includeWithDependencies($key, $target);
            }
        }
        $targetKeys = array_keys($target);

        return DB::transaction(function () use ($company, $targetKeys, $expiries, $actor, $notes) {
            $license = CompanyLicense::where('company_id', $company->id)->lockForUpdate()->first();
            if (!$license) {
                $license = $this->assignPlan($company->id, null, ['billing_cycle' => 'custom', 'status' => 'active'], $actor);
            }

            $planModules = $license->saas_plan_id
                ? (SaasPlan::find($license->saas_plan_id)?->modules_json ?? [])
                : [];

            $before = CompanyLicenseModule::where('company_id', $company->id)->get()->toArray();
            CompanyLicenseModule::where('company_id', $company->id)->delete();

            foreach (CapabilityCatalog::DEFINITIONS as $key => $def) {
                if ($def[2] ?? false) {
                    continue; // Core capabilities are governed by company license active status.
                }
                $inTarget = in_array($key, $targetKeys, true);
                $inPlan = in_array($key, $planModules, true);
                $expiry = !empty($expiries[$key]) ? CarbonImmutable::parse((string) $expiries[$key])->toDateString() : null;

                if ($inTarget && (!$inPlan || $expiry !== null)) {
                    CompanyLicenseModule::create([
                        'company_id' => $company->id,
                        'capability_key' => $key,
                        'state' => 'grant',
                        'expires_at' => $expiry,
                        'notes' => $notes,
                        'updated_by' => $actor,
                    ]);
                } elseif (!$inTarget && $inPlan) {
                    CompanyLicenseModule::create([
                        'company_id' => $company->id,
                        'capability_key' => $key,
                        'state' => 'revoke',
                        'expires_at' => null,
                        'notes' => $notes,
                        'updated_by' => $actor,
                    ]);
                }
            }

            $this->syncCapabilitiesToCompany($company->id, $actor);

            $after = CompanyLicenseModule::where('company_id', $company->id)->get()->toArray();
            $this->recordAudit($company->id, $license->saas_plan_id, $actor, 'modules_updated',
                'Updated module licenses ('.count($targetKeys).' of '.count(CapabilityCatalog::DEFINITIONS).' active)',
                $before, $after);

            DB::afterCommit(fn () => $this->forgetCompany($company->id));

            return $this->buildSnapshot($company->id);
        });
    }

    public function updateLifecycle(int $companyId, string $action, array $payload, int $actor): CompanyLicense
    {
        $company = Company::findOrFail($companyId);

        return DB::transaction(function () use ($company, $action, $payload, $actor) {
            $license = CompanyLicense::where('company_id', $company->id)->lockForUpdate()->first();
            if (!$license) {
                $license = $this->assignPlan($company->id, 'enterprise', ['billing_cycle' => 'lifetime', 'status' => 'active'], $actor);
            }
            $before = $license->toArray();

            switch ($action) {
                case 'suspend':
                    $license->forceFill([
                        'status' => 'suspended',
                        'suspended_at' => now(),
                        'suspension_reason' => trim((string) ($payload['reason'] ?? '')) ?: 'Suspended by platform developer',
                        'managed_by' => $actor,
                    ])->save();
                    $summary = 'Suspended tenant license'.($license->suspension_reason ? ': '.$license->suspension_reason : '');
                    break;

                case 'activate':
                    $license->forceFill([
                        'status' => 'active',
                        'suspended_at' => null,
                        'suspension_reason' => null,
                        'managed_by' => $actor,
                    ])->save();
                    if ($company->status !== 'active') {
                        $company->forceFill(['status' => 'active'])->save();
                    }
                    $summary = 'Activated tenant license';
                    break;

                case 'renew':
                    $months = max(0, (int) ($payload['months'] ?? 0));
                    $days = max(0, (int) ($payload['days'] ?? 0));
                    $base = $license->expires_at && $license->expires_at->isFuture()
                        ? CarbonImmutable::parse($license->expires_at->toDateString())
                        : CarbonImmutable::now('UTC');
                    $newExpiry = !empty($payload['expires_at'])
                        ? CarbonImmutable::parse((string) $payload['expires_at'])
                        : ($months > 0 ? $base->addMonths($months) : $base->addDays($days > 0 ? $days : 30));
                    $graceDays = $license->plan?->grace_days ?? 7;
                    $license->forceFill([
                        'status' => 'active',
                        'expires_at' => $newExpiry->toDateString(),
                        'grace_ends_at' => $newExpiry->addDays($graceDays)->toDateString(),
                        'suspended_at' => null,
                        'suspension_reason' => null,
                        'managed_by' => $actor,
                    ])->save();
                    $summary = 'Renewed license until '.$newExpiry->toDateString();
                    break;

                case 'regenerate_key':
                    $license->forceFill([
                        'license_key' => $this->generateLicenseKey(),
                        'managed_by' => $actor,
                    ])->save();
                    $summary = 'Regenerated tenant license key';
                    break;

                case 'update_quotas':
                    $license->forceFill([
                        'status' => in_array($payload['status'] ?? '', self::STATUSES, true) ? $payload['status'] : $license->status,
                        'billing_cycle' => in_array($payload['billing_cycle'] ?? '', self::CYCLES, true) ? $payload['billing_cycle'] : $license->billing_cycle,
                        'starts_at' => !empty($payload['starts_at']) ? $payload['starts_at'] : $license->starts_at,
                        'expires_at' => array_key_exists('expires_at', $payload) ? ($payload['expires_at'] ?: null) : $license->expires_at,
                        'grace_ends_at' => array_key_exists('grace_ends_at', $payload) ? ($payload['grace_ends_at'] ?: null) : $license->grace_ends_at,
                        'max_users' => $this->nullableInt($payload['max_users'] ?? null),
                        'max_branches' => $this->nullableInt($payload['max_branches'] ?? null),
                        'max_warehouses' => $this->nullableInt($payload['max_warehouses'] ?? null),
                        'max_monthly_invoices' => $this->nullableInt($payload['max_monthly_invoices'] ?? null),
                        'ai_monthly_credits' => $this->nullableQuotaInt($payload['ai_monthly_credits'] ?? null),
                        'ai_monthly_media_pages' => $this->nullableQuotaInt($payload['ai_monthly_media_pages'] ?? null),
                        'ai_max_concurrent_runs' => $this->nullableQuotaInt($payload['ai_max_concurrent_runs'] ?? null),
                        'ai_daily_token_limit' => $this->nullableQuotaInt($payload['ai_daily_token_limit'] ?? null),
                        'ai_read_only' => array_key_exists('ai_read_only', $payload) ? $this->nullableBool($payload['ai_read_only']) : $license->ai_read_only,
                        'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $license->notes,
                        'managed_by' => $actor,
                    ])->save();
                    $summary = 'Updated license contract & quotas';
                    break;

                default:
                    throw ValidationException::withMessages(['action' => 'Unsupported license action.']);
            }

            $this->syncCapabilitiesToCompany($company->id, $actor);
            $this->recordAudit($company->id, $license->saas_plan_id, $actor, $action, $summary, $before, $license->fresh()->toArray());
            DB::afterCommit(fn () => $this->forgetCompany($company->id));

            return $license->fresh(['plan']);
        });
    }

    public function savePlan(array $data, int $actor, ?int $planId = null): SaasPlan
    {
        $this->ensurePlansSeeded();
        $code = Str::slug((string) ($data['code'] ?? $data['name'] ?? ''), '_');
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Plan code is required.']);
        }

        $target = [];
        foreach (CapabilityCatalog::DEFINITIONS as $key => $def) {
            if (($def[2] ?? false) || in_array($key, $data['modules'] ?? [], true)) {
                $this->includeWithDependencies($key, $target);
            }
        }

        return DB::transaction(function () use ($data, $code, $target, $actor, $planId) {
            $plan = $planId ? SaasPlan::lockForUpdate()->findOrFail($planId) : new SaasPlan();
            $before = $plan->exists ? $plan->toArray() : null;

            $conflict = SaasPlan::where('code', $code)->when($plan->exists, fn ($q) => $q->where('id', '!=', $plan->id))->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['code' => "Plan code '{$code}' already exists."]);
            }

            $plan->fill([
                'code' => $plan->exists && $plan->is_system ? $plan->code : $code,
                'name' => trim((string) $data['name']),
                'description' => $data['description'] ?? null,
                'billing_cycle' => in_array($data['billing_cycle'] ?? '', self::CYCLES, true) ? $data['billing_cycle'] : 'monthly',
                'price' => (float) ($data['price'] ?? 0),
                'currency_code' => strtoupper(trim((string) ($data['currency_code'] ?? 'INR'))) ?: 'INR',
                'trial_days' => max(0, (int) ($data['trial_days'] ?? 14)),
                'grace_days' => max(0, (int) ($data['grace_days'] ?? 7)),
                'max_users' => $this->nullableInt($data['max_users'] ?? null),
                'max_branches' => $this->nullableInt($data['max_branches'] ?? null),
                'max_warehouses' => $this->nullableInt($data['max_warehouses'] ?? null),
                'max_monthly_invoices' => $this->nullableInt($data['max_monthly_invoices'] ?? null),
                'ai_monthly_credits' => $this->nullableQuotaInt($data['ai_monthly_credits'] ?? null),
                'ai_monthly_media_pages' => $this->nullableQuotaInt($data['ai_monthly_media_pages'] ?? null),
                'ai_max_concurrent_runs' => $this->nullableQuotaInt($data['ai_max_concurrent_runs'] ?? null),
                'ai_daily_token_limit' => $this->nullableQuotaInt($data['ai_daily_token_limit'] ?? null),
                'ai_read_only' => array_key_exists('ai_read_only', $data)
                    ? ($this->nullableBool($data['ai_read_only']) ?? true)
                    : ($plan->exists ? (bool) $plan->ai_read_only : true),
                'modules_json' => array_keys($target),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($plan->exists ? $plan->is_active : true),
                'sort_order' => (int) ($data['sort_order'] ?? ($plan->exists ? $plan->sort_order : 10)),
            ])->save();

            $affectedCompanies = CompanyLicense::where('saas_plan_id', $plan->id)->pluck('company_id')->all();
            foreach ($affectedCompanies as $cid) {
                $this->syncCapabilitiesToCompany((int) $cid, $actor);
            }

            $this->recordAudit(null, $plan->id, $actor, $planId ? 'plan_updated' : 'plan_created',
                ($planId ? 'Updated' : 'Created')." SaaS plan {$plan->name} (".count($plan->modules_json).' modules)',
                $before, $plan->toArray());

            DB::afterCommit(function () use ($affectedCompanies) {
                foreach ($affectedCompanies as $cid) {
                    $this->forgetCompany((int) $cid);
                }
            });

            return $plan;
        });
    }

    /** Synchronize effective licensed capabilities and AI settings into company tables so changes activate immediately. */
    public function syncCapabilitiesToCompany(int $companyId, int $actor): void
    {
        $snap = $this->buildSnapshot($companyId);
        if ($snap['is_unconstrained']) {
            return;
        }

        $conn = DB::connection();
        $schema = app(SchemaCache::class);

        if ($schema->hasTable($conn, 'capabilities') && $schema->hasTable($conn, 'company_capabilities')) {
            $licensed = [];
            foreach ($snap['modules'] as $key => $info) {
                if (!empty($info['licensed'])) {
                    $licensed[] = $key;
                }
            }
            app(CapabilityService::class)->syncFromLicense($companyId, $licensed, $actor);
        }

        $this->syncAiFeatureSettings($companyId, $snap, $actor);
    }

    /** Push licensed AI flags and limits into ai_feature_settings when the AI schema is present (e.g. after ai-unified merges). */
    private function syncAiFeatureSettings(int $companyId, array $snap, int $actor): void
    {
        $conn = DB::connection();
        $schema = app(SchemaCache::class);
        if (!$schema->hasTable($conn, 'ai_feature_settings') || !empty($snap['is_unconstrained'])) {
            return;
        }

        $aiEnabled = !empty($snap['is_usable']) && !empty($snap['modules']['ai.copilot']['licensed']);
        $actionsLicensed = $aiEnabled && !empty($snap['modules']['ai.actions']['licensed']);
        $multimodalLicensed = $aiEnabled && !empty($snap['modules']['ai.multimodal']['licensed']);
        $readOnly = (bool) ($snap['ai_limits']['read_only'] ?? true) || !$actionsLicensed;
        $dailyTokens = $snap['ai_limits']['daily_token_limit'];
        $dailyTokenCol = $dailyTokens === null ? 100000000 : max(0, min((int) $dailyTokens, 100000000));

        $payload = [
            'enabled' => $aiEnabled,
            'read_only' => $readOnly,
            'daily_token_limit' => $dailyTokenCol,
            'updated_by' => $actor,
            'updated_at' => now(),
        ];
        if ($schema->hasColumn($conn, 'ai_feature_settings', 'multimodal_enabled')) {
            $payload['multimodal_enabled'] = $multimodalLicensed;
        }
        if ($schema->hasColumn($conn, 'ai_feature_settings', 'daily_media_upload_limit')) {
            $mediaLimit = $snap['ai_limits']['monthly_media_pages'];
            $payload['daily_media_upload_limit'] = $mediaLimit === null ? 10000 : max(0, (int) $mediaLimit);
        }

        $exists = DB::table('ai_feature_settings')->where('company_id', $companyId)->exists();
        if ($exists) {
            DB::table('ai_feature_settings')->where('company_id', $companyId)->update($payload);
            return;
        }

        DB::table('ai_feature_settings')->insert(array_merge([
            'company_id' => $companyId,
            'monthly_budget_minor' => 0,
            'retention_days' => 30,
            'created_at' => now(),
        ], $payload));
    }

    public function forgetCompany(int $companyId): void
    {
        Cache::forget($this->cacheKey($companyId));
        app(CapabilityService::class)->forgetCompany($companyId);
    }

    public function generateLicenseKey(): string
    {
        return 'ZOLO-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
    }

    private function buildSnapshot(int $companyId): array
    {
        $license = CompanyLicense::with('plan')->where('company_id', $companyId)->first();
        if (!$license) {
            return $this->unconstrainedSnapshot($companyId);
        }

        $company = Company::find($companyId);
        $tz = $company && in_array($company->timezone, timezone_identifiers_list(), true) ? $company->timezone : 'UTC';
        $today = CarbonImmutable::now($tz)->toDateString();

        $effectiveStatus = $license->status;
        $blockReason = null;
        $warning = null;
        $daysRemaining = null;

        if ($company && $company->status !== 'active') {
            $effectiveStatus = 'suspended';
            $blockReason = 'This company account is suspended or inactive.';
        } elseif ($license->status === 'suspended' || $license->status === 'cancelled') {
            $effectiveStatus = $license->status;
            $blockReason = $license->suspension_reason ?: 'Company license is '.$license->status.'.';
        } elseif ($license->expires_at) {
            $expiry = $license->expires_at->toDateString();
            $graceEnd = $license->grace_ends_at?->toDateString();
            $daysRemaining = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($expiry), false);

            if ($today > $expiry) {
                if ($graceEnd && $today <= $graceEnd) {
                    $effectiveStatus = 'grace';
                    $graceDaysLeft = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($graceEnd), false);
                    $warning = "License expired on {$expiry}. In grace period ({$graceDaysLeft} day(s) left until {$graceEnd}).";
                } else {
                    $effectiveStatus = 'expired';
                    $blockReason = "Company license expired on {$expiry}. Contact your platform developer to renew.";
                }
            } elseif ($daysRemaining <= 7) {
                $label = $effectiveStatus === 'trial' ? 'Trial' : 'License';
                $warning = "{$label} expires in {$daysRemaining} day(s) ({$expiry}).";
            }
        }

        $isBlocked = in_array($effectiveStatus, ['suspended', 'expired', 'cancelled'], true);

        $plan = $license->plan;
        $planModules = $plan ? ($plan->modules_json ?? []) : [];
        $overrides = CompanyLicenseModule::where('company_id', $companyId)->get()->keyBy('capability_key');

        $modules = [];
        foreach (CapabilityCatalog::DEFINITIONS as $key => $def) {
            $isCore = (bool) ($def[2] ?? false);
            $override = $overrides->get($key);
            if ($isCore) {
                $modules[$key] = ['licensed' => !$isBlocked, 'source' => 'core', 'expires_at' => null, 'notes' => null];
                continue;
            }
            if ($override) {
                if ($override->state === 'revoke') {
                    $modules[$key] = ['licensed' => false, 'source' => 'revoked', 'expires_at' => null, 'notes' => $override->notes];
                } else {
                    $addonExpiry = $override->expires_at?->toDateString();
                    $expired = $addonExpiry !== null && $today > $addonExpiry;
                    $modules[$key] = [
                        'licensed' => !$isBlocked && !$expired,
                        'source' => $expired ? 'addon_expired' : 'addon',
                        'expires_at' => $addonExpiry,
                        'notes' => $override->notes,
                    ];
                }
            } elseif (in_array($key, $planModules, true)) {
                $modules[$key] = ['licensed' => !$isBlocked, 'source' => 'plan', 'expires_at' => null, 'notes' => null];
            } else {
                $modules[$key] = ['licensed' => false, 'source' => 'unlicensed', 'expires_at' => null, 'notes' => null];
            }
        }

        // Enforce module dependency integrity on read so an expired dependency disables dependent modules cleanly.
        foreach (CapabilityCatalog::DEFINITIONS as $key => $def) {
            if (!$this->moduleDependenciesLicensed($key, $modules)) {
                $modules[$key]['licensed'] = false;
            }
        }

        return [
            'company_id' => $companyId,
            'has_license' => true,
            'is_unconstrained' => false,
            'status' => $effectiveStatus,
            'raw_status' => $license->status,
            'is_usable' => !$isBlocked,
            'is_blocked' => $isBlocked,
            'block_reason' => $blockReason,
            'banner_warning' => $warning,
            'license_key' => $license->license_key,
            'plan' => $plan ? [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle,
                'price' => (float) $plan->price,
                'currency_code' => $plan->currency_code,
            ] : null,
            'billing_cycle' => $license->billing_cycle,
            'starts_at' => $license->starts_at?->toDateString(),
            'expires_at' => $license->expires_at?->toDateString(),
            'grace_ends_at' => $license->grace_ends_at?->toDateString(),
            'days_remaining' => $daysRemaining,
            'suspension_reason' => $license->suspension_reason,
            'notes' => $license->notes,
            'limits' => [
                'users' => $license->max_users ?? $plan?->max_users,
                'branches' => $license->max_branches ?? $plan?->max_branches,
                'warehouses' => $license->max_warehouses ?? $plan?->max_warehouses,
                'monthly_invoices' => $license->max_monthly_invoices ?? $plan?->max_monthly_invoices,
            ],
            'custom_limits' => [
                'users' => $license->max_users,
                'branches' => $license->max_branches,
                'warehouses' => $license->max_warehouses,
                'monthly_invoices' => $license->max_monthly_invoices,
            ],
            'ai_limits' => [
                'monthly_credits' => $license->ai_monthly_credits ?? $plan?->ai_monthly_credits,
                'monthly_media_pages' => $license->ai_monthly_media_pages ?? $plan?->ai_monthly_media_pages,
                'max_concurrent_runs' => $license->ai_max_concurrent_runs ?? $plan?->ai_max_concurrent_runs,
                'daily_token_limit' => $license->ai_daily_token_limit ?? $plan?->ai_daily_token_limit,
                'read_only' => $license->ai_read_only ?? ($plan ? (bool) $plan->ai_read_only : true),
            ],
            'custom_ai_limits' => [
                'monthly_credits' => $license->ai_monthly_credits,
                'monthly_media_pages' => $license->ai_monthly_media_pages,
                'max_concurrent_runs' => $license->ai_max_concurrent_runs,
                'daily_token_limit' => $license->ai_daily_token_limit,
                'read_only' => $license->ai_read_only,
            ],
            'modules' => $modules,
        ];
    }

    private function unconstrainedSnapshot(int $companyId): array
    {
        $modules = [];
        foreach (CapabilityCatalog::DEFINITIONS as $key => $def) {
            $modules[$key] = ['licensed' => true, 'source' => 'legacy', 'expires_at' => null, 'notes' => null];
        }

        return [
            'company_id' => $companyId,
            'has_license' => false,
            'is_unconstrained' => true,
            'status' => 'unlicensed_legacy',
            'raw_status' => 'unlicensed_legacy',
            'is_usable' => true,
            'is_blocked' => false,
            'block_reason' => null,
            'banner_warning' => null,
            'license_key' => null,
            'plan' => null,
            'billing_cycle' => 'lifetime',
            'starts_at' => null,
            'expires_at' => null,
            'grace_ends_at' => null,
            'days_remaining' => null,
            'suspension_reason' => null,
            'notes' => null,
            'limits' => ['users' => null, 'branches' => null, 'warehouses' => null, 'monthly_invoices' => null],
            'custom_limits' => ['users' => null, 'branches' => null, 'warehouses' => null, 'monthly_invoices' => null],
            'ai_limits' => [
                'monthly_credits' => null,
                'monthly_media_pages' => null,
                'max_concurrent_runs' => null,
                'daily_token_limit' => null,
                'read_only' => false,
            ],
            'custom_ai_limits' => [
                'monthly_credits' => null,
                'monthly_media_pages' => null,
                'max_concurrent_runs' => null,
                'daily_token_limit' => null,
                'read_only' => null,
            ],
            'modules' => $modules,
        ];
    }

    private function moduleDependenciesLicensed(string $key, array $modules): bool
    {
        foreach (CapabilityCatalog::DEFINITIONS[$key][1] ?? [] as $dep) {
            if (empty($modules[$dep]['licensed']) || !$this->moduleDependenciesLicensed($dep, $modules)) {
                return false;
            }
        }
        return true;
    }

    private function includeWithDependencies(string $key, array &$target): void
    {
        if (isset($target[$key])) {
            return;
        }
        $target[$key] = true;
        foreach (CapabilityCatalog::DEFINITIONS[$key][1] ?? [] as $dep) {
            $this->includeWithDependencies($dep, $target);
        }
    }

    private function resolvePlan(int|string|null $planIdOrCode): ?SaasPlan
    {
        if ($planIdOrCode === null || $planIdOrCode === '' || $planIdOrCode === 'custom') {
            return null;
        }
        return is_numeric($planIdOrCode)
            ? SaasPlan::findOrFail((int) $planIdOrCode)
            : SaasPlan::where('code', (string) $planIdOrCode)->firstOrFail();
    }

    private function defaultExpiryDate(string $cycle, string $startsAt, int $trialDays): ?string
    {
        $start = CarbonImmutable::parse($startsAt);
        return match ($cycle) {
            'trial' => $start->addDays(max(1, $trialDays))->toDateString(),
            'monthly' => $start->addMonth()->toDateString(),
            'yearly' => $start->addYear()->toDateString(),
            'lifetime' => null,
            default => $start->addMonth()->toDateString(),
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (is_numeric($value) && (int) $value <= 0)) {
            return null;
        }
        return max(1, (int) $value);
    }

    /** Integer quota where explicit 0 means zero allowed, while null/empty clears override (inherits plan or unlimited). */
    private function nullableQuotaInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, (int) $value);
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function recordAudit(?int $companyId, ?int $planId, ?int $actorId, string $action, string $summary, ?array $before, ?array $after): void
    {
        if (!app(SchemaCache::class)->hasTable(DB::connection(), 'company_license_audits')) {
            return;
        }
        CompanyLicenseAudit::create([
            'company_id' => $companyId,
            'saas_plan_id' => $planId,
            'actor_id' => $actorId,
            'action' => $action,
            'summary' => mb_substr($summary, 0, 255),
            'before_json' => $before,
            'after_json' => $after,
            'created_at' => now(),
        ]);
    }

    private function cacheKey(int $companyId): string
    {
        $conn = DB::connection();
        $identity = [$conn->getName(), $conn->getConfig('host'), $conn->getConfig('port'), $conn->getDatabaseName()];
        return 'saas_license:'.hash('sha256', json_encode($identity)).':'.$companyId;
    }
}
