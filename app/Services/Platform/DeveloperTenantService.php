<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\User;
use App\Services\Industry\IndustryCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Platform-level multi-tenant lifecycle orchestration for the Developer Portal.
 * Reuses CompanyDirectoryService provisioning and binds SaaS plan licensing + developer/admin access.
 */
class DeveloperTenantService
{
    public function __construct(
        private CompanyDirectoryService $directory,
        private LicenseService $licenses,
    ) {
    }

    public function listTenants(?string $search = null, ?string $status = null): Collection
    {
        $companies = Company::with('group')->orderBy('trade_name')->orderBy('legal_name')->get();
        $names = $companies->countBy(fn ($c) => $c->trade_name ?: $c->legal_name);
        $profiles = Schema::hasTable('company_industry_settings')
            ? DB::table('company_industry_settings')->get()->keyBy('company_id')
            : collect();
        $switchesCount = Schema::hasTable('company_menu_switches')
            ? DB::table('company_menu_switches')->groupBy('company_id')->selectRaw('company_id, COUNT(*) as total')->pluck('total', 'company_id')
            : collect();

        $totalModules = count(CapabilityCatalog::DEFINITIONS);

        return $companies->map(function (Company $company) use ($names, $profiles, $switchesCount, $totalModules) {
            $baseName = $company->trade_name ?: $company->legal_name;
            $label = $baseName.(($names[$baseName] ?? 0) > 1 ? ' #'.$company->id : '');
            $license = $this->licenses->snapshot($company->id);
            $usage = $this->licenses->quotaUsage($company->id);
            $profile = $profiles->get($company->id);
            $licensedModules = count(array_filter($license['modules'], fn ($m) => !empty($m['licensed'])));

            return [
                'id' => $company->id,
                'code' => $company->code,
                'label' => $label,
                'trade_name' => $company->trade_name,
                'legal_name' => $company->legal_name,
                'company_status' => $company->status,
                'group_name' => $company->group?->name,
                'industry' => $profile->profile_key ?? 'general_trading',
                'industry_label' => CapabilityCatalog::PROFILES[$profile->profile_key ?? 'general_trading'][0] ?? 'General Trading',
                'subtype' => $profile->subtype ?? null,
                'license' => $license,
                'usage' => $usage,
                'licensed_count' => $licensedModules,
                'total_modules' => $totalModules,
                'menu_switches_off' => (int) ($switchesCount[$company->id] ?? 0),
            ];
        })->filter(function (array $row) use ($search, $status) {
            if ($search !== null && trim($search) !== '') {
                $needle = mb_strtolower(trim($search));
                $haystack = mb_strtolower(implode(' ', [
                    $row['label'],
                    $row['legal_name'],
                    $row['code'],
                    $row['group_name'] ?? '',
                    $row['license']['license_key'] ?? '',
                    $row['license']['plan']['name'] ?? '',
                ]));
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }
            if ($status !== null && $status !== '' && $status !== 'all') {
                return ($row['license']['status'] ?? '') === $status;
            }
            return true;
        })->values();
    }

    /**
     * Provision a brand-new tenant company with branch, FY, warehouse, chart of accounts, starter masters,
     * initial SaaS license, optional initial tenant admin user, and automatic developer memberships.
     */
    public function createTenant(array $data, int $actor): array
    {
        $this->licenses->ensurePlansSeeded();
        $validated = validator($data, [
            'legal_name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:50|regex:/^[A-Za-z0-9_-]+$/',
            'company_group_id' => 'nullable|integer|exists:company_groups,id',
            'industry' => ['required', Rule::in(array_keys(IndustryCatalog::SUBTYPES))],
            'subtype' => 'nullable|string|max:50',
            'state_code' => 'nullable|string|max:20',
            'base_currency_id' => 'nullable|integer|exists:currencies,id',
            'timezone' => 'required|string|max:100',
            'fy_start' => 'required|date_format:Y-m-d',
            'fy_end' => 'required|date_format:Y-m-d|after:fy_start',
            'saas_plan' => 'nullable|string|max:50',
            'billing_cycle' => ['nullable', Rule::in(LicenseService::CYCLES)],
            'expires_at' => 'nullable|date_format:Y-m-d',
            'admin_name' => ['nullable', 'required_with:admin_email', 'string', 'max:255', Rule::unique('users', 'name')->where('is_deleted', false)],
            'admin_email' => ['nullable', 'required_with:admin_name', 'email', 'max:255', Rule::unique('users', 'email')->where('is_deleted', false)],
            'admin_password' => 'nullable|required_with:admin_email|string|min:8|max:100',
        ])->validate();

        return DB::transaction(function () use ($validated, $data, $actor) {
            $company = $this->directory->createCompany($actor, [
                'company_group_id' => $validated['company_group_id'] ?? null,
                'legal_name' => $validated['legal_name'],
                'trade_name' => $validated['trade_name'] ?? null,
                'code' => $validated['code'] ?? '',
                'industry' => $validated['industry'],
                'subtype' => $validated['subtype'] ?? null,
                'state_code' => $validated['state_code'] ?? null,
                'base_currency_id' => $validated['base_currency_id'] ?? null,
                'timezone' => $validated['timezone'],
                'fy_start' => $validated['fy_start'],
                'fy_end' => $validated['fy_end'],
            ]);

            $this->grantAllDevelopersAccess($company->id);

            $planCode = $validated['saas_plan'] ?? 'starter';
            $license = $this->licenses->assignPlan($company->id, $planCode, [
                'billing_cycle' => $validated['billing_cycle'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'max_users' => $data['max_users'] ?? null,
                'max_branches' => $data['max_branches'] ?? null,
                'max_warehouses' => $data['max_warehouses'] ?? null,
                'max_monthly_invoices' => $data['max_monthly_invoices'] ?? null,
                'notes' => 'Provisioned via Developer Portal',
            ], $actor);

            $admin = null;
            if (!empty($validated['admin_email'])) {
                $admin = User::create([
                    'name' => $validated['admin_name'],
                    'email' => $validated['admin_email'],
                    'password' => bcrypt($validated['admin_password']),
                    'role_id' => 1,
                    'is_active' => true,
                    'is_deleted' => false,
                    'phone' => '',
                ]);
                $this->grantUserToCompany($admin->id, $company->id, true);
            }

            return ['company' => $company, 'license' => $license, 'admin' => $admin];
        });
    }

    public function createGroup(array $data): CompanyGroup
    {
        $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($data['name'] ?? 'GRP')));
        $validated = validator(['name' => $data['name'] ?? '', 'code' => $code], [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|regex:/^[A-Z0-9_-]+$/|unique:company_groups,code',
        ])->validate();

        return CompanyGroup::create($validated + ['status' => 'active']);
    }

    public function grantAllDevelopersAccess(int $companyId): void
    {
        if (!Schema::hasColumn('users', 'is_developer')) {
            return;
        }
        $developerIds = User::where('is_deleted', false)->where('is_developer', true)->pluck('id');
        foreach ($developerIds as $devId) {
            $this->grantUserToCompany((int) $devId, $companyId, false);
        }
    }

    private function grantUserToCompany(int $userId, int $companyId, bool $isDefault): void
    {
        if (!DB::table('company_user')->where(['company_id' => $companyId, 'user_id' => $userId])->exists()) {
            $hasDefault = DB::table('company_user')->where('user_id', $userId)->where('is_default', true)->exists();
            DB::table('company_user')->insert([
                'company_id' => $companyId,
                'user_id' => $userId,
                'is_default' => $isDefault && !$hasDefault,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach (DB::table('company_branches')->where('company_id', $companyId)->where('is_active', true)->pluck('id') as $branchId) {
            DB::table('company_user_branches')->updateOrInsert(
                ['company_id' => $companyId, 'user_id' => $userId, 'branch_id' => $branchId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
