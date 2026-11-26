<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Platform\CompanyLicenseAudit;
use App\Models\Platform\SaasPlan;
use App\Models\User;
use App\Services\ERP\SaleService;
use App\Services\Platform\CapabilityCatalog;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanySetupService;
use App\Services\Platform\LicenseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CompanyContextTestCase;

class DeveloperLicensingPortalTest extends CompanyContextTestCase
{
    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['app.erp_optional_activation_ready' => true]);

        if (!Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable();
                $table->string('password')->nullable();
                $table->string('phone')->nullable();
                $table->timestamps();
            });
        }
        DB::table('users')->where('id', 1)->update(['email' => 'admin@companya.test', 'password' => bcrypt('secret1234')]);
        DB::table('users')->where('id', 2)->update(['email' => 'admin@companyb.test', 'password' => bcrypt('secret1234')]);

        $this->seedCompanyReaderFixtures();
        $this->installAccountingFoundation();
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name')->default('web');
                $t->timestamps();
            });
            Schema::create('role_has_permissions', function (Blueprint $t) {
                $t->unsignedBigInteger('permission_id');
                $t->unsignedInteger('role_id');
            });
        }
        (require database_path('migrations/2026_09_19_000007_create_repair_project_and_booking_tables.php'))->up();
        (require database_path('migrations/2026_10_07_000001_create_manufacturing_and_job_work.php'))->up();
        (require database_path('migrations/2026_10_08_000001_create_industry_profile_extensions.php'))->up();
        (require database_path('migrations/2026_10_09_000001_create_company_setup_audits.php'))->up();
        (require database_path('migrations/2026_10_13_000001_create_company_groups.php'))->up();
        (require database_path('migrations/2026_10_27_000001_add_developer_login_and_company_menu_switches.php'))->up();
        (require database_path('migrations/2026_11_26_000001_create_saas_licensing_tables.php'))->up();

        foreach (['address' => fn (Blueprint $t) => $t->text('address')->nullable(), 'is_active' => fn (Blueprint $t) => $t->boolean('is_active')->default(true)] as $col => $add) {
            if (!Schema::hasColumn('warehouses', $col)) {
                Schema::table('warehouses', $add);
            }
        }
        if (!Schema::hasColumn('units', 'unit_code')) {
            Schema::table('units', function (Blueprint $t) {
                $t->string('unit_code')->nullable();
                $t->string('unit_name')->nullable();
                $t->string('base_unit')->nullable();
                $t->string('operator')->nullable();
                $t->double('operation_value')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (!Schema::hasColumn('billers', 'company_id')) {
            Schema::table('billers', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable();
                $t->boolean('is_active')->default(true);
            });
        }
        if (!Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->increments('id');
                $table->string('code');
                $table->string('name');
            });
            DB::table('currencies')->insert(['id' => 1, 'code' => 'INR', 'name' => 'Indian Rupee']);
        }
        if (!Schema::hasTable('general_settings')) {
            Schema::create('general_settings', function (Blueprint $table) {
                $table->increments('id');
                $table->string('site_title')->default('zoloERP');
                $table->string('modules')->nullable();
                $table->unsignedInteger('currency')->nullable();
                $table->string('theme')->default('default.css');
                $table->boolean('is_rtl')->default(false);
                $table->text('font_css')->nullable();
                $table->timestamps();
            });
            DB::table('general_settings')->insert(['site_title' => 'zoloERP', 'modules' => '', 'currency' => 1]);
        }

        View::share('general_setting', (object) [
            'site_title' => 'zoloERP',
            'theme' => 'default.css',
            'is_rtl' => false,
            'font_css' => null,
            'custom_css' => null,
            'date_format' => 'Y-m-d',
            'modules' => '',
            'favicon' => null,
            'site_logo' => 'logo.png',
            'currency' => 1,
        ]);
        View::share('theme', 'light');
        View::share('alert_product', 0);
        View::share('dso_alert_product_no', 0);
        View::share('expire_alert_products', 0);
        View::share('categories_list', collect());
        View::share('languages', collect());
        View::share('role_has_permissions_list', collect());

        $this->context = $this->resolver->resolve(1);
    }

    private function makeDeveloperUser(): User
    {
        $dev = User::forceCreate([
            'id' => 99,
            'name' => 'Platform Dev',
            'email' => 'dev@zoloerp.local',
            'password' => bcrypt('developer123'),
            'role_id' => 1,
            'is_developer' => true,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        DB::table('company_user')->insert([
            ['company_id' => $this->company->id, 'user_id' => $dev->id, 'is_default' => true],
            ['company_id' => $this->other->id, 'user_id' => $dev->id, 'is_default' => false],
        ]);
        DB::table('company_user_branches')->insert([
            ['company_id' => $this->company->id, 'user_id' => $dev->id, 'branch_id' => $this->branch->id],
            ['company_id' => $this->other->id, 'user_id' => $dev->id, 'branch_id' => $this->otherBranch->id],
        ]);

        return $dev;
    }

    public function test_migration_is_idempotent_and_seeds_all_five_saas_plans(): void
    {
        (require database_path('migrations/2026_11_26_000001_create_saas_licensing_tables.php'))->up();
        $this->assertSame(5, SaasPlan::count());
        $enterprise = SaasPlan::where('code', 'enterprise')->firstOrFail();
        $this->assertCount(count(CapabilityCatalog::DEFINITIONS), $enterprise->modules_json);
    }

    public function test_non_developer_cannot_open_developer_portal_or_mutate_licenses(): void
    {
        $this->actingAs(User::findOrFail(1), 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);

        $this->get('/developer')->assertForbidden();
        $this->post('/developer/tenants/'.$this->company->id.'/plan', ['saas_plan' => 'enterprise'])->assertForbidden();
        $this->post('/developer/plans', ['name' => 'Hack Plan', 'billing_cycle' => 'monthly'])->assertForbidden();
    }

    public function test_developer_portal_renders_all_tabs_and_all_37_modules(): void
    {
        $dev = $this->makeDeveloperUser();
        $this->actingAs($dev, 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);

        $tenantsHtml = $this->get('/developer?tab=tenants&company='.$this->company->id)->assertOk();
        $tenantsHtml->assertSee('Developer Portal')->assertSee('Module Licensing');
        foreach (array_keys(CapabilityCatalog::DEFINITIONS) as $key) {
            $tenantsHtml->assertSee($key);
        }

        $this->get('/developer?tab=plans')->assertOk()->assertSee('Enterprise All-Modules');
        $this->get('/developer?tab=admins')->assertOk()->assertSee('Tenant Admin Logins');
        $this->get('/developer?tab=modules')->assertOk()->assertSee('Complete ERP Module Catalog');
        $this->get('/developer?tab=audits')->assertOk()->assertSee('Licensing &amp; Tenant Lifecycle Audit Log', false);
    }

    public function test_plan_assignment_and_module_licensing_enforce_api_and_lock_company_admin_bypass(): void
    {
        $dev = $this->makeDeveloperUser();
        $licenses = app(LicenseService::class);
        $capabilities = app(CapabilityService::class);

        // Assign Starter plan (does not include manufacturing.production or operations.water_logistics).
        $licenses->assignPlan($this->company->id, 'starter', [], $dev->id);

        $this->assertTrue($capabilities->enabled('core.sales', $this->company->id));
        $this->assertTrue($capabilities->enabled('sales.pos', $this->company->id));
        $this->assertFalse($capabilities->enabled('manufacturing.production', $this->company->id));
        $this->assertFalse($capabilities->enabled('operations.water_logistics', $this->company->id));

        // Company admin cannot self-enable an unlicensed module via API or legacy addon toggle.
        Sanctum::actingAs(User::findOrFail(1));
        $this->putJson('/api/v1/company-context/capabilities/manufacturing.production', ['enabled' => true])->assertForbidden();
        $this->getJson('/api/v1/company-context/license')->assertOk()->assertJsonPath('data.license.plan.code', 'starter');

        $this->actingAs(User::findOrFail(1), 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);
        $this->postJson('/addon/toggle', ['key' => 'water_logistics'])->assertForbidden();

        // Developer grants operations.water_logistics as add-on (automatically grants dependency sales.route_distribution).
        $this->actingAs($dev, 'web');
        $this->post('/developer/tenants/'.$this->company->id.'/modules', [
            'modules' => ['sales.pos', 'operations.water_logistics'],
            'expiries' => ['operations.water_logistics' => '2026-10-10'],
        ])->assertRedirect();

        Cache::flush();
        $this->assertTrue($capabilities->enabled('operations.water_logistics', $this->company->id));
        $this->assertTrue($capabilities->enabled('sales.route_distribution', $this->company->id));
        $this->assertFalse($capabilities->enabled('sales.gift_cards', $this->company->id));

        // Expired add-on override automatically stops working without affecting other licensed modules.
        $this->actingAs($dev, 'web');
        $this->post('/developer/tenants/'.$this->company->id.'/modules', [
            'modules' => ['sales.pos', 'operations.water_logistics'],
            'expiries' => ['operations.water_logistics' => '2026-10-01'],
        ])->assertRedirect();

        Cache::flush();
        $this->assertFalse($capabilities->enabled('operations.water_logistics', $this->company->id));
        $this->assertTrue($capabilities->enabled('sales.pos', $this->company->id));
    }

    public function test_suspended_and_expired_licenses_block_tenant_users_while_grace_warns(): void
    {
        $dev = $this->makeDeveloperUser();
        $licenses = app(LicenseService::class);

        // 1. Grace period: expired 2026-10-01, grace_ends_at 2026-10-05 (now is 2026-10-03).
        $licenses->assignPlan($this->company->id, 'starter', [
            'expires_at' => '2026-10-01',
            'grace_ends_at' => '2026-10-05',
        ], $dev->id);

        $graceSnap = $licenses->snapshot($this->company->id);
        $this->assertSame('grace', $graceSnap['status']);
        $this->assertFalse($graceSnap['is_blocked']);
        $this->assertNotNull($graceSnap['banner_warning']);

        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/company-context/license')->assertOk()->assertJsonPath('data.license.status', 'grace');

        // 2. Expired past grace: blocked (403) for company admin, developer still opens /developer!
        $licenses->assignPlan($this->company->id, 'starter', [
            'expires_at' => '2026-09-25',
            'grace_ends_at' => '2026-10-01',
        ], $dev->id);

        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/company-context/license')->assertForbidden()->assertJsonPath('license_status', 'expired');

        $this->actingAs($dev, 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);
        $this->get('/developer?company='.$this->company->id)->assertOk()->assertSee('EXPIRED');

        // 3. Developer renews +1 month -> tenant immediately unblocked.
        $this->post('/developer/tenants/'.$this->company->id.'/lifecycle', [
            'action' => 'renew',
            'months' => 1,
        ])->assertRedirect();

        Cache::flush();
        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/company-context/license')->assertOk()->assertJsonPath('data.license.status', 'active');

        // 4. Suspend blocks tenant user on both API and web (rendering blocked screen); developer stays allowed.
        $licenses->updateLifecycle($this->company->id, 'suspend', ['reason' => 'Non-payment'], $dev->id);
        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/company-context/license')->assertForbidden()->assertJsonPath('license_status', 'suspended');
        $this->actingAs(User::findOrFail(1), 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);
        $this->get('/workspace')->assertForbidden()->assertSee('access paused')->assertSee('Non-payment');
    }

    public function test_quota_limits_block_exceeding_users_branches_warehouses_and_monthly_invoices(): void
    {
        $dev = $this->makeDeveloperUser();
        $licenses = app(LicenseService::class);

        $licenses->assignPlan($this->company->id, 'starter', [
            'max_users' => 1,
            'max_branches' => 2,
            'max_warehouses' => 2,
            'max_monthly_invoices' => 1,
        ], $dev->id);

        // Branch limit (MAIN + NORTH already exist from seedCompanyReaderFixtures).
        try {
            app(CompanySetupService::class)->branch(1, $this->company->id, ['code' => 'BR2', 'name' => 'Second Branch']);
            $this->fail('Expected branch quota ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Branch limit reached', $e->getMessage());
        }

        // Warehouse limit (warehouses 1 & 3 already exist on company A).
        DB::table('warehouses')->whereIn('id', [1, 3])->update(['company_id' => $this->company->id, 'is_active' => true]);
        try {
            app(CompanySetupService::class)->location(1, $this->company->id, [
                'branch_id' => $this->branch->id,
                'name' => 'Second Warehouse',
                'address' => 'Test',
            ]);
            $this->fail('Expected warehouse quota ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Warehouse', $e->getMessage());
        }

        // User seat limit (User 1 is already active non-developer member; Dev 99 does NOT consume seat quota).
        $this->actingAs(User::findOrFail(1), 'web');
        request()->attributes->set(CompanyContext::class, $this->context);
        try {
            $licenses->assertCanAddUser($this->company->id);
            $this->fail('Expected user seat quota ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('User seat limit reached', $e->getMessage());
        }

        // Monthly invoice quota (1 sale in current month blocks 2nd).
        DB::table('sales')->insert([
            'reference_no' => 'INV-QUOTA-1',
            'user_id' => 1,
            'company_id' => $this->company->id,
            'warehouse_id' => 1,
            'customer_id' => 1,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 100,
            'grand_total' => 100,
            'sale_status' => 1,
            'payment_status' => 1,
            'created_at' => '2026-10-02 10:00:00',
        ]);
        try {
            app(SaleService::class)->createSale([
                'customer_id' => 1,
                'warehouse_id' => 1,
                'items' => [['product_id' => 1, 'qty' => 1, 'net_unit_price' => 10]],
            ], 1, $this->context);
            $this->fail('Expected monthly invoice quota ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Monthly sales invoice quota reached', $e->getMessage());
        }
    }

    public function test_developer_can_provision_new_tenant_company_with_license_and_admin(): void
    {
        $dev = $this->makeDeveloperUser();
        $this->actingAs($dev, 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);

        $response = $this->post('/developer/tenants', [
            'legal_name' => 'Nova Optics Lab Pvt Ltd',
            'trade_name' => 'Nova Optics',
            'code' => 'NOVAOPT',
            'industry' => 'opticals',
            'subtype' => 'lens_manufacturing',
            'saas_plan' => 'enterprise',
            'billing_cycle' => 'yearly',
            'timezone' => 'Asia/Kolkata',
            'fy_start' => '2026-04-01',
            'fy_end' => '2027-03-31',
            'admin_name' => 'Nova Admin',
            'admin_email' => 'nova@optics.test',
            'admin_password' => 'Passw0rd123',
        ]);
        $response->assertRedirect();

        $nova = Company::where('code', 'NOVAOPT')->firstOrFail();
        $snap = app(LicenseService::class)->snapshot($nova->id);
        $this->assertSame('active', $snap['status']);
        $this->assertSame('enterprise', $snap['plan']['code']);
        $this->assertTrue(app(CapabilityService::class)->enabled('service.warranty_amc', $nova->id));

        $admin = User::where('email', 'nova@optics.test')->firstOrFail();
        $this->assertTrue(DB::table('company_user')->where('company_id', $nova->id)->where('user_id', $admin->id)->exists());
        $this->assertTrue(DB::table('company_user')->where('company_id', $nova->id)->where('user_id', $dev->id)->exists());
        $this->assertTrue(CompanyLicenseAudit::where('company_id', $nova->id)->where('action', 'plan_assigned')->exists());
    }

    public function test_developer_can_create_custom_saas_plan(): void
    {
        $dev = $this->makeDeveloperUser();
        $this->actingAs($dev, 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);

        $this->post('/developer/plans', [
            'name' => 'Water Fleet Special',
            'code' => 'water_fleet',
            'billing_cycle' => 'monthly',
            'price' => 3499,
            'currency_code' => 'INR',
            'trial_days' => 7,
            'grace_days' => 5,
            'max_users' => 15,
            'max_branches' => 3,
            'max_warehouses' => 4,
            'max_monthly_invoices' => 3000,
            'modules' => ['operations.water_logistics'],
        ])->assertRedirect();

        $plan = SaasPlan::where('code', 'water_fleet')->firstOrFail();
        $this->assertContains('operations.water_logistics', $plan->modules_json);
        // Dependencies (sales.route_distribution -> core.sales, core.inventory) are auto-included.
        $this->assertContains('sales.route_distribution', $plan->modules_json);
        $this->assertContains('core.sales', $plan->modules_json);
    }

    public function test_ai_modules_and_credit_quotas_are_licensed_and_synced_cleanly(): void
    {
        $this->assertCount(45, CapabilityCatalog::DEFINITIONS);
        foreach (['ai.copilot', 'ai.actions', 'ai.multimodal', 'ai.documents', 'ai.workflows', 'ai.operations', 'ai.schedules', 'ai.mcp'] as $aiKey) {
            $this->assertArrayHasKey($aiKey, CapabilityCatalog::DEFINITIONS);
        }

        $licenses = app(LicenseService::class);
        $licenses->ensurePlansSeeded();

        $enterprise = SaasPlan::where('code', 'enterprise')->firstOrFail();
        $this->assertCount(45, $enterprise->modules_json);
        $this->assertSame(100000, (int) $enterprise->ai_monthly_credits);
        $this->assertFalse((bool) $enterprise->ai_read_only);

        $dev = $this->makeDeveloperUser();
        $this->actingAs($dev, 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);

        $html = $this->get('/developer?company=1&tab=tenants')->assertOk()->getContent();
        $this->assertStringContainsString('AI Operating Layer &amp; Copilot', $html);
        $this->assertStringContainsString('ai.copilot', $html);
        $this->assertStringContainsString('ai_monthly_credits', $html);
        $this->assertStringContainsString('Select All 45', $html);

        // Assign Growth plan with custom AI credit override and read+write allowed.
        // Growth includes read-only AI (copilot, documents, multimodal) and excludes ai.actions/workflows.
        $this->post('/developer/tenants/1/plan', [
            'saas_plan' => 'growth',
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'ai_monthly_credits' => 15000,
            'ai_monthly_media_pages' => 80,
            'ai_max_concurrent_runs' => 4,
            'ai_daily_token_limit' => 250000,
            'ai_read_only' => '0',
        ])->assertRedirect();

        $aiGrowth = $licenses->aiEntitlement(1);
        $this->assertTrue($aiGrowth['is_active']);
        $this->assertSame('growth', $aiGrowth['plan_sku']);
        $this->assertSame(15000, $aiGrowth['included_monthly_credits']);
        $this->assertSame(80, $aiGrowth['monthly_media_pages']);
        $this->assertSame(4, $aiGrowth['max_concurrent_runs']);
        $this->assertSame(250000, $aiGrowth['daily_token_limit']);
        // Even if ai_read_only=0 is set on license, read_only stays true because growth lacks ai.actions.
        $this->assertTrue($aiGrowth['read_only']);
        $this->assertTrue($aiGrowth['modules']['ai.copilot']);
        $this->assertTrue($aiGrowth['modules']['ai.documents']);
        $this->assertTrue($aiGrowth['modules']['ai.multimodal']);
        $this->assertFalse($aiGrowth['modules']['ai.actions']);
        $this->assertFalse($aiGrowth['modules']['ai.workflows']);

        // Industry Pro includes ai.actions and allows read+write AI; explicit 0 credit limit stays 0.
        $this->post('/developer/tenants/1/plan', [
            'saas_plan' => 'industry_pro',
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'ai_monthly_credits' => '0',
            'ai_read_only' => '',
        ])->assertRedirect();
        $aiPro = $licenses->aiEntitlement(1);
        $this->assertSame(0, $aiPro['included_monthly_credits']);
        $this->assertFalse($aiPro['read_only']);
        $this->assertTrue($aiPro['modules']['ai.actions']);
        $this->assertTrue($aiPro['modules']['ai.workflows']);
        $this->assertFalse($aiPro['modules']['ai.mcp']);

        // Custom SaaS plan auto-includes ai.copilot when ai.actions is checked.
        $this->post('/developer/plans', [
            'name' => 'AI Copilot Addon Plan',
            'code' => 'ai_addon_plan',
            'billing_cycle' => 'monthly',
            'price' => 2999,
            'ai_monthly_credits' => 8000,
            'ai_monthly_media_pages' => 40,
            'ai_max_concurrent_runs' => 3,
            'ai_daily_token_limit' => 120000,
            'ai_read_only' => false,
            'modules' => ['ai.actions'],
        ])->assertRedirect();

        $addonPlan = SaasPlan::where('code', 'ai_addon_plan')->firstOrFail();
        $this->assertContains('ai.actions', $addonPlan->modules_json);
        $this->assertContains('ai.copilot', $addonPlan->modules_json);
        $this->assertSame(8000, (int) $addonPlan->ai_monthly_credits);

        // Simulate merged AI tables and verify sync + live credit/media metering + suspend killswitch.
        Schema::create('ai_feature_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('read_only')->default(true);
            $table->boolean('multimodal_enabled')->default(false);
            $table->unsignedInteger('daily_token_limit')->default(100000);
            $table->unsignedInteger('daily_media_upload_limit')->default(25);
            $table->unsignedInteger('monthly_budget_minor')->default(0);
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_credit_ledger', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('kind');
            $table->integer('amount_credits');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('ai_media_assets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('ai_credit_ledger')->insert([
            'id' => 'led-1',
            'company_id' => 1,
            'kind' => 'usage_debit',
            'amount_credits' => -425,
            'created_at' => now(),
        ]);
        DB::table('ai_media_assets')->insert([
            'id' => 'med-1',
            'company_id' => 1,
            'page_count' => 12,
            'created_at' => now(),
        ]);

        $usage = $licenses->quotaUsage(1);
        $this->assertSame(425, $usage['ai_credits_used']);
        $this->assertSame(12, $usage['ai_media_pages_used']);

        // Assigning Industry Pro syncs ai_feature_settings with enabled=1, read_only=0, multimodal=1.
        $licenses->assignPlan(1, 'industry_pro', ['status' => 'active'], $dev->id);
        $fs = DB::table('ai_feature_settings')->where('company_id', 1)->first();
        $this->assertNotNull($fs);
        $this->assertSame(1, (int) $fs->enabled);
        $this->assertSame(0, (int) $fs->read_only);
        $this->assertSame(1, (int) $fs->multimodal_enabled);
        $this->assertSame(500000, (int) $fs->daily_token_limit);

        // Revoking ai.actions forces ai_feature_settings.read_only=1 even though ai.copilot stays on.
        $licenses->saveCompanyModules(1, ['ai.copilot'], [], $dev->id);
        $fsAfter = DB::table('ai_feature_settings')->where('company_id', 1)->first();
        $this->assertSame(1, (int) $fsAfter->enabled);
        $this->assertSame(1, (int) $fsAfter->read_only);
        $this->assertSame(0, (int) $fsAfter->multimodal_enabled);

        // API license endpoint includes AI entitlement & live AI usage before suspension.
        Sanctum::actingAs(User::findOrFail(1));
        $api = $this->getJson('/api/v1/company-context/license');
        $api->assertOk()
            ->assertJsonPath('data.ai.plan_sku', 'industry_pro')
            ->assertJsonPath('data.ai.is_blocked', false)
            ->assertJsonPath('data.usage.ai_credits_used', 425)
            ->assertJsonPath('data.usage.ai_media_pages_used', 12);
        $this->assertTrue($api->json('data.ai.modules')['ai.copilot']);

        // Suspending tenant flips ai_feature_settings.enabled=0 and blocks API; reactivating restores enabled=1.
        $this->actingAs($dev, 'web');
        $this->post('/developer/tenants/1/lifecycle', ['action' => 'suspend', 'reason' => 'AI billing overdue'])->assertRedirect();
        $this->assertSame(0, (int) DB::table('ai_feature_settings')->where('company_id', 1)->value('enabled'));

        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/company-context/license')->assertForbidden()->assertJsonPath('license_status', 'suspended');

        $this->actingAs($dev, 'web');
        $this->post('/developer/tenants/1/lifecycle', ['action' => 'activate'])->assertRedirect();
        $this->assertSame(1, (int) DB::table('ai_feature_settings')->where('company_id', 1)->value('enabled'));
    }
}
