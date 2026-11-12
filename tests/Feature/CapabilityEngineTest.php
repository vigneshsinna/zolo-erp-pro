<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\CapabilityCatalog;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\LegacyModuleAdapter;
use Database\Seeders\CapabilitySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CompanyContextTestCase;

class CapabilityEngineTest extends CompanyContextTestCase
{
    private CapabilityService $service;
    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Exercise the completed engine in isolated fixtures without opening production activation.
        $this->service = \Mockery::mock(CapabilityService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $this->service->shouldReceive('optionalActivationReady')->andReturn(true);
        $this->app->instance(CapabilityService::class, $this->service);
        $this->context = $this->resolver->resolve(1);
        Sanctum::actingAs(User::findOrFail(1));
    }

    public static function profileDefaults(): array
    {
        return [
            ['general_trading', []],
            ['fmcg', ['inventory.batch_expiry', 'inventory.multi_uom']],
            ['textile', ['sales.wholesale', 'printing.dot_matrix']],
            ['timber', ['inventory.dimension_tracking']],
            ['solar', ['operations.projects', 'inventory.serial_tracking', 'operations.installation']],
            ['opticals', ['inventory.multi_uom', 'inventory.batch_expiry', 'inventory.serial_tracking', 'sales.wholesale', 'service.warranty_amc', 'communications.whatsapp']],
        ];
    }

    #[DataProvider('profileDefaults')]
    public function test_profiles_enable_expected_defaults_and_preserve_other_company(string $profile, array $keys): void
    {
        $this->service->applyProfile($profile, $this->context, 1);
        foreach (array_merge(['core.sales', 'core.accounting'], $keys) as $key) {
            $this->assertTrue($this->service->enabled($key, $this->context));
        }
        foreach ($keys as $key) {
            $this->assertFalse($this->service->enabled($key, $this->other->id));
        }
        if ($profile === 'textile') {
            $this->assertSame(['lines' => 68, 'columns' => 80], $this->service->config('printing.dot_matrix', null, $this->context));
        }
        $this->assertCount(count(CapabilityCatalog::PROFILES), DB::table('business_profiles')->get());
        (new CapabilitySeeder)->run();
        $this->assertCount(count(CapabilityCatalog::PROFILES), DB::table('business_profiles')->get());
    }

    public function test_enable_and_disable_validate_dependencies_atomically(): void
    {
        try {
            $this->service->enable('operations.installation', [], $this->context, 1);
            $this->fail('Missing dependencies must reject enable.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('requires', $error->getMessage());
        }
        $this->assertSame(0, DB::table('company_capabilities')->count());
        $this->service->applyProfile('solar', $this->context, 1);
        try {
            $this->service->disable('inventory.serial_tracking', $this->context, 1);
            $this->fail('Enabled dependent must reject disable.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('requires', $error->getMessage());
        }
        $this->assertTrue($this->service->enabled('inventory.serial_tracking', $this->context));
        $this->service->disable('operations.installation', $this->context, 1);
        $this->service->disable('service.warranty_amc', $this->context, 1);
        $this->service->disable('inventory.serial_tracking', $this->context, 1);
        $this->assertFalse($this->service->enabled('inventory.serial_tracking', $this->context));
    }

    public function test_core_capability_cannot_be_disabled(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->disable('core.sales', $this->context, 1);
    }

    public function test_config_validation_and_company_cache_invalidation(): void
    {
        $this->assertFalse($this->service->enabled('inventory.batch_expiry', $this->context));
        $this->service->enable('inventory.batch_expiry', ['warning_days' => 30], $this->context, 1);
        $this->assertTrue($this->service->enabled('inventory.batch_expiry', $this->context));
        $this->assertSame(['warning_days' => 30], $this->service->config('inventory.batch_expiry', null, $this->context));
        $this->expectException(ValidationException::class);
        $this->service->enable('inventory.batch_expiry', ['warning_days' => -1], $this->context, 1);
    }

    public function test_unknown_configuration_and_unknown_capability_are_rejected(): void
    {
        $this->putJson('/api/v1/company-context/capabilities/inventory.batch_expiry', ['enabled' => true, 'config' => ['secret' => 'x']])->assertStatus(422);
        $this->putJson('/api/v1/company-context/capabilities/unknown', ['enabled' => true])->assertStatus(422);
    }

    public function test_legacy_modules_are_exact_tokens_with_dependencies_and_unknown_values_ignored(): void
    {
        $keys = app(LegacyModuleAdapter::class)->keys(' repair, manufacturing, repair,unknown,notrepair,woocommerce,OptechJobWork ');
        foreach (['service.repair', 'inventory.serial_tracking', 'manufacturing.production', 'sales.woocommerce', 'sales.ecommerce', 'operations.job_work'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertCount(count(array_unique($keys)), $keys);
        $this->assertNotContains('unknown', $keys);
    }

    public function test_legacy_settings_only_feed_default_company_and_explicit_disable_wins(): void
    {
        Schema::create('general_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('modules')->nullable();
        });
        DB::table('general_settings')->insert(['modules' => 'manufacturing,repair']);
        $this->company->update(['code' => 'DEFAULT']);
        $this->assertTrue($this->service->enabled('manufacturing.production', $this->context));
        $this->assertFalse($this->service->enabled('manufacturing.production', $this->other->id));
        $this->service->importLegacy($this->context, 1);
        $this->service->disable('manufacturing.production', $this->context, 1);
        $this->service->importLegacy($this->context, 1);
        $this->assertFalse($this->service->enabled('manufacturing.production', $this->context));
        $this->assertTrue($this->service->enabled('service.repair', $this->context));
    }

    public function test_rollback_keeps_cached_committed_configuration(): void
    {
        $this->assertFalse($this->service->enabled('manufacturing.production', $this->context));
        DB::beginTransaction();
        $this->service->enable('manufacturing.bom', [], $this->context, 1);
        $this->service->enable('manufacturing.production', [], $this->context, 1);
        $this->assertTrue($this->service->enabled('manufacturing.production', $this->context));
        DB::rollBack();
        $this->assertFalse($this->service->enabled('manufacturing.production', $this->context));
    }

    public function test_staff_can_read_but_cannot_change_capabilities_or_profiles(): void
    {
        DB::table('users')->where('id', 1)->update(['role_id' => 4]);
        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/company-context/capabilities')->assertOk();
        $this->putJson('/api/v1/company-context/capabilities/manufacturing.production', ['enabled' => true])->assertForbidden();
        $this->postJson('/api/v1/company-context/profiles/textile')->assertForbidden();
        $this->postJson('/api/v1/company-context/capabilities/import-legacy')->assertForbidden();
    }

    public function test_navigation_reuses_middleware_context_without_repeating_authorization_queries(): void
    {
        request()->attributes->set(CompanyContext::class, $this->context);
        $expected = $this->service->forNavigation($this->context);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertSame($expected, $this->service->forNavigation());
        $authorizationReads = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/\b(users|company_user|company_branches|fiscal_years)\b/', $query['query']));
        $this->assertCount(0, $authorizationReads);
    }

    public function test_company_role_override_controls_configuration_and_spoofing_is_rejected(): void
    {
        DB::table('company_user')->where('user_id', 1)->update(['role_id_override' => 4]);
        $this->postJson('/api/v1/company-context/profiles/fmcg')->assertForbidden();
        $this->withHeader('X-Company-ID', $this->other->id)->getJson('/api/v1/company-context/capabilities')->assertForbidden();
    }

    public function test_disabled_addon_direct_urls_and_api_are_rejected_before_controller_runs(): void
    {
        $this->getJson('/api/v1/repair/jobs')->assertForbidden();
        $this->postJson('/api/v1/water/trip-sheets', [])->assertForbidden();
        $this->actingAs(User::findOrFail(1), 'web');
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);
        $this->getJson('/repair/dashboard')->assertForbidden();
        $this->getJson('/manufacturing/productions')->assertForbidden();
        $this->assertContains('company.context', Route::getRoutes()->getByName('api.v1.repair.jobs')->middleware());
    }

    public function test_profile_api_and_addon_status_are_company_scoped(): void
    {
        $this->postJson('/api/v1/company-context/profiles/solar')->assertOk()->assertJsonFragment(['enabled' => true]);
        $this->getJson('/api/v1/addons/status')->assertOk()->assertJsonPath('data.features.project_management', true)->assertJsonPath('data.features.repair', false);
        $this->assertFalse($this->service->enabled('operations.installation', $this->other->id));
    }

    public function test_disabling_optional_feature_does_not_remove_history(): void
    {
        $this->seedCompanyTransactionFixtures();
        $this->service->applyProfile('textile', $this->context, 1);
        $this->service->disable('operations.job_work', $this->context, 1);
        $this->getJson('/api/v1/sales/1')->assertOk()->assertJsonPath('data.reference_no', 'DOC-1');
        $this->assertSame(3, DB::table('sales')->count());
    }

    public function test_production_activation_gate_blocks_profiles_enabling_navigation_and_direct_api(): void
    {
        $this->service->applyProfile('solar', $this->context, 1);
        $this->app->instance(CapabilityService::class, new CapabilityService);
        $this->putJson('/api/v1/company-context/capabilities/manufacturing.production', ['enabled' => true])->assertStatus(422);
        $this->postJson('/api/v1/company-context/profiles/textile')->assertStatus(422);
        $this->getJson('/api/v1/company-context/capabilities')->assertOk();
        $this->assertNotContains('operations.projects', app(CapabilityService::class)->forNavigation($this->context));
        $this->assertTrue((bool) DB::table('company_capabilities')->join('capabilities', 'capabilities.id', '=', 'capability_id')
            ->where('key', 'operations.projects')->value('enabled'));
        $this->getJson('/api/v1/repair/jobs')->assertForbidden();
    }

    public function test_legacy_import_preserves_configured_history_while_activation_is_blocked(): void
    {
        $this->company->update(['settings_json' => ['legacy_modules' => 'repair,manufacturing']]);
        $service = new CapabilityService;
        $service->importLegacy($this->context, 1);
        $this->assertFalse($service->enabled('manufacturing.production', $this->context));
        $this->assertTrue((bool) DB::table('company_capabilities')->join('capabilities', 'capabilities.id', '=', 'capability_id')
            ->where('key', 'manufacturing.production')->value('enabled'));
        $this->assertTrue((bool) DB::table('company_capabilities')->join('capabilities', 'capabilities.id', '=', 'capability_id')
            ->where('key', 'inventory.serial_tracking')->value('enabled'));
        $service->disable('manufacturing.production', $this->context, 1);
        $this->assertFalse((bool) DB::table('company_capabilities')->join('capabilities', 'capabilities.id', '=', 'capability_id')
            ->where('key', 'manufacturing.production')->value('enabled'));
        $this->assertTrue((bool) DB::table('company_capabilities')->join('capabilities', 'capabilities.id', '=', 'capability_id')
            ->where('key', 'service.repair')->value('enabled'));
    }

    public function test_sidebar_reads_effective_capabilities_and_retains_core_links(): void
    {
        request()->setUserResolver(fn () => User::findOrFail(1));
        $data = ['role' => (object) ['name' => 'Admin'], 'general_setting' => (object) ['modules' => 'manufacturing']];
        $disabled = view('backend.layout.sidebar', $data)->render();
        $this->assertStringNotContainsString('id="manufacturing-menu"', $disabled);
        $this->assertStringContainsString('id="sale-list-menu"', $disabled);
        $this->service->enable('manufacturing.bom', [], $this->context, 1);
        $this->service->enable('manufacturing.production', [], $this->context, 1);
        $enabled = view('backend.layout.sidebar', $data)->render();
        $this->assertStringContainsString('id="manufacturing-menu"', $enabled);
        $this->app->instance(CapabilityService::class, new CapabilityService);
        $gated = view('backend.layout.sidebar', $data)->render();
        $this->assertStringNotContainsString('id="manufacturing-menu"', $gated);
        foreach (['damage-stock-menu', 'sale-exchange-menu', 'installment-menu', 'whatsapp'] as $id) {
            $this->assertStringNotContainsString('id="'.$id.'"', $gated);
        }
        $this->assertStringContainsString('id="sale-list-menu"', $gated);
        if (getenv('ERP_CAPABILITY_BROWSER_FIXTURES') === '1') {
            $directory = base_path('scratch/phase23-browser');
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            foreach (['enabled' => $enabled, 'gated' => $gated] as $name => $html) {
                $style = 'body{font-family:Arial,sans-serif;margin:0}nav{width:280px;padding:16px;box-sizing:border-box}ul{list-style:none;padding-left:18px}a{display:block;padding:8px;color:#17375e}.sidebar-heading{padding:12px 0;font-weight:bold}';
                file_put_contents($directory.'/'.$name.'.html', '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Capability sidebar fixture</title><style>'.$style.'</style><nav aria-label="Main">'.$html.'</nav></html>');
            }
        }
    }
}
