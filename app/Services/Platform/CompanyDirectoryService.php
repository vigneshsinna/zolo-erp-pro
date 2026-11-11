<?php

namespace App\Services\Platform;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\User;
use App\Services\Accounting\SemanticAccountResolver;
use App\Services\Industry\IndustryCatalog;
use App\Services\Industry\IndustryProfileService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Group -> company directory behind the post-login company picker. A group is only a container: access is still
 * company_user membership, so every group admin is synchronized into each company of the group.
 */
class CompanyDirectoryService
{
    /** Installation administrators (Admin/Owner) create groups and ungrouped companies. */
    public function isInstallationAdmin(int $userId): bool
    {
        $user = User::find($userId);

        return $user && $user->is_active && !$user->is_deleted && in_array((int) $user->role_id, [1, 2], true)
            && DB::table('roles')->where('id', $user->role_id)->where('is_active', true)->exists();
    }

    public function canManageGroup(int $userId, ?int $groupId): bool
    {
        if ($groupId === null) {
            return $this->isInstallationAdmin($userId);
        }

        return $this->isInstallationAdmin($userId)
            && DB::table('company_group_admins')->where('company_group_id', $groupId)->where('user_id', $userId)->exists();
    }

    /** Member companies grouped for the picker; groups the user administers are listed even while empty. */
    public function directory(int $userId): array
    {
        $companies = Company::with('group')->where('status', 'active')
            ->whereIn('id', DB::table('company_user')->where('user_id', $userId)->select('company_id'))
            ->orderBy('legal_name')->get();
        $managed = DB::table('company_group_admins')->where('user_id', $userId)->pluck('company_group_id')->all();
        $profiles = Schema::hasTable('company_industry_settings')
            ? DB::table('company_industry_settings')->whereIn('company_id', $companies->pluck('id'))->get()->keyBy('company_id')
            : collect();
        $today = fn (Company $company) => CarbonImmutable::now($company->timezone)->toDateString();
        $gst = Schema::hasTable('tax_registrations') ? DB::table('tax_registrations')->whereIn('company_id', $companies->pluck('id'))
            ->where('status', 'active')->whereNull('effective_to')->orderByDesc('effective_from')->get()->unique('company_id')->keyBy('company_id') : collect();
        $resolver = app(CompanyContextResolver::class);

        $groups = CompanyGroup::where('status', 'active')
            ->where(fn ($q) => $q->whereIn('id', $companies->pluck('company_group_id')->filter())->orWhereIn('id', $managed))
            ->orderBy('name')->get()
            ->map(fn (CompanyGroup $group) => ['id' => $group->id, 'code' => $group->code, 'name' => $group->name,
                'manageable' => in_array($group->id, $managed, true), 'companies' => []])
            ->keyBy('id')->all();
        $ungrouped = [];
        foreach ($companies as $company) {
            $branches = $company->branches()->where('is_active', true)->whereIn('id', DB::table('company_user_branches')
                ->where('company_id', $company->id)->where('user_id', $userId)->select('branch_id'))->orderBy('name')->get(['id', 'name']);
            $years = $company->fiscalYears()->orderByDesc('start_date')->get(['id', 'name', 'status', 'start_date', 'end_date']);
            $current = $years->first(fn ($year) => $year->start_date->toDateString() <= $today($company)
                && $year->end_date->toDateString() >= $today($company));
            $profile = $profiles->get($company->id);
            $entry = [
                'id' => $company->id, 'code' => $company->code,
                'name' => $company->trade_name ?: $company->legal_name, 'legal_name' => $company->legal_name,
                'industry' => $profile->profile_key ?? 'general_trading', 'subtype' => $profile->subtype ?? null,
                'branches' => $branches->map->only(['id', 'name'])->all(),
                'years' => $years->map(fn ($year) => ['id' => $year->id, 'name' => $year->name, 'status' => $year->status])->all(),
                'current_year_id' => $current?->id,
                // Edit / delete from the picker: company administrators only; the details prefill the edit dialog.
                'manageable' => $resolver->canManageFinancialYears($userId, $company->id),
                'details' => $company->only(['legal_name', 'trade_name', 'code', 'company_group_id', 'address', 'city', 'state_code',
                    'postal_code', 'phone', 'email', 'website', 'pan', 'base_currency_id'])
                    + ['gstin' => $gst->get($company->id)?->gstin, 'registration_type' => $gst->get($company->id)?->registration_type ?? 'regular'],
            ];
            if ($company->company_group_id && isset($groups[$company->company_group_id])) {
                $groups[$company->company_group_id]['companies'][] = $entry;
            } else {
                $ungrouped[] = $entry;
            }
        }

        return ['groups' => array_values($groups), 'ungrouped' => $ungrouped, 'count' => $companies->count()];
    }

    /** @param array{name:string, code?:string, company_ids?:array<int>} $input */
    public function createGroup(int $actor, array $input): CompanyGroup
    {
        if (!$this->isInstallationAdmin($actor)) {
            throw new AuthorizationException('Administrator required to create a company group.');
        }
        $input['code'] = strtoupper($input['code'] ?? '') ?: $this->code($input['name'] ?? '', 'company_groups');
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|regex:/^[A-Z0-9_-]+$/|unique:company_groups,code',
            'company_ids' => 'array', 'company_ids.*' => 'integer|min:1',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $group = CompanyGroup::create(['code' => $data['code'], 'name' => $data['name']]);
            DB::table('company_group_admins')->insert(['company_group_id' => $group->id, 'user_id' => $actor,
                'created_at' => now(), 'updated_at' => now()]);
            // Only ungrouped companies the actor already administers may be moved in.
            foreach (array_unique($data['company_ids'] ?? []) as $companyId) {
                $company = Company::whereKey($companyId)->whereNull('company_group_id')->lockForUpdate()->first();
                if (!$company || !app(CompanyContextResolver::class)->canManageFinancialYears($actor, $company->id)) {
                    throw ValidationException::withMessages(['company_ids' => 'Only ungrouped companies you administer can join a group.']);
                }
                $company->update(['company_group_id' => $group->id]);
            }

            return $group;
        });
    }

    /** Group admins see every company in the group, including companies created later. */
    public function addGroupAdmin(int $actor, int $groupId, int $userId): void
    {
        if (!$this->canManageGroup($actor, $groupId)) {
            throw new AuthorizationException('Group administrator required.');
        }
        if (!$this->isInstallationAdmin($userId)) {
            throw ValidationException::withMessages(['user_id' => 'Group administrators must have the Admin or Owner role.']);
        }
        DB::transaction(function () use ($groupId, $userId) {
            DB::table('company_group_admins')->insertOrIgnore(['company_group_id' => $groupId, 'user_id' => $userId,
                'created_at' => now(), 'updated_at' => now()]);
            foreach (Company::where('company_group_id', $groupId)->pluck('id') as $companyId) {
                $this->grant($companyId, $userId);
            }
        });
    }

    /**
     * Opens a new legal company with its MAIN branch, first financial year, main warehouse, chart of accounts and
     * industry profile. The actor and the group's admins become members of every branch.
     */
    public function createCompany(int $actor, array $input, ?\Illuminate\Http\UploadedFile $logo = null): Company
    {
        $groupId = isset($input['company_group_id']) && $input['company_group_id'] !== '' ? (int) $input['company_group_id'] : null;
        if (!$this->canManageGroup($actor, $groupId)) {
            throw new AuthorizationException('Group administrator required to add a company.');
        }
        $input['code'] = strtoupper($input['code'] ?? '') ?: $this->code($input['trade_name'] ?? $input['legal_name'] ?? '', 'companies');
        $data = Validator::make($input, [
            'legal_name' => 'required|string|max:255', 'trade_name' => 'nullable|string|max:255',
            'code' => 'required|string|max:50|regex:/^[A-Z0-9_-]+$/|unique:companies,code',
            'industry' => ['required', Rule::in(array_keys(IndustryCatalog::SUBTYPES))],
            'subtype' => 'nullable|string|max:50',
            'state_code' => 'nullable|string|max:20',
            'base_currency_id' => 'nullable|integer|exists:currencies,id',
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'fy_start' => 'required|date_format:Y-m-d',
            'fy_end' => 'required|date_format:Y-m-d|after:fy_start',
        ])->validate();
        $subtypes = IndustryCatalog::SUBTYPES[$data['industry']];
        $data['subtype'] = ($data['subtype'] ?? null) ?: array_key_first($subtypes);
        if (!array_key_exists($data['subtype'], $subtypes)) {
            throw ValidationException::withMessages(['subtype' => 'Select a business type for this industry.']);
        }

        return DB::transaction(function () use ($actor, $groupId, $data, $input, $logo) {
            $company = Company::create([
                'company_group_id' => $groupId, 'code' => $data['code'], 'legal_name' => $data['legal_name'],
                'trade_name' => $data['trade_name'] ?? null, 'country_code' => 'IN', 'state_code' => $data['state_code'] ?? null,
                'base_currency_id' => $data['base_currency_id']
                    ?? (Schema::hasTable('currencies') ? DB::table('currencies')->where('code', 'INR')->value('id') : null), 'timezone' => $data['timezone'],
            ]);
            $branch = $company->branches()->create(['code' => 'MAIN', 'name' => 'Main branch']);
            $year = $company->fiscalYears()->create(['name' => $this->yearName($data['fy_start'], $data['fy_end']),
                'start_date' => $data['fy_start'], 'end_date' => $data['fy_end'], 'status' => 'open', 'is_closed' => false]);
            $admins = $groupId ? DB::table('company_group_admins')->where('company_group_id', $groupId)->pluck('user_id')->all() : [];
            foreach (array_unique([$actor, ...$admins]) as $userId) {
                $this->grant($company->id, (int) $userId);
            }
            DB::table('warehouses')->insert(['name' => (($data['trade_name'] ?? '') ?: $data['legal_name']).' - Main', 'address' => '-',
                'is_active' => true, 'company_id' => $company->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->installChart($company->id);

            $context = new CompanyContext($company->id, $branch->id, $year->id);
            $this->applyIndustry($data['industry'], $data['subtype'], $context, $actor);
            // Address, contact, PAN, GSTIN and logo given at creation go through the same path as Settings › Company profile.
            $profile = array_filter(array_intersect_key($input, array_flip(['address', 'city', 'postal_code', 'phone', 'email', 'website', 'pan', 'gstin', 'registration_type'])),
                fn ($value) => $value !== null && $value !== '');
            if ($logo || $profile) {
                app(CompanyProfileService::class)->save($company, $profile + ['legal_name' => $company->legal_name, 'trade_name' => $company->trade_name,
                    'state_code' => $company->state_code, 'gst_effective_from' => $data['fy_start']], $logo, $context, $actor);
            }
            $this->installStarterMasters($company->refresh());
            // A GST-registered (or declared unregistered) company bills under GST rules from its first invoice: install the standard slabs now.
            if ((!empty($profile['gstin']) || ($profile['registration_type'] ?? null) === 'unregistered') && config('compliance.enabled') && Schema::hasTable('tax_categories')) {
                app(\App\Services\Tax\GstSetupService::class)->installDefaults($context, $actor);
            }
            DB::table('company_setup_audits')->insert(['company_id' => $company->id, 'actor_id' => $actor,
                'action' => 'company_created', 'before_json' => '[]',
                'after_json' => json_encode(['group_id' => $groupId, 'code' => $company->code, 'industry' => $data['industry'],
                    'subtype' => $data['subtype'], 'branch_id' => $branch->id, 'financial_year_id' => $year->id,
                    'idempotency_key' => $input['idempotency_key'] ?? null], JSON_THROW_ON_ERROR),
                'created_at' => now()]);

            return $company;
        });
    }

    private function grant(int $companyId, int $userId): void
    {
        DB::table('company_user')->insertOrIgnore(['company_id' => $companyId, 'user_id' => $userId,
            'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('company_branches')->where('company_id', $companyId)->where('is_active', true)->pluck('id') as $branchId) {
            DB::table('company_user_branches')->insertOrIgnore(['company_id' => $companyId, 'user_id' => $userId,
                'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Masters every company needs before its first bill: customer group, product category, biller (the company itself),
     * Cash and Bank payment accounts on the chart's cash/bank ledgers, and the Optech sale/purchase types, bill sundries
     * and document series. Idempotent: only what is missing is added.
     */
    public function installStarterMasters(Company $company): void
    {
        // A company that already has any row of a master keeps its own setup; only empty masters are filled.
        $empty = fn (string $table) => Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')
            && !DB::table($table)->where('company_id', $company->id)->exists();
        $fill = array_filter(['customer_groups' => true, 'categories' => true, 'billers' => true, 'accounts' => true], fn ($v, $table) => $empty($table), ARRAY_FILTER_USE_BOTH);
        $insert = function (string $table, array $match, array $values) use ($company, $fill) {
            if (!isset($fill[$table])) return;
            $columns = array_flip(Schema::getColumnListing($table));
            DB::table($table)->insert(array_intersect_key(['company_id' => $company->id] + $match + $values
                + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()], $columns));
        };
        $name = $company->trade_name ?: $company->legal_name;
        $state = $company->state_code ? (\App\Services\Tax\Gstin::$states[$company->state_code] ?? null) : null;
        $insert('customer_groups', ['name' => 'General'], ['percentage' => 0]);
        $insert('categories', ['name' => 'General'], []);
        $insert('billers', ['name' => $name], ['company_name' => $company->legal_name, 'email' => $company->email ?? '',
            'phone_number' => $company->phone ?? '', 'address' => $company->address ?? '', 'city' => $company->city ?? '', 'state' => $state,
            'postal_code' => $company->postal_code, 'country' => 'India',
            'vat_number' => Schema::hasTable('tax_registrations') ? DB::table('tax_registrations')->where('company_id', $company->id)->whereNull('effective_to')->value('gstin') : null]);
        $isDefault = true;
        foreach (['cash' => ['Cash', 'Cash'], 'bank' => ['Bank', 'Bank Account']] as $subType => [$label, $type]) {
            $ledger = DB::table('chart_of_accounts')->where('company_id', $company->id)->where('sub_type', $subType)->where('is_active', true)->orderBy('code')->first();
            $insert('accounts', ['name' => $label], ['account_no' => strtoupper($company->code).'-'.strtoupper($label), 'type' => $type,
                'initial_balance' => 0, 'total_balance' => 0, 'is_default' => $isDefault, 'is_payment' => true, 'chart_of_account_id' => $ledger?->id,
                'note' => 'Created with the company']);
            $isDefault = false;
        }
        \Database\Seeders\OptechMasterSeeder::seedCompany($company);
    }

    private function installChart(int $companyId): void
    {
        $ids = [];
        foreach (ChartOfAccountsSeeder::accounts() as $account) {
            $chart = (new ChartOfAccount)->forceFill([
                'company_id' => $companyId, 'code' => $account['code'], 'name' => $account['name'], 'type' => $account['type'],
                'sub_type' => $account['sub_type'], 'parent_id' => $ids[$account['parent_code']] ?? null,
                'is_system' => $account['is_system'], 'description' => $account['description'], 'is_active' => true,
            ]);
            $chart->save();
            $ids[$account['code']] = $chart->id;
        }
        if (Schema::hasTable('semantic_account_mappings') && Schema::hasColumn('chart_of_accounts', 'control_type')) {
            app(SemanticAccountResolver::class)->seedCompany($companyId);
        }
    }

    /** Capability presets follow the optional-activation gate; the profile, attributes and processes always install. */
    private function applyIndustry(string $profile, string $subtype, CompanyContext $context, int $actor): void
    {
        if (!Schema::hasTable('company_industry_settings')) {
            return;
        }
        try {
            app(CapabilityService::class)->applyProfile($profile, $context, $actor);
            foreach (IndustryCatalog::SUBTYPES[$profile][$subtype] as $capability) {
                app(CapabilityService::class)->enable($capability, [], $context, $actor);
            }
        } catch (ValidationException $error) {
            if (!array_key_exists('capability', $error->errors())) {
                throw $error;
            }
            app(IndustryProfileService::class)->installDefaults($profile, $context, $actor);
        }
        app(IndustryProfileService::class)->useSubtype($profile, $subtype, $context->companyId);
    }

    private function code(string $name, string $table): string
    {
        $base = substr(strtoupper(Str::slug($name, '_')), 0, 40) ?: 'CO';
        $code = $base;
        for ($i = 2; DB::table($table)->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }

    /**
     * Company administrators edit the master details from the picker. Names, address, contact, tax and logo go through
     * CompanyProfileService (the same path as Settings › Company profile); moving the company into another group also
     * needs administration of that group, whose admins then get access.
     */
    public function updateCompany(int $actor, int $companyId, array $input, ?\Illuminate\Http\UploadedFile $logo = null): Company
    {
        $company = Company::where('status', 'active')->findOrFail($companyId);
        if (!app(CompanyContextResolver::class)->canManageFinancialYears($actor, $company->id)) {
            throw new AuthorizationException('Company administrator required.');
        }
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? ''))) ?: $company->code;
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('companies', 'code')->ignore($company->id)],
            'company_group_id' => 'nullable|integer|min:1',
        ])->validate();
        $groupId = !empty($data['company_group_id']) ? (int) $data['company_group_id'] : null;
        $moving = $groupId !== ($company->company_group_id ? (int) $company->company_group_id : null);
        if ($moving && !$this->canManageGroup($actor, $groupId)) {
            throw new AuthorizationException('Group administrator required to move a company into this group.');
        }

        return DB::transaction(function () use ($actor, $company, $data, $groupId, $moving, $input, $logo) {
            $before = $company->only(['code', 'company_group_id']);
            $company->forceFill(['code' => $data['code'], 'company_group_id' => $groupId])->save();
            if ($moving && $groupId) {
                foreach (DB::table('company_group_admins')->where('company_group_id', $groupId)->pluck('user_id') as $userId) {
                    $this->grant($company->id, (int) $userId);
                }
            }
            $profile = array_intersect_key($input, array_flip([...CompanyProfileService::FIELDS, 'gstin', 'registration_type']));
            app(CompanyProfileService::class)->save($company->refresh(), $profile, $logo, $this->profileContext($company), $actor);
            $this->audit($company->id, $actor, 'company_updated', $before, $company->only(['code', 'company_group_id']));

            return $company->refresh();
        });
    }

    /**
     * Deleting hides the company from every picker and blocks entering it; its books, stock and documents stay, so an
     * administrator can restore it. The short code must be typed to confirm.
     */
    public function deleteCompany(int $actor, int $companyId, string $confirmCode): Company
    {
        $company = $this->administeredCompany($actor, $companyId, 'active');
        if (strtoupper(trim($confirmCode)) !== strtoupper($company->code)) {
            throw ValidationException::withMessages(['confirm_code' => 'Type the company short code '.$company->code.' to confirm.']);
        }
        $company->forceFill(['status' => 'deleted'])->save();
        $this->audit($company->id, $actor, 'company_deleted', ['status' => 'active'], ['status' => 'deleted']);

        return $company;
    }

    public function restoreCompany(int $actor, int $companyId): Company
    {
        $company = $this->administeredCompany($actor, $companyId, 'deleted');
        $company->forceFill(['status' => 'active'])->save();
        $this->audit($company->id, $actor, 'company_restored', ['status' => 'deleted'], ['status' => 'active']);

        return $company;
    }

    /** Deleted companies this administrator can restore. */
    public function deletedCompanies(int $userId): \Illuminate\Support\Collection
    {
        if (!$this->isInstallationAdmin($userId)) {
            return collect();
        }
        return Company::where('status', 'deleted')
            ->whereIn('id', DB::table('company_user')->where('user_id', $userId)->select('company_id'))
            ->orderBy('legal_name')->get(['id', 'code', 'legal_name', 'trade_name'])
            ->filter(fn (Company $company) => app(CompanyContextResolver::class)->canManageFinancialYears($userId, $company->id))->values();
    }

    public function updateGroup(int $actor, int $groupId, array $input): CompanyGroup
    {
        $group = CompanyGroup::where('status', 'active')->findOrFail($groupId);
        if (!$this->canManageGroup($actor, $group->id)) {
            throw new AuthorizationException('Group administrator required.');
        }
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? ''))) ?: $group->code;
        $data = Validator::make($input, [
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('company_groups', 'code')->ignore($group->id)],
        ])->validate();
        $group->update(['name' => $data['name'], 'code' => $data['code']]);

        return $group;
    }

    /** A group is only a container: deleting it makes its companies independent and keeps their books untouched. */
    public function deleteGroup(int $actor, int $groupId): void
    {
        $group = CompanyGroup::where('status', 'active')->findOrFail($groupId);
        if (!$this->canManageGroup($actor, $group->id)) {
            throw new AuthorizationException('Group administrator required.');
        }
        DB::transaction(function () use ($actor, $group) {
            foreach (Company::where('company_group_id', $group->id)->lockForUpdate()->get() as $company) {
                $company->forceFill(['company_group_id' => null])->save();
                $this->audit($company->id, $actor, 'company_left_group', ['company_group_id' => $group->id, 'group' => $group->name], ['company_group_id' => null]);
            }
            DB::table('company_group_admins')->where('company_group_id', $group->id)->delete();
            $group->delete();
        });
    }

    private function administeredCompany(int $actor, int $companyId, string $status): Company
    {
        $company = Company::where('status', $status)->findOrFail($companyId);
        if (!$this->isInstallationAdmin($actor) || !app(CompanyContextResolver::class)->canManageFinancialYears($actor, $company->id)) {
            throw new AuthorizationException('Company administrator required.');
        }

        return $company;
    }

    /** The branch whose GST registration a GSTIN edit updates: MAIN (else the first branch), in the latest year. */
    private function profileContext(Company $company): CompanyContext
    {
        $branch = $company->branches()->orderByRaw("CASE WHEN code = 'MAIN' THEN 0 ELSE 1 END")->orderBy('id')->firstOrFail();
        $year = $company->fiscalYears()->orderByDesc('start_date')->firstOrFail();

        return new CompanyContext($company->id, $branch->id, $year->id);
    }

    private function audit(int $companyId, int $actor, string $action, array $before, array $after): void
    {
        if (Schema::hasTable('company_setup_audits')) {
            DB::table('company_setup_audits')->insert(['company_id' => $companyId, 'actor_id' => $actor, 'action' => $action,
                'before_json' => json_encode($before, JSON_THROW_ON_ERROR), 'after_json' => json_encode($after, JSON_THROW_ON_ERROR),
                'created_at' => now()]);
        }
    }

    private function yearName(string $start, string $end): string
    {
        $from = substr($start, 0, 4);
        $to = substr($end, 0, 4);

        return $from === $to ? 'FY '.$from : 'FY '.$from.'-'.substr($to, 2);
    }
}
