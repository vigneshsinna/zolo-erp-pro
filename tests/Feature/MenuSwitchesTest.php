<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\MenuSwitches;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompanyContextTestCase;

/** The developer switches sections and screens off per company; the menu hides them and their URLs are refused. */
class MenuSwitchesTest extends CompanyContextTestCase
{
    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        (require base_path('database/migrations/2026_10_27_000001_add_developer_login_and_company_menu_switches.php'))->up();
        $this->context = $this->resolver->resolve(1);
        $this->actingAs(User::findOrFail(1), 'web');
        request()->setUserResolver(fn () => User::findOrFail(1));
        request()->attributes->set(CompanyContext::class, $this->context);
    }

    private function sidebar(): string
    {
        Cache::flush();
        return view('backend.layout.sidebar', ['role' => (object) ['id' => 1], 'role_has_permissions_list' => collect()])->render();
    }

    private function switchOff(array $keys): void
    {
        $switches = app(MenuSwitches::class);
        $switches->save($this->context->companyId, $keys, $switches->catalog($this->sidebar()), 1);
    }

    public function test_catalog_lists_sections_with_their_screens(): void
    {
        $catalog = collect(app(MenuSwitches::class)->catalog($this->sidebar()))->keyBy('key');
        $this->assertArrayHasKey('section:hrm', $catalog->all());
        $this->assertContains('dept-menu', array_column($catalog['section:hrm']['items'], 'key'));
        $this->assertContains('sale-agent-menu', array_column($catalog['section:people']['items'], 'key'));
    }

    public function test_switched_off_section_and_screen_leave_the_menu_and_their_urls_are_refused(): void
    {
        $this->switchOff(['section:hrm', 'sale-agent-menu']);
        $html = app(MenuSwitches::class)->filterSidebar($this->sidebar());
        $this->assertStringNotContainsString('href="#hrm"', $html);
        $this->assertStringNotContainsString('id="sale-agent-menu"', $html);
        $this->assertStringContainsString('id="user-list-menu"', $html);

        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);
        foreach (['/departments', '/employees', '/payroll', '/sale-agents'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonFragment(['message' => (str_starts_with($url, '/sale') ? 'People › Sale Agents' : 'HRM')
                .' is switched off for this company. Ask your developer to switch it on.']);
        }
        // Own profile and the developer console are never switched off.
        $this->assertTrue(app(MenuSwitches::class)->allowsPath('/user/profile/1'));
        $this->assertTrue(app(MenuSwitches::class)->allowsPath('/biller'));
    }

    public function test_developer_sees_everything_and_only_a_developer_opens_the_console(): void
    {
        $this->switchOff(['section:hrm']);
        $this->withoutMiddleware([\App\Http\Middleware\Common::class, \App\Http\Middleware\Active::class]);
        $this->getJson('/developer')->assertForbidden();

        DB::table('users')->where('id', 1)->update(['is_developer' => true]);
        $this->actingAs(User::findOrFail(1), 'web');
        $this->assertTrue(app(MenuSwitches::class)->allowsPath('/departments'));
        $this->assertStringContainsString('href="#hrm"', app(MenuSwitches::class)->filterSidebar($this->sidebar()));
    }

    public function test_turning_everything_back_on_clears_the_switches(): void
    {
        $this->switchOff(['section:hrm']);
        $this->switchOff([]);
        $this->assertSame([], app(MenuSwitches::class)->off($this->context->companyId));
    }
}
