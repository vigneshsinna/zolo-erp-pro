<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\CapabilityCatalog;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CompanyContextTestCase;

/** HRM, POS, gift cards, coupons, scheduling and the lot report: existing features that companies can now switch off. */
class SwitchableCapabilityTest extends CompanyContextTestCase
{
    private CapabilityService $service;
    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // The real service: production keeps the optional-activation gate closed.
        $this->service = new CapabilityService;
        $this->app->instance(CapabilityService::class, $this->service);
        $this->context = $this->resolver->resolve(1);
        $this->actingAs(User::findOrFail(1), 'web');
        request()->setUserResolver(fn () => User::findOrFail(1));
    }

    private function sidebar(): string
    {
        Cache::flush();
        return view('backend.layout.sidebar', ['role' => (object) ['id' => 1]])->render();
    }

    public function test_existing_features_stay_on_without_a_company_choice_even_with_the_activation_gate_closed(): void
    {
        $enabled = $this->service->forNavigation($this->context);
        foreach (CapabilityCatalog::SWITCHABLE as $key) {
            $this->assertContains($key, $enabled);
        }
        $html = $this->sidebar();
        foreach (['id="hrm"', 'id="gift-card-menu"', 'id="coupon-menu"', 'id="stock-by-lot-menu"', 'Booking Calendar', 'POS Terminal'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_disabled_feature_leaves_navigation_and_its_routes_refuse_access(): void
    {
        foreach (['hrm.enabled', 'sales.coupons', 'operations.scheduling', 'inventory.lot_report', 'sales.pos'] as $key) {
            $this->service->disable($key, $this->context, 1);
        }
        $html = $this->sidebar();
        foreach (['id="hrm"', 'id="coupon-menu"', 'id="stock-by-lot-menu"', 'Booking Calendar', 'POS Terminal', 'HRM Settings'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
        $this->assertStringContainsString('id="gift-card-menu"', $html);
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);
        foreach (['/departments', '/coupons', '/bookings/calendar', '/inventory-reports/stock-by-lot', '/pos'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_admin_can_turn_a_switched_off_feature_back_on_while_activation_gate_is_closed(): void
    {
        $this->service->disable('sales.gift_cards', $this->context, 1);
        $this->assertFalse($this->service->enabled('sales.gift_cards', $this->context));
        $this->service->enable('sales.gift_cards', [], $this->context, 1);
        Cache::flush();
        $this->assertTrue($this->service->enabled('sales.gift_cards', $this->context));
    }

    public function test_business_profile_decides_for_fresh_companies(): void
    {
        $this->service->applyProfile('general_trading', $this->context, 1);
        Cache::flush();
        foreach (CapabilityCatalog::SWITCHABLE as $key) {
            $this->assertFalse($this->service->enabled($key, $this->context), $key);
        }
        // FMCG also turns on new optional modules, which needs the activation gate open.
        $open = \Mockery::mock(CapabilityService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $open->shouldReceive('optionalActivationReady')->andReturn(true);
        $open->applyProfile('fmcg', $this->context, 1);
        Cache::flush();
        $this->assertTrue($open->enabled('sales.pos', $this->context));
        $this->assertTrue($open->enabled('inventory.lot_report', $this->context));
        $this->assertFalse($open->enabled('hrm.enabled', $this->context));
    }
}
