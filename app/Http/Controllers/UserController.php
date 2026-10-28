<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Roles;
use App\Models\Biller;
use App\Models\Warehouse;
use App\Models\CustomerGroup;
use App\Models\Customer;
use DB;
use Auth;
use Hash;
use Keygen;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Mail\UserDetails;
use Mail;
use App\Models\MailSetting;

class UserController extends Controller
{
    use \App\Traits\MailInfo;

    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('users-index')){
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            // Only the active company's users; other companies' staff stay private.
            $context = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
            $lims_user_list = User::where('is_deleted', false)
                ->when($context && \Illuminate\Support\Facades\Schema::hasTable('company_user'), fn ($q) => $q->whereIn('id', \Illuminate\Support\Facades\DB::table('company_user')->where('company_id', $context->companyId)->select('user_id')))
                // Developer logins are managed from the developer console and stay out of an admin's list.
                ->when(!\App\Services\Platform\MenuSwitches::isDeveloper() && \Illuminate\Support\Facades\Schema::hasColumn('users', 'is_developer'), fn ($q) => $q->where('is_developer', false))->get();
            $numberOfUserAccount = User::where('is_active', true)->count();
            return view('backend.user.index', compact('lims_user_list', 'all_permission', 'numberOfUserAccount'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('users-add')){
            $lims_role_list = Roles::where('is_active', true)->get();
            $lims_biller_list = Biller::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_customer_group_list = CustomerGroup::where('is_active', true)->get();
            $numberOfUserAccount = User::where('is_active', true)->count();
            return view('backend.user.create', compact('lims_role_list', 'lims_biller_list', 'lims_warehouse_list', 'lims_customer_group_list', 'numberOfUserAccount'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function generatePassword()
    {
        $id = Keygen::numeric(6)->generate();
        return $id;
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => [
                'max:255',
                    Rule::unique('users')->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
            'email' => [
                'email',
                'max:255',
                    Rule::unique('users')->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
        ]);

        if($request->role_id == 5) {
            $this->validate($request, [
                'phone_number' => [
                    'max:255',
                        Rule::unique('customers')->where(function ($query) {
                        return $query->where('is_active', 1);
                    }),
                ],
            ]);
        }
        $data = $request->all();
        $message = 'User created successfully';
        $mail_setting = MailSetting::latest()->first();
        if($mail_setting) {
            $this->setMailInfo($mail_setting);
            try {
                Mail::to($data['email'])->send(new UserDetails($data));
            }
            catch(\Exception $e){
                $message = 'User created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        if(!isset($data['is_active']))
            $data['is_active'] = false;
        $data['is_deleted'] = false;
        $data['password'] = bcrypt($data['password']);
        $data['phone'] = $data['phone_number'];
        $user_data = User::create($data);
        $this->grantCompanyAccess($user_data);
        if($data['role_id'] == 5) {
            $data['user_id'] = $user_data->id;
            $data['name'] = $data['customer_name'];
            $data['phone_number'] = $data['phone'];
            $data['is_active'] = true;
            Customer::create($data);
        }
        return redirect('user')->with('message1', $message);
    }

    /** A user created inside a company context joins that company with the creator's branch grants. */
    private function grantCompanyAccess(User $user): void
    {
        $context = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
        if (!$context) {
            return;
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $context) {
            \Illuminate\Support\Facades\DB::table('company_user')->updateOrInsert(
                ['company_id' => $context->companyId, 'user_id' => $user->id], ['is_default' => true, 'updated_at' => now(), 'created_at' => now()]);
            $branches = \Illuminate\Support\Facades\DB::table('company_user_branches')->where('company_id', $context->companyId)
                ->where('user_id', Auth::id())->pluck('branch_id');
            foreach ($branches as $branchId) {
                \Illuminate\Support\Facades\DB::table('company_user_branches')->updateOrInsert(
                    ['company_id' => $context->companyId, 'user_id' => $user->id, 'branch_id' => $branchId], ['updated_at' => now(), 'created_at' => now()]);
            }
        });
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('users-edit')){
            $lims_user_data = $this->companyUser($id);
            $lims_role_list = Roles::where('is_active', true)->get();
            $lims_biller_list = Biller::where('is_active', true)->get();
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            [$company_branches, $user_branch_ids] = $this->branchAccess($lims_user_data->id);
            return view('backend.user.edit', compact('lims_user_data', 'lims_role_list', 'lims_biller_list', 'lims_warehouse_list', 'company_branches', 'user_branch_ids'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function update(Request $request, $id)
    {
        if(!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));

        $this->validate($request, [
            'name' => [
                'max:255',
                Rule::unique('users')->ignore($id)->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
            'email' => [
                'email',
                'max:255',
                    Rule::unique('users')->ignore($id)->where(function ($query) {
                    return $query->where('is_deleted', false);
                }),
            ],
        ]);

        $lims_user_data = $this->companyUser($id);
        $input = $request->except('password', 'branch_ids', 'branch_access');
        if(!isset($input['is_active']))
            $input['is_active'] = false;
        if(!empty($request['password']))
            $input['password'] = bcrypt($request['password']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $lims_user_data, $input) {
            $lims_user_data->update($input);
            if ($request->boolean('branch_access')) $this->syncBranchAccess($lims_user_data->id, (array) $request->input('branch_ids', []));
        });

        cache()->forget('user_role');
        return redirect('user')->with('message2', __('db.Data updated successfullly'));
    }

    public function toggleStatus(Request $request)
    {
        if(!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));
        
        $user = User::find($request->id);

        if ($user) {
            $this->guardDeveloper($user);
            $user->is_active = $request->is_active;
            $user->save();

            return response()->json(['success' => true, 'message' => 'User status updated successfully.']);
        }

        return response()->json(['success' => false, 'message' => 'User not found.']);
    } 

    public function superadminProfile($id)
    {
        $lims_user_data = User::findOrFail($id);
        return view('landlord.profile', compact('lims_user_data'));
    }

    public function profile($id)
    {
        $lims_user_data = User::findOrFail($id);
        $this->guardDeveloper($lims_user_data);
        return view('backend.user.profile', compact('lims_user_data'));
    }

    public function profileUpdate(Request $request, $id)
    {
        if(!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));

        $input = $request->all();
        $lims_user_data = User::findOrFail($id);
        $this->guardDeveloper($lims_user_data);
        $lims_user_data->update($input);
        return redirect()->back()->with('message3', __('db.Data updated successfullly'));
    }

    public function changePassword(Request $request, $id)
    {
        if(!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));

        $input = $request->all();
        $lims_user_data = User::findOrFail($id);
        $this->guardDeveloper($lims_user_data);
        if($input['new_pass'] != $input['confirm_pass'])
            return redirect("user/" .  "profile/" . $id )->with('message2', __("db.Please Confirm your new password"));

        if (Hash::check($input['current_pass'], $lims_user_data->password)) {
            $lims_user_data->password = bcrypt($input['new_pass']);
            $lims_user_data->save();
        }
        else {
            return redirect("user/" .  "profile/" . $id )->with('message1', __("db.Current Password does not match"));
        }
        auth()->logout();
        return redirect('/');
    }

    public function deleteBySelection(Request $request)
    {
        $user_id = $request['userIdArray'];
        
        foreach ($user_id as $id) {
            $lims_user_data = User::find($id);
            $this->guardDeveloper($lims_user_data);
            $lims_user_data->is_deleted = true;
            $lims_user_data->is_active = false;
            $lims_user_data->save();
        }
        return 'User deleted successfully!';
    }

    public function destroy($id)
    {
        if(!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));

        $lims_user_data = User::findOrFail($id);
        $this->guardDeveloper($lims_user_data);
        $lims_user_data->is_deleted = true;
        $lims_user_data->is_active = false;
        $lims_user_data->save();
        if(Auth::id() == $id){
            auth()->logout();
            return redirect('/login');
        }
        else
            return redirect('user')->with('message3', __('db.Data deleted successfullly'));
    }

    public function notificationUsers()
    {
        $notification_users = DB::table('users')->where([
            ['is_active', true],
            ['id', '!=', \Auth::user()->id],
            ['role_id', '!=', '5']
        ])->get();

        $html = '';
        foreach($notification_users as $user){
            $html .='<option value="'.$user->id.'">'.$user->name . ' (' . $user->email. ')'.'</option>';
        }

        return response()->json($html);
    }

    public function allUsers()
    {
        $lims_user_list = DB::table('users')->where('is_active', true)->get();

        $html = '';
        foreach($lims_user_list as $user){
            $html .='<option value="'.$user->id.'">'.$user->name . ' (' . $user->phone. ')'.'</option>';
        }

        return response()->json($html);
    }
    /** A user can be viewed or changed only from a company the user belongs to. */
    /** Only a developer may change a developer login (an admin cannot edit, block or delete it). */
    private function guardDeveloper(?User $user): void
    {
        abort_if($user && ($user->is_developer ?? false) && !\App\Services\Platform\MenuSwitches::isDeveloper(), 403,
            'This is a developer login. Only a developer can change it.');
    }

    private function companyUser($id): User
    {
        $user = User::findOrFail($id);
        $this->guardDeveloper($user);
        $context = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
        if ($context && \Illuminate\Support\Facades\Schema::hasTable('company_user')) {
            abort_unless(\Illuminate\Support\Facades\DB::table('company_user')->where('company_id', $context->companyId)->where('user_id', $user->id)->exists(), 404);
        }
        return $user;
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: list<int>} the active company's branches and the user's grants */
    private function branchAccess(int $userId): array
    {
        $context = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
        if (!$context || !\Illuminate\Support\Facades\Schema::hasTable('company_user_branches')) return [collect(), []];
        return [\Illuminate\Support\Facades\DB::table('company_branches')->where('company_id', $context->companyId)->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']),
            \Illuminate\Support\Facades\DB::table('company_user_branches')->where('company_id', $context->companyId)->where('user_id', $userId)->pluck('branch_id')->map(fn ($id) => (int) $id)->all()];
    }

    /** Branch grants in the active company; only a company administrator may change them and at least one must remain. */
    private function syncBranchAccess(int $userId, array $branchIds): void
    {
        $context = request()->attributes->get(\App\Services\Platform\CompanyContext::class);
        if (!$context) return;
        abort_unless(app(\App\Services\Platform\CompanyContextResolver::class)->canManageFinancialYears(Auth::id(), $context->companyId), 403, 'Company administrator required to change branch access.');
        $valid = \Illuminate\Support\Facades\DB::table('company_branches')->where('company_id', $context->companyId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id);
        $chosen = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($chosen->isEmpty() || $chosen->diff($valid)->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['branch_ids' => 'Tick at least one branch of this company.']);
        }
        $table = \Illuminate\Support\Facades\DB::table('company_user_branches');
        (clone $table)->where('company_id', $context->companyId)->where('user_id', $userId)->whereNotIn('branch_id', $chosen)->delete();
        foreach ($chosen as $branchId) {
            (clone $table)->updateOrInsert(['company_id' => $context->companyId, 'user_id' => $userId, 'branch_id' => $branchId], ['updated_at' => now(), 'created_at' => now()]);
        }
    }
}
