<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Roles;
use Auth;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index()
    {
        if(Auth::user()->role_id <= 2) {
            $lims_role_all = Roles::where('is_active', true)->get();
            return view('backend.role.create', compact('lims_role_all'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => [
                'max:255',
                    Rule::unique('roles')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
        ]);

        $data = $request->all();
        Roles::create($data);
        return redirect('role')->with('message', __('db.Data inserted successfully'));
    }

    public function edit($id)
    {
        if(Auth::user()->role_id <= 2) {
            $lims_role_data = Roles::find($id);
            return $lims_role_data;
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'name' => [
                'max:255',
                Rule::unique('roles')->ignore($request->role_id)->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
        ]);

        $input = $request->all();
        $lims_role_data = Roles::where('id', $input['role_id'])->first();
        $lims_role_data->update($input);
        return redirect('role')->with('message', __('db.Data updated successfully'));
    }

    public function permission($id)
    {
        if(Auth::user()->role_id <= 2) {
            $lims_role_data = Roles::findOrFail($id);
            $permissions = Role::findByName($lims_role_data->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            if(empty($all_permission))
                $all_permission[] = 'dummy text';
            return view('backend.role.permission', ['lims_role_data' => $lims_role_data, 'all_permission' => $all_permission, 'operation_permissions' => Permission::where(function ($query) {
                foreach (['accounting.%', 'manufacturing.%', 'job_work.%', 'profiles.%', 'projects.%', 'ai.%'] as $prefix) $query->orWhere('name', 'like', $prefix);
            })->orderBy('name')->pluck('name')]);
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function setPermission(Request $request)
    {
        abort_unless(Auth::user()->role_id <= 2, 403);
        if(!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));
        
        $lims_permissions = Permission::pluck('name')->toArray();
        $operations = $request->validate(['operation_permissions' => 'sometimes|array', 'operation_permissions.*' => ['string', Rule::in($lims_permissions)]])['operation_permissions'] ?? [];
        
        $lims_new_request_permissions = array_diff(
            array_keys($request->except('_token', 'role_id', 'credit_override', 'operation_permissions')),
            $lims_permissions
        );
        
        $lims_permissions = array_merge($lims_permissions, $lims_new_request_permissions);
        $lims_permissions = array_unique($lims_permissions);

        $role = Role::firstOrCreate(['id' => $request['role_id']]);

        foreach ($lims_permissions as $permission_name) {
            $permission = Permission::firstOrCreate(['name' => $permission_name]);
            
            if($permission_name === 'sales.override_credit' ? $request->boolean('credit_override') : ($request->has($permission_name) || in_array($permission_name, $operations, true))) {
                if(!$role->hasPermissionTo($permission_name)) {
                    $role->givePermissionTo($permission);
                }
            }
            else {
                if($permission) $role->revokePermissionTo($permission_name);
            }
        }

        cache()->forget('permissions');

        return redirect('role')->with('message', __('db.Permission updated successfully'));
    }

    public function destroy($id)
    {
        if(!config('app.user_verified'))
            return redirect()->back()->with('not_permitted', __('db.This feature is disable for demo!'));
        $lims_role_data = Roles::findOrFail($id);
        $lims_role_data->is_active = false;
        $lims_role_data->save();
        return redirect('role')->with('not_permitted', __('db.Data deleted successfully'));
    }
}
