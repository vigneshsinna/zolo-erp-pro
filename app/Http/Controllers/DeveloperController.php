<?php

namespace App\Http\Controllers;

use App\Exceptions\CompanyFinancialYearSetupRequired;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Platform\CompanyLicenseAudit;
use App\Models\Platform\SaasPlan;
use App\Models\User;
use App\Services\Industry\IndustryCatalog;
use App\Services\Industry\IndustryProfileService;
use App\Services\Platform\BranchAccess;
use App\Services\Platform\CapabilityCatalog;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use App\Services\Platform\CompanyDirectoryService;
use App\Services\Platform\DeveloperTenantService;
use App\Services\Platform\LicenseService;
use App\Services\Platform\MenuSwitches;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Multi-tenant SaaS Developer Portal: tenant lifecycle, subscription plans, 45-module licensing
 * (37 ERP + 8 AI), ERP & AI resource quotas, per-company screen switches, admin logins, and audit logs.
 */
class DeveloperController extends Controller
{
    public function __construct(
        private LicenseService $licenses,
        private DeveloperTenantService $tenants,
    ) {
    }

    private function authorizeDeveloper(): void
    {
        abort_unless(MenuSwitches::isDeveloper(), 403, 'Only a developer login can open the developer portal.');
    }

    public function index(Request $request, MenuSwitches $switches)
    {
        $this->authorizeDeveloper();
        $this->licenses->ensurePlansSeeded();

        $search = $request->query('q');
        $statusFilter = $request->query('status', 'all');
        $groupFilter = (string) $request->query('group', 'all');
        $tab = in_array($request->query('tab'), ['dashboard', 'tenants', 'plans', 'admins', 'audits', 'modules'], true)
            ? $request->query('tab')
            : ($request->filled('company') || $request->has('tab') ? 'tenants' : 'dashboard');

        $tenants = $this->tenants->listTenants($search, $statusFilter, $groupFilter);
        $allTenants = ($search || $statusFilter !== 'all' || $groupFilter !== 'all') ? $this->tenants->listTenants() : $tenants;

        $requestedCompanyId = $request->integer('company') ?: (int) ($request->session()->get('company_id') ?: 0);
        $selectedTenant = $tenants->firstWhere('id', $requestedCompanyId)
            ?? $tenants->first()
            ?? $allTenants->firstWhere('id', $requestedCompanyId)
            ?? $allTenants->first();
        if ($selectedTenant) {
            $this->shareShellContext($request, (int) $selectedTenant['id']);
        }

        $plans = Schema::hasTable('saas_plans')
            ? SaasPlan::orderBy('sort_order')->orderBy('id')->get()
            : collect();
        $planSubscriberCounts = Schema::hasTable('company_licenses')
            ? DB::table('company_licenses')->whereNotNull('saas_plan_id')->groupBy('saas_plan_id')
                ->selectRaw('saas_plan_id, COUNT(*) as total')->pluck('total', 'saas_plan_id')
            : collect();

        $moduleCatalog = $this->licenses->moduleCatalog();
        $moduleGroups = collect($moduleCatalog)->groupBy('group_key');

        $admins = User::where('is_deleted', false)
            ->whereIn('role_id', [1, 2])
            ->when(Schema::hasColumn('users', 'is_developer'), fn ($q) => $q->where('is_developer', false))
            ->orderBy('name')
            ->get();
        $memberships = DB::table('company_user')->whereIn('user_id', $admins->pluck('id'))->get()->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('company_id')->map(fn ($id) => (int) $id)->all());

        $selectedCompanyId = $selectedTenant['id'] ?? null;
        $catalog = $switches->catalog($this->sidebar($selectedCompanyId));
        $off = $selectedCompanyId ? array_keys($switches->off((int) $selectedCompanyId)) : [];
        $selectedCapabilities = $selectedCompanyId ? app(CapabilityService::class)->snapshot((int) $selectedCompanyId) : [];

        $audits = Schema::hasTable('company_license_audits')
            ? CompanyLicenseAudit::orderByDesc('id')->limit(80)->get()
            : collect();
        $actorNames = User::whereIn('id', $audits->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $companyNames = $allTenants->pluck('label', 'id');

        $groups = Schema::hasTable('company_groups')
            ? CompanyGroup::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name'])
            : collect();
        $groupedCounts = $allTenants->whereNotNull('group_id')->countBy('group_id');
        $independentCount = $allTenants->whereNull('group_id')->count();

        $currencies = Schema::hasTable('currencies')
            ? DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name'])
            : collect();
        $defaultCurrencyId = Schema::hasTable('currencies')
            ? (DB::table('currencies')->where('code', 'INR')->value('id') ?? $currencies->first()?->id)
            : null;
        $states = array_diff_key(\App\Services\Tax\Gstin::$states, ['97' => 1]);
        $today = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $fyStart = $today->month >= 4 ? $today->setDate($today->year, 4, 1) : $today->setDate($today->year - 1, 4, 1);

        $stats = [
            'tenants_total' => $allTenants->count(),
            'groups_total' => $groups->count(),
            'independent_total' => $independentCount,
            'tenants_active' => $allTenants->whereIn('license.status', ['active', 'unlicensed_legacy'])->count(),
            'tenants_trial_grace' => $allTenants->whereIn('license.status', ['trial', 'grace'])->count(),
            'tenants_blocked' => $allTenants->whereIn('license.status', ['suspended', 'expired', 'cancelled'])->count(),
            'plans_active' => $plans->where('is_active', true)->count(),
            'modules_total' => count(CapabilityCatalog::DEFINITIONS),
            'total_users' => $allTenants->sum('usage.users'),
            'total_monthly_invoices' => $allTenants->sum('usage.monthly_invoices'),
        ];

        return view('backend.developer.index', [
            'tab' => $tab,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'groupFilter' => $groupFilter,
            'stats' => $stats,
            'tenants' => $tenants,
            'allTenants' => $allTenants,
            'selectedTenant' => $selectedTenant,
            'selectedCapabilities' => $selectedCapabilities,
            'plans' => $plans,
            'planSubscriberCounts' => $planSubscriberCounts,
            'moduleCatalog' => $moduleCatalog,
            'moduleGroups' => $moduleGroups,
            'moduleGroupLabels' => LicenseService::MODULE_GROUPS,
            'admins' => $admins,
            'memberships' => $memberships,
            'catalog' => $catalog,
            'off' => $off,
            'audits' => $audits,
            'actorNames' => $actorNames,
            'companyNames' => $companyNames,
            'groups' => $groups,
            'groupedCounts' => $groupedCounts,
            'independentCount' => $independentCount,
            'theme' => $_COOKIE['theme'] ?? 'light',
            'currencies' => $currencies,
            'defaultCurrencyId' => $defaultCurrencyId,
            'states' => $states,
            'industries' => collect(CapabilityCatalog::PROFILES)->map(fn ($p) => $p[0])->all(),
            'subtypes' => IndustryCatalog::SUBTYPES,
            'defaults' => [
                'timezone' => config('app.timezone', 'Asia/Kolkata'),
                'fy_start' => $fyStart->toDateString(),
                'fy_end' => $fyStart->addYear()->subDay()->toDateString(),
            ],
        ]);
    }

    public function storeTenant(Request $request)
    {
        $this->authorizeDeveloper();
        $result = $this->tenants->createTenant($request->all(), (int) Auth::id());
        $company = $result['company'];

        return redirect()->route('developer.index', ['company' => $company->id, 'tab' => 'tenants'])
            ->with('message', 'Tenant '.$company->legal_name.' provisioned with '.$result['license']->license_key.'.');
    }

    public function storeGroup(Request $request)
    {
        $this->authorizeDeveloper();
        $group = $this->tenants->createGroup($request->only(['name', 'code']));

        return redirect()->route('developer.index', ['tab' => 'tenants', 'group' => $group->id])
            ->with('message', 'Company group '.$group->name.' created.');
    }

    public function updateGroup(Request $request, int $group, CompanyDirectoryService $directory)
    {
        $this->authorizeDeveloper();
        $saved = $directory->updateGroup((int) Auth::id(), $group, $request->only(['name', 'code']));

        return redirect()->route('developer.index', ['tab' => 'tenants', 'group' => $saved->id])
            ->with('message', 'Company group '.$saved->name.' updated.');
    }

    public function deleteGroup(int $group, CompanyDirectoryService $directory)
    {
        $this->authorizeDeveloper();
        $directory->deleteGroup((int) Auth::id(), $group);

        return redirect()->route('developer.index', ['tab' => 'tenants', 'group' => 'independent'])
            ->with('message', 'Company group removed; member companies moved to Independent Companies.');
    }

    public function assignGroup(Request $request, int $company)
    {
        $this->authorizeDeveloper();
        $data = $request->validate([
            'company_group_id' => 'nullable|integer|exists:company_groups,id',
        ]);
        $updated = $this->tenants->assignCompanyGroup(
            $company,
            !empty($data['company_group_id']) ? (int) $data['company_group_id'] : null,
            (int) Auth::id()
        );

        return redirect()->route('developer.index', ['company' => $updated->id, 'tab' => 'tenants'])
            ->with('message', $updated->group ? "Assigned to group {$updated->group->name}." : 'Moved to Independent Companies.');
    }

    public function assignPlan(Request $request, int $company)
    {
        $this->authorizeDeveloper();
        $data = $request->validate([
            'saas_plan' => 'nullable|string|max:50',
            'billing_cycle' => ['nullable', Rule::in(LicenseService::CYCLES)],
            'status' => ['nullable', Rule::in(LicenseService::STATUSES)],
            'starts_at' => 'nullable|date_format:Y-m-d',
            'expires_at' => 'nullable|date_format:Y-m-d',
            'grace_ends_at' => 'nullable|date_format:Y-m-d',
            'max_users' => 'nullable|integer|min:0',
            'max_branches' => 'nullable|integer|min:0',
            'max_warehouses' => 'nullable|integer|min:0',
            'max_monthly_invoices' => 'nullable|integer|min:0',
            'ai_monthly_credits' => 'nullable|integer|min:0',
            'ai_monthly_media_pages' => 'nullable|integer|min:0',
            'ai_max_concurrent_runs' => 'nullable|integer|min:0',
            'ai_daily_token_limit' => 'nullable|integer|min:0',
            'ai_read_only' => 'nullable|in:0,1',
            'keep_overrides' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $license = $this->licenses->assignPlan($company, $data['saas_plan'] ?? null, $data, (int) Auth::id());

        return redirect()->route('developer.index', ['company' => $company, 'tab' => 'tenants'])
            ->with('message', 'Plan updated: '.($license->plan?->name ?? 'Custom').' ('.$license->status.').');
    }

    public function saveModules(Request $request, int $company)
    {
        $this->authorizeDeveloper();
        $data = $request->validate([
            'modules' => 'sometimes|array',
            'modules.*' => 'string|max:100',
            'expiries' => 'sometimes|array',
            'expiries.*' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:255',
        ]);

        $snap = $this->licenses->saveCompanyModules(
            $company,
            $data['modules'] ?? [],
            $data['expiries'] ?? [],
            (int) Auth::id(),
            $data['notes'] ?? null
        );
        $count = count(array_filter($snap['modules'], fn ($m) => !empty($m['licensed'])));

        return redirect()->route('developer.index', ['company' => $company, 'tab' => 'tenants'])
            ->with('message', "Module licenses saved ({$count}/".count(CapabilityCatalog::DEFINITIONS).' modules active).');
    }

    public function lifecycle(Request $request, int $company)
    {
        $this->authorizeDeveloper();
        $data = $request->validate([
            'action' => 'required|in:activate,suspend,renew,regenerate_key,update_quotas',
            'reason' => 'nullable|string|max:500',
            'months' => 'nullable|integer|min:1|max:120',
            'days' => 'nullable|integer|min:1|max:3650',
            'status' => ['nullable', Rule::in(LicenseService::STATUSES)],
            'billing_cycle' => ['nullable', Rule::in(LicenseService::CYCLES)],
            'starts_at' => 'nullable|date_format:Y-m-d',
            'expires_at' => 'nullable|date_format:Y-m-d',
            'grace_ends_at' => 'nullable|date_format:Y-m-d',
            'max_users' => 'nullable|integer|min:0',
            'max_branches' => 'nullable|integer|min:0',
            'max_warehouses' => 'nullable|integer|min:0',
            'max_monthly_invoices' => 'nullable|integer|min:0',
            'ai_monthly_credits' => 'nullable|integer|min:0',
            'ai_monthly_media_pages' => 'nullable|integer|min:0',
            'ai_max_concurrent_runs' => 'nullable|integer|min:0',
            'ai_daily_token_limit' => 'nullable|integer|min:0',
            'ai_read_only' => 'nullable|in:0,1',
            'notes' => 'nullable|string|max:2000',
        ]);

        $license = $this->licenses->updateLifecycle($company, $data['action'], $data, (int) Auth::id());

        return redirect()->route('developer.index', ['company' => $company, 'tab' => 'tenants'])
            ->with('message', 'License updated ('.$license->status.', key '.$license->license_key.').');
    }

    public function applyIndustry(Request $request, int $company)
    {
        $this->authorizeDeveloper();
        $data = $request->validate([
            'industry' => ['required', Rule::in(array_keys(IndustryCatalog::SUBTYPES))],
            'subtype' => 'nullable|string|max:50',
        ]);
        $context = $this->resolveCompanyContext($company);
        app(IndustryProfileService::class)->apply($data['industry'], $data['subtype'] ?? null, $context, (int) Auth::id());

        return redirect()->route('developer.index', ['company' => $company, 'tab' => 'tenants'])
            ->with('message', 'Industry preset applied within licensed entitlements.');
    }

    public function storePlan(Request $request)
    {
        $this->authorizeDeveloper();
        $plan = $this->licenses->savePlan($this->validatePlan($request), (int) Auth::id());

        return redirect()->route('developer.index', ['tab' => 'plans'])
            ->with('message', 'SaaS plan '.$plan->name.' created.');
    }

    public function updatePlan(Request $request, int $plan)
    {
        $this->authorizeDeveloper();
        $saved = $this->licenses->savePlan($this->validatePlan($request), (int) Auth::id(), $plan);

        return redirect()->route('developer.index', ['tab' => 'plans'])
            ->with('message', 'SaaS plan '.$saved->name.' updated.');
    }

    public function togglePlan(int $plan)
    {
        $this->authorizeDeveloper();
        $record = SaasPlan::findOrFail($plan);
        $record->forceFill(['is_active' => !$record->is_active])->save();

        return redirect()->route('developer.index', ['tab' => 'plans'])
            ->with('message', 'Plan '.$record->name.' is now '.($record->is_active ? 'active' : 'archived').'.');
    }

    public function storeAdmin(Request $request)
    {
        $this->authorizeDeveloper();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('users')->where('is_deleted', false)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->where('is_deleted', false)],
            'password' => 'required|string|min:8|max:100',
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'integer|exists:companies,id',
        ], ['company_ids.required' => 'Tick at least one company for the new admin.']);
        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
                'role_id' => 1,
                'is_active' => true,
                'is_deleted' => false,
                'phone' => '',
            ]);
            $this->syncCompanies($user->id, $data['company_ids']);
        });

        return redirect()->route('developer.index', ['tab' => 'admins'])
            ->with('message', 'Admin '.$data['name'].' created. They can sign in with '.$data['email'].'.');
    }

    public function status(Request $request, int $user)
    {
        $this->authorizeDeveloper();
        $admin = $this->admin($user);
        $admin->forceFill(['is_active' => !$admin->is_active])->save();

        return redirect()->route('developer.index', ['tab' => 'admins'])
            ->with('message', $admin->name.' is now '.($admin->is_active ? 'active' : 'blocked from signing in').'.');
    }

    public function password(Request $request, int $user)
    {
        $this->authorizeDeveloper();
        $admin = $this->admin($user);
        $data = $request->validate(['password' => 'required|string|min:8|max:100']);
        $admin->forceFill(['password' => bcrypt($data['password'])])->save();

        return redirect()->route('developer.index', ['tab' => 'admins'])
            ->with('message', 'Password of '.$admin->name.' changed.');
    }

    public function companies(Request $request, int $user)
    {
        $this->authorizeDeveloper();
        $admin = $this->admin($user);
        $data = $request->validate([
            'company_ids' => 'required|array|min:1',
            'company_ids.*' => 'integer|exists:companies,id',
        ], ['company_ids.required' => 'An admin needs at least one company. Block the login instead to stop all access.']);
        DB::transaction(fn () => $this->syncCompanies($admin->id, $data['company_ids']));

        return redirect()->route('developer.index', ['tab' => 'admins'])
            ->with('message', 'Companies of '.$admin->name.' updated.');
    }

    public function switches(Request $request, MenuSwitches $switches, ?int $company = null)
    {
        $this->authorizeDeveloper();
        $companyId = $company ?: $request->integer('company_id') ?: ($request->attributes->get(CompanyContext::class)?->companyId ?? 0);
        if (!$companyId) {
            $companyId = (int) ($request->session()->get('company_id') ?: Company::query()->value('id'));
        }
        abort_unless($companyId > 0, 409, 'Choose a company first.');

        $data = $request->validate(['on' => 'sometimes|array', 'on.*' => 'string|max:120']);
        $catalog = $switches->catalog($this->sidebar($companyId));
        $keys = [];
        foreach ($catalog as $section) {
            $keys[] = $section['key'];
            foreach ($section['items'] as $item) {
                $keys[] = $item['key'];
            }
        }
        $off = array_values(array_diff($keys, $data['on'] ?? []));
        $switches->save($companyId, $off, $catalog, (int) Auth::id());

        return redirect()->route('developer.index', ['company' => $companyId, 'tab' => 'tenants'])
            ->with('message', 'Screen switches saved for this company. Everyone except developers sees the change on their next page.');
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'code' => 'nullable|string|max:50',
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'billing_cycle' => ['required', Rule::in(LicenseService::CYCLES)],
            'price' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|max:10',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'grace_days' => 'nullable|integer|min:0|max:365',
            'max_users' => 'nullable|integer|min:0',
            'max_branches' => 'nullable|integer|min:0',
            'max_warehouses' => 'nullable|integer|min:0',
            'max_monthly_invoices' => 'nullable|integer|min:0',
            'ai_monthly_credits' => 'nullable|integer|min:0',
            'ai_monthly_media_pages' => 'nullable|integer|min:0',
            'ai_max_concurrent_runs' => 'nullable|integer|min:0',
            'ai_daily_token_limit' => 'nullable|integer|min:0',
            'ai_read_only' => 'nullable|boolean',
            'modules' => 'sometimes|array',
            'modules.*' => 'string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    private function admin(int $id): User
    {
        $user = User::where('is_deleted', false)->whereIn('role_id', [1, 2])->findOrFail($id);
        abort_if((bool) ($user->is_developer ?? false), 403, 'A developer login is managed with the erp:developer command, not here.');

        return $user;
    }

    private function syncCompanies(int $userId, array $companyIds): void
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        DB::table('company_user_branches')->where('user_id', $userId)->whereNotIn('company_id', $companyIds)->delete();
        DB::table('company_user')->where('user_id', $userId)->whereNotIn('company_id', $companyIds)->delete();
        $hasDefault = DB::table('company_user')->where('user_id', $userId)->where('is_default', true)->exists();
        foreach ($companyIds as $i => $companyId) {
            if (!DB::table('company_user')->where(['company_id' => $companyId, 'user_id' => $userId])->exists()) {
                DB::table('company_user')->insert([
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'is_default' => !$hasDefault && $i === 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
            foreach (DB::table('company_branches')->where('company_id', $companyId)->where('is_active', true)->pluck('id') as $branchId) {
                DB::table('company_user_branches')->updateOrInsert(
                    ['company_id' => $companyId, 'user_id' => $userId, 'branch_id' => $branchId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }
        app(BranchAccess::class)->forget();
    }

    private function shareShellContext(Request $request, int $companyId): void
    {
        try {
            $context = $this->resolveCompanyContext($companyId);
            $request->attributes->set(CompanyContext::class, $context);
        } catch (CompanyFinancialYearSetupRequired|ValidationException|AuthorizationException) {
            // Developer Portal remains accessible even when a tenant is suspended or lacks an open FY.
        }
    }

    private function resolveCompanyContext(int $companyId): CompanyContext
    {
        $this->tenants->grantAllDevelopersAccess($companyId);
        $branchId = DB::table('company_branches')->where('company_id', $companyId)->where('is_active', true)->orderBy('id')->value('id');
        $yearId = DB::table('fiscal_years')->where('company_id', $companyId)->orderByDesc('start_date')->value('id');
        return app(CompanyContextResolver::class)->resolve((int) Auth::id(), $companyId, $branchId ? (int) $branchId : null, $yearId ? (int) $yearId : null);
    }

    private function sidebar(?int $companyId = null): string
    {
        $req = request();
        $previous = $req->attributes->get(CompanyContext::class);
        if ($companyId) {
            $this->shareShellContext($req, $companyId);
        }
        try {
            return view('backend.layout.sidebar')->render();
        } finally {
            if ($previous) {
                $req->attributes->set(CompanyContext::class, $previous);
            } else {
                $req->attributes->remove(CompanyContext::class);
            }
        }
    }
}
