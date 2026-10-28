<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Auth;
use App\Models\Language;
use Illuminate\Support\Facades\Cache;

class Common
{
    use \App\Traits\TenantInfo;

    public function handle(Request $request, Closure $next)
    {

        $context = $request->attributes->get(\App\Services\Platform\CompanyContext::class);
        // Scoped reads stay fresh while legacy installation caches retain their invalidation keys.
        $remember = fn ($key, $ttl, $read) => $context ? $read() : Cache::remember($key, $ttl, $read);
        $roleId = $context
            ? (DB::table('company_user')->where('company_id', $context->companyId)->where('user_id', Auth::id())->value('role_id_override') ?? Auth::user()->role_id)
            : Auth::user()->role_id;

        // Installation settings remain shared; company identity comes from trusted context.
        //get general setting value: installation-wide, so it stays cached even under a company context; the legacy
        // reports and dashboard read this key directly. Company identity and currency come from the company below.
        $general_setting =  Cache::remember('general_setting', 60*60*24*365, function () {
            return DB::table('general_settings')->latest()->first();
        });

        // ✅ Timezone setup
        $company = $context ? \App\Models\Company::findOrFail($context->companyId) : null;
        $timezone = $company?->timezone ?? $general_setting->timezone ?? config('app.timezone');
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        $todayDate = date("Y-m-d");
        if(config('database.connections.zoloerp_landlord')) {
            $subdomain = $this->getTenantId();
            if($general_setting->expiry_date) {
                $expiry_date = date("Y-m-d", strtotime($general_setting->expiry_date));
                if($todayDate > $expiry_date) {
                    auth()->logout();
                    return redirect('https://'.env('CENTRAL_DOMAIN').'/contact-for-renewal?id='.$subdomain);
                }
            }
            View::share('subdomain', $subdomain);
        }
        //setting language
        View::composer(['backend.layout.main'], function ($view) {
            $languages = Cache::rememberForever('languages_list', function () {
                if (Schema::hasTable('languages')) {
                    return Language::select('id', 'name')->orderBy('name')->get();
                }
                else {
                    return collect();
                }
            });

            $view->with('languages', $languages);
        });

        //setting theme
        if(isset($_COOKIE['theme'])) {
            View::share('theme', $_COOKIE['theme']);
        }
        else {
            View::share('theme', 'light');
        }
        $currency = $remember('currency', 60*60*24*365, function () {
            $settingData = DB::table('general_settings')->select('currency')->latest()->first();
            return \App\Models\Currency::find($settingData->currency);
        });

        if ($company?->base_currency_id) {
            $currency = \App\Models\Currency::findOrFail($company->base_currency_id);
        }
        View::share('general_setting', $general_setting);
        View::share('currency', $currency);
        config(['staff_access' => $general_setting->staff_access, 'is_packing_slip' => $general_setting->is_packing_slip, 'date_format' => $general_setting->date_format, 'currency' => $currency->symbol ?? $currency->code, 'currency_position' => $general_setting->currency_position, 'decimal' => $general_setting->decimal, 'is_zatca' => $general_setting->is_zatca, 'company_name' => $company?->legal_name ?? $general_setting->company_name, 'vat_registration_number' => $general_setting->vat_registration_number, 'without_stock' => $general_setting->without_stock, 'addons' => $general_setting->modules]);

        $alert_product = $context ? 0 : DB::table('products')->when($context, fn ($q) => $q->where('company_id', $context->companyId))->where('is_active', true)->whereColumn('alert_quantity', '>', 'qty')->count();
        $dso_alert_product = $context ? null : DB::table('dso_alerts')->select('number_of_products')->whereDate('created_at', date("Y-m-d"))->first();
        if($dso_alert_product)
            $dso_alert_product_no = $dso_alert_product->number_of_products;
        else
            $dso_alert_product_no = 0;

        // Dynamic days from settings
        $days = $general_setting->expiry_alert_days ?? 0;
        // dd($days);

        $expire_alert_products = $context ? 0 : DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->when($context, fn ($q) => $q->where('products.company_id', $context->companyId))
            ->where('products.is_active', true)
            ->where('product_batches.qty', '>', 0)
            ->whereDate('product_batches.expired_date', '<=', now()->addDays($days)->format('Y-m-d'))
            ->count();

        // View share (exact same style)
        View::share([
            'alert_product'          => $alert_product,
            'dso_alert_product_no'   => $dso_alert_product_no,
            'expire_alert_products'  => $expire_alert_products,
        ]);


        $role = DB::table('roles')->find($roleId);
        View::share('role', $role);
        View::share('permission_list', DB::table('permissions')->get());
        View::share('role_has_permissions', DB::table('role_has_permissions')->where('role_id', $roleId)->get());
        $role_has_permissions_list = DB::table('permissions')
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_id', $roleId)->select('permissions.name')->get();
        View::share('role_has_permissions_list', $role_has_permissions_list);
        $categories_list = $remember('category_list', 60*60*24*365, function () use ($context) {
            return DB::table('categories')->when($context, fn ($q) => $q->where('company_id', $context->companyId))
                ->where('is_active', true)->get();
        });
        View::share('categories_list', $categories_list);

        return $next($request);
    }
}
