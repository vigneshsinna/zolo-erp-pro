<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Platform\BranchAccess;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\MenuSwitches;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Developer console: create and manage the admin logins of every company, and switch sections / screens off for the
 * active company (for example HRM). Only developer accounts reach it; admins cannot see or change a developer.
 */
class DeveloperController extends Controller
{
    private function authorizeDeveloper(): void
    {
        abort_unless(MenuSwitches::isDeveloper(), 403, 'Only a developer login can open the developer console.');
    }

    private function context(): CompanyContext
    {
        return request()->attributes->get(CompanyContext::class) ?? abort(409, 'Choose a company first.');
    }

    public function index(MenuSwitches $switches)
    {
        $this->authorizeDeveloper();
        $context = $this->context();
        $companies = DB::table('companies')->orderBy('trade_name')->get(['id', 'trade_name', 'legal_name']);
        // Two companies can share a trade name: the id tells them apart.
        $names = $companies->countBy(fn ($c) => $c->trade_name ?: $c->legal_name);
        $companies->each(function ($c) use ($names) {
            $c->label = ($c->trade_name ?: $c->legal_name).($names[$c->trade_name ?: $c->legal_name] > 1 ? ' #'.$c->id : '');
        });
        $admins = User::where('is_deleted', false)->whereIn('role_id', [1, 2])->where('is_developer', false)->orderBy('name')->get();
        $memberships = DB::table('company_user')->whereIn('user_id', $admins->pluck('id'))->get()->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('company_id')->map(fn ($id) => (int) $id)->all());
        $catalog = $switches->catalog($this->sidebar());
        $off = array_keys($switches->off($context->companyId));
        $company = $companies->firstWhere('id', $context->companyId);
        return view('backend.developer.index', compact('companies', 'admins', 'memberships', 'catalog', 'off', 'company'));
    }

    public function storeAdmin(Request $request)
    {
        $this->authorizeDeveloper();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('users')->where('is_deleted', false)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->where('is_deleted', false)],
            'password' => 'required|string|min:8|max:100',
            'company_ids' => 'required|array|min:1', 'company_ids.*' => 'integer|exists:companies,id',
        ], ['company_ids.required' => 'Tick at least one company for the new admin.']);
        DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => bcrypt($data['password']),
                'role_id' => 1, 'is_active' => true, 'is_deleted' => false, 'phone' => '']);
            $this->syncCompanies($user->id, $data['company_ids']);
        });
        return redirect()->route('developer.index')->with('message', 'Admin '.$data['name'].' created. They can sign in with '.$data['email'].'.');
    }

    public function status(Request $request, int $user)
    {
        $this->authorizeDeveloper();
        $admin = $this->admin($user);
        $admin->forceFill(['is_active' => !$admin->is_active])->save();
        return redirect()->route('developer.index')->with('message', $admin->name.' is now '.($admin->is_active ? 'active' : 'blocked from signing in').'.');
    }

    public function password(Request $request, int $user)
    {
        $this->authorizeDeveloper();
        $admin = $this->admin($user);
        $data = $request->validate(['password' => 'required|string|min:8|max:100']);
        $admin->forceFill(['password' => bcrypt($data['password'])])->save();
        return redirect()->route('developer.index')->with('message', 'Password of '.$admin->name.' changed.');
    }

    public function companies(Request $request, int $user)
    {
        $this->authorizeDeveloper();
        $admin = $this->admin($user);
        $data = $request->validate(['company_ids' => 'required|array|min:1', 'company_ids.*' => 'integer|exists:companies,id'],
            ['company_ids.required' => 'An admin needs at least one company. Block the login instead to stop all access.']);
        DB::transaction(fn () => $this->syncCompanies($admin->id, $data['company_ids']));
        return redirect()->route('developer.index')->with('message', 'Companies of '.$admin->name.' updated.');
    }

    public function switches(Request $request, MenuSwitches $switches)
    {
        $this->authorizeDeveloper();
        $context = $this->context();
        $data = $request->validate(['on' => 'sometimes|array', 'on.*' => 'string|max:120']);
        // The form ticks what stays on; everything in the catalog it leaves unticked is switched off.
        $catalog = $switches->catalog($this->sidebar());
        $keys = [];
        foreach ($catalog as $section) {
            $keys[] = $section['key'];
            foreach ($section['items'] as $item) $keys[] = $item['key'];
        }
        $off = array_values(array_diff($keys, $data['on'] ?? []));
        $switches->save($context->companyId, $off, $catalog, (int) Auth::id());
        return redirect()->route('developer.index')->with('message', 'Sections saved for this company. Everyone except developers sees the change on their next page.');
    }

    private function admin(int $id): User
    {
        $user = User::where('is_deleted', false)->whereIn('role_id', [1, 2])->findOrFail($id);
        abort_if((bool) $user->is_developer, 403, 'A developer login is managed with the erp:developer command, not here.');
        return $user;
    }

    /** Membership of exactly these companies, with access to every active branch of each. */
    private function syncCompanies(int $userId, array $companyIds): void
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        DB::table('company_user_branches')->where('user_id', $userId)->whereNotIn('company_id', $companyIds)->delete();
        DB::table('company_user')->where('user_id', $userId)->whereNotIn('company_id', $companyIds)->delete();
        $hasDefault = DB::table('company_user')->where('user_id', $userId)->where('is_default', true)->exists();
        foreach ($companyIds as $i => $companyId) {
            if (!DB::table('company_user')->where(['company_id' => $companyId, 'user_id' => $userId])->exists()) {
                DB::table('company_user')->insert(['company_id' => $companyId, 'user_id' => $userId,
                    'is_default' => !$hasDefault && $i === 0, 'updated_at' => now(), 'created_at' => now()]);
            }
            foreach (DB::table('company_branches')->where('company_id', $companyId)->where('is_active', true)->pluck('id') as $branchId) {
                DB::table('company_user_branches')->updateOrInsert(['company_id' => $companyId, 'user_id' => $userId, 'branch_id' => $branchId],
                    ['updated_at' => now(), 'created_at' => now()]);
            }
        }
        app(BranchAccess::class)->forget();
    }

    /** The sidebar as this developer sees it: the switch catalog follows whatever the menu currently offers. */
    private function sidebar(): string
    {
        return view('backend.layout.sidebar')->render();
    }
}
