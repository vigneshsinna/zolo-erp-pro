<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use App\Models\Product_Warehouse;
use App\Models\Translation;
use App\Services\Inventory\LegacyStockShadow;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\App;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Auth;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(LegacyStockShadow::class);
        // Company / branch scoping consults these on almost every query; both are dropped as soon as what they hold can change.
        $this->app->singleton(\App\Support\Database\SchemaCache::class);
        $this->app->singleton(\App\Services\Platform\BranchAccess::class);
        // Asked for every menu link and pill: one instance per request keeps the company's switches loaded once.
        $this->app->scoped(\App\Services\Platform\MenuSwitches::class);
        Event::listen(\Illuminate\Database\Events\QueryExecuted::class, function ($query) {
            if (preg_match('/^\s*(create|alter|drop|rename|truncate)\b/i', $query->sql)) {
                $this->app->make(\App\Support\Database\SchemaCache::class)->flush();
            }
            if (preg_match('/^\s*(insert|update|delete|replace)\b.*\bcompany_(user_branches|branches|user)\b/is', $query->sql)) {
                $this->app->make(\App\Services\Platform\BranchAccess::class)->forget();
            }
        });
        Event::listen(TenancyBootstrapped::class, function () {
            $this->app->make(\App\Support\Database\SchemaCache::class)->flush();
            $this->app->make(\App\Services\Platform\BranchAccess::class)->forget();
        });
        // Raw DB::table() reads/writes of company-owned tables follow the same request-company scope as Eloquent.
        \Illuminate\Database\Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) =>
            new \App\Support\Database\CompanyScopedConnection($pdo, $database, $prefix, $config));
        \Illuminate\Database\Connection::resolverFor('sqlite', fn ($pdo, $database, $prefix, $config) =>
            new \App\Support\Database\CompanyScopedSqliteConnection($pdo, $database, $prefix, $config));
    }

    /** Feeds legacy product_warehouse changes and transaction outcomes to the phase 4b stock shadow. */
    private function bootLegacyStockShadow(): void
    {
        $shadow = fn () => $this->app->make(LegacyStockShadow::class);
        foreach (['created', 'updated', 'deleted'] as $event) {
            Product_Warehouse::{$event}(fn (Product_Warehouse $row) => $shadow()->capture($row, $event));
        }
        Event::listen('eloquent.created: *', fn (string $name, array $models) => $shadow()->created($models[0]));
        Event::listen(TransactionRolledBack::class, fn ($event) => $shadow()->rolledBack($event->connection->transactionLevel()));
        Event::listen(TransactionCommitted::class, fn ($event) => $shadow()->committed($event->connection->transactionLevel()));
        // Attach to loaded and cached routes, including module routes registered later.
        $this->app->booted(function () {
            foreach ($this->app['router']->getRoutes() as $route) {
                [$controller, $method] = array_pad(explode('@', $route->getActionName()), 2, null);
                [$document, $methods] = LegacyStockShadow::WRITERS[$controller] ?? [null, []];
                if (in_array($method, $methods, true)) {
                    $route->middleware('legacy.stock'.($document ? ':'.$document : ''));
                }
            }
        });
    }

    /** Party GSTIN/state and product HSN follow their masters, whichever screen or import saved them. */
    private function bootGstMasterSync(): void
    {
        $gst = fn () => $this->app->make(\App\Services\Tax\GstSetupService::class);
        foreach ([\App\Models\Supplier::class => 'supplier', \App\Models\Customer::class => 'customer'] as $model => $type) {
            $model::saved(function ($party) use ($gst, $type) {
                if ($party->wasRecentlyCreated || $party->wasChanged(['vat_number', 'tax_no', 'state'])) $gst()->syncParty($type, $party);
            });
        }
        \App\Models\Product::saved(function ($product) use ($gst) {
            if ($product->hsn_code && $product->company_id && ($product->wasChanged('hsn_code') || $product->wasRecentlyCreated)) {
                $gst()->ensureCode((int) $product->company_id, $product->hsn_code, $gst()->kindFor($product->type), $product->name);
            }
        });
    }

    public function boot()
    {
        Schema::defaultStringLength(191);
        // A label with no row in the translations table shows its readable text ("Stock"), never the raw key ("db.Stock").
        $this->app['translator']->handleMissingKeysUsing(fn ($key) => is_string($key) && str_starts_with($key, 'db.') ? substr($key, 3) : $key);
        $this->app->bind(\App\ViewModels\ISmsModel::class, \App\ViewModels\SmsModel::class);
        $this->app->booted(function () {
            foreach ($this->app['router']->getRoutes() as $route) {
                [$controller, $method] = array_pad(explode('@', $route->getActionName()), 2, null);
                if (in_array($controller, [\App\Http\Controllers\ReturnController::class, \App\Http\Controllers\ReturnPurchaseController::class,
                    \App\Http\Controllers\DamageStockController::class, \App\Http\Controllers\ExchangeController::class], true)
                    || ($controller === \App\Http\Controllers\SaleController::class && $method === 'genInvoice')) {
                    $route->middleware(\App\Http\Middleware\ComplianceRouteAdapter::class);
                }
            }
        });
        $this->bootLegacyStockShadow();
        $this->bootGstMasterSync();

        Blade::if('can', function ($permission) {
            $user = Auth::user();
            if (!$user) {
                return false;
            }
            if ($user->role_id == 1) {
                return true;
            }
            $role_has_permissions_list = Cache::remember(
                'role_has_permissions_list' . $user->role_id,
                60 * 60 * 24 * 365,
                function () use ($user) {
                    return DB::table('permissions')
                        ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                        ->where('role_id', $user->role_id)
                        ->select('permissions.name')
                        ->get();
                }
            );
            $permissions = $role_has_permissions_list->pluck('name')->toArray();
            return in_array($permission, $permissions);
        });

        if (app()->runningInConsole()) {
            return;
        }

        $translationLogic = function () {
            try {
                if (!DB::connection()->getDatabaseName()) {
                    return;
                }
            } catch (\Exception $e) {
                // Skip logic if DB connection fails
                return;
            }

            try {
                if (isset($_COOKIE['language'])) {
                    App::setLocale($_COOKIE['language']);
                } elseif (Schema::hasTable('languages')) {
                    $language = DB::table('languages')->where('is_default', true)->first();
                    App::setLocale($language->language ?? 'en');
                } else {
                    App::setLocale('en');
                }

                if (Schema::hasTable('translations')) {
                    $currentLocale = App::getLocale();

                    $translations = Cache::rememberForever("translations_{$currentLocale}", function () use ($currentLocale) {
                        return \App\Models\Translation::getTrnaslactionsByLocale($currentLocale);
                    });

                    if (!empty($translations)) {
                        app('translator')->addLines($translations, $currentLocale);
                    }
                }
            } catch (\Exception $e) {
                // Optional: log the error
                // Log::error($e->getMessage());
            }
        };

        if (config('database.connections.zoloerp_landlord')) {
            ///new code for superadmin//
            if (!app()->bound('tenancy')) {
                $locale = null;
                if (config('database.connections.mysql.database') && Schema::hasTable('languages')) {
                    // Fallback to default language
                    $default_language = DB::table('languages')->where('is_default', true)->first();
                    $locale = $default_language->code ?? 'en';
                } else {
                    $locale = 'en';
                }

                // Finally, set the app locale
                App::setLocale($locale);

                // Check if language file exists
                $langFile = resource_path("lang/{$locale}.php");
                if (!file_exists($langFile)) {
                    $langFile = resource_path("lang/master.php");
                }

                $transData = include $langFile; // loads the array
                $translations = [];
                foreach ($transData as $group => $items) {
                    foreach ($items as $key => $value) {
                        $translations["{$group}.{$key}"] = $value;
                    }
                }
                // Merge translations into Laravel's translator
                app('translator')->addLines($translations, $locale);
            }
            ///new code for superadmin//

            Event::listen(TenancyBootstrapped::class, function () use ($translationLogic) {
                $translationLogic();
            });
        } else {
            $translationLogic();
        }
    }
}
