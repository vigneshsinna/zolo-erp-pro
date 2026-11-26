<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanySetupService
{
    public function choices(int $actor): array
    {
        $result = [];
        foreach (Company::whereIn('id', DB::table('company_user')->where('user_id', $actor)->select('company_id'))->where('status', 'active')->get() as $company) {
            app(CompanyContextResolver::class)->authorizedCompany($actor, $company->id);
            $result[] = $company->only(['id', 'code', 'legal_name']) + [
                'branches' => $company->branches()->where('is_active', true)->whereIn('id', DB::table('company_user_branches')
                    ->where('company_id', $company->id)->where('user_id', $actor)->select('branch_id'))->get(['id', 'name'])->toArray(),
                'years' => $company->fiscalYears()->get(['id', 'name', 'status'])->toArray(),
            ];
        }
        return $result;
    }

    public function company(int $actor, ?int $id): Company
    {
        $resolver = app(CompanyContextResolver::class);
        $company = $resolver->authorizedCompany($actor, $id);
        if (!$resolver->canManageFinancialYears($actor, $company->id)) {
            throw new AuthorizationException('Company administrator required for setup.');
        }
        return $company;
    }

    public function update(int $actor, ?int $id, array $input): Company
    {
        $company = $this->company($actor, $id);
        $data = Validator::make($input, [
            'legal_name' => 'required|string|max:255', 'trade_name' => 'nullable|string|max:255',
            'state_code' => 'required|string|max:20', 'base_currency_id' => 'required|integer|exists:currencies,id',
            'print_format' => 'required|in:a4,thermal,dot_matrix',
        ])->validate();
        return DB::transaction(function () use ($company, $actor, $data) {
            $company = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $before = $company->only(['legal_name', 'trade_name', 'state_code', 'base_currency_id']);
            $settings = $company->settings_json ?? [];
            $settings['print_format'] = $data['print_format'];
            $company->fill(array_diff_key($data, ['print_format' => true]));
            $company->settings_json = $settings;
            $company->save();
            $this->audit($company->id, $actor, 'company_setup', $before, $data);
            return $company;
        });
    }

    public function location(int $actor, ?int $id, array $input): Warehouse
    {
        $company = $this->company($actor, $id);
        $data = Validator::make($input, ['branch_id' => 'required|integer|min:1',
            'name' => 'required|string|max:255', 'address' => 'required|string|max:1000',
            'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:255'])->validate();
        return DB::transaction(function () use ($company, $actor, $data) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $branch = $company->branches()->where('is_active', true)->whereKey($data['branch_id'])
                ->whereIn('id', DB::table('company_user_branches')->where('company_id', $company->id)->where('user_id', $actor)->select('branch_id'))->firstOrFail();
            $existing = Warehouse::where('company_id', $company->id)->where('branch_id', $branch->id)->where('name', $data['name'])->first();
            if ($existing) {
                foreach (['address', 'phone', 'email'] as $field) {
                    if (($existing->$field ?? null) !== ($data[$field] ?? null)) {
                        throw ValidationException::withMessages(['name' => 'This warehouse name already has different details.']);
                    }
                }
                return $existing;
            }
            app(LicenseService::class)->assertCanAddWarehouse($company->id);
            $warehouse = Warehouse::forceCreate($data + ['company_id' => $company->id, 'is_active' => true]);
            $this->audit($company->id, $actor, 'warehouse_setup', [], ['warehouse_id' => $warehouse->id, 'branch_id' => $branch->id, 'name' => $warehouse->name]);
            return $warehouse;
        });
    }

    public function branch(int $actor, ?int $id, array $input): \App\Models\CompanyBranch
    {
        $company = $this->company($actor, $id);
        $data = Validator::make($input, ['code' => 'required|string|max:50|regex:/^[A-Z0-9_-]+$/', 'name' => 'required|string|max:255'])->validate();
        return DB::transaction(function () use ($company, $actor, $data) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $branch = $company->branches()->where('code', $data['code'])->first();
            if ($branch && $branch->name !== $data['name']) throw ValidationException::withMessages(['code' => 'Branch code already has different details.']);
            if (!$branch) {
                app(LicenseService::class)->assertCanAddBranch($company->id);
                $branch = $company->branches()->create($data + ['branch_type' => 'branch']);
                $this->audit($company->id, $actor, 'branch_setup', [], ['branch_id' => $branch->id] + $data);
                // A new branch numbers its documents from the start, not through automatic fallback series.
                foreach (DB::table('fiscal_years')->where('company_id', $company->id)->where('is_closed', false)->pluck('id') as $yearId) {
                    \Database\Seeders\OptechMasterSeeder::seedSeries($company->id, $branch->id, (int) $yearId);
                }
            }
            DB::table('company_user_branches')->updateOrInsert(['company_id' => $company->id, 'user_id' => $actor, 'branch_id' => $branch->id]);
            return $branch;
        });
    }

    private function audit(int $company, int $actor, string $action, array $before, array $after): void
    {
        DB::table('company_setup_audits')->insert(['company_id' => $company, 'actor_id' => $actor,
            'action' => $action, 'before_json' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_json' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
