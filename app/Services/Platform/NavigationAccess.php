<?php

namespace App\Services\Platform;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * What the signed-in user may see in navigation: company role (with override), loaded permissions and enabled
 * capabilities. Menus only hide links for UX; every target route authorises again on the server.
 */
final class NavigationAccess
{
    public readonly ?CompanyContext $context;
    public readonly bool $isAdmin;
    public readonly array $capabilities;
    private Collection $permissions;

    /** @param Collection|null $permissionsList rows with a `name` already loaded by the layout, to avoid re-querying */
    public function __construct(?Collection $permissionsList = null)
    {
        $this->capabilities = app(CapabilityService::class)->forNavigation();
        $this->context = request()->attributes->get(CompanyContext::class);
        $roleId = $this->context
            ? (DB::table('company_user')->where('company_id', $this->context->companyId)->where('user_id', Auth::id())->value('role_id_override') ?? Auth::user()->role_id)
            : Auth::user()->role_id;
        $this->isAdmin = Auth::check() && in_array((int) $roleId, [1, 2], true);
        $this->permissions = $this->context
            ? ($permissionsList ?? DB::table('permissions')
                ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('role_has_permissions.role_id', $roleId)->select('permissions.name')->get())
                ->pluck('name')->flip()
            : collect();
    }

    public function can(string $permission): bool
    {
        return $this->context ? $this->permissions->has($permission) : Auth::user()->can($permission);
    }

    /** Admin, or any one of the permissions. */
    public function allows(string ...$permissions): bool
    {
        if ($this->isAdmin) return true;
        foreach ($permissions as $permission) {
            if ($this->can($permission)) return true;
        }
        return false;
    }

    public function has(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
