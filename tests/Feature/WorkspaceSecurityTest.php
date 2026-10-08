<?php

namespace Tests\Feature;

use App\Services\Platform\CapabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OperationsTestCase;

class WorkspaceSecurityTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('currencies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('code'); });
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Rupee', 'code' => 'INR']);
        Schema::table('warehouses', fn (Blueprint $t) => $t->string('phone')->nullable());
        (require database_path('migrations/2026_10_09_000001_create_company_setup_audits.php'))->up();
    }

    public function test_setup_works_before_a_year_exists_and_preserves_settings_and_stock(): void
    {
        $this->withoutExceptionHandling();
        $this->company->update(['settings_json' => ['existing' => 'preserved']]);
        $product = $this->material();
        $this->year->delete();
        $this->get('/workspace/setup')->assertOk()->assertSee('Company setup');
        $this->putJson('/api/v1/company-context/setup/company', ['legal_name' => 'New company name', 'state_code' => '33',
            'base_currency_id' => 1, 'print_format' => 'a4', 'company_id' => $this->other->id])->assertOk();
        $this->assertSame('preserved', $this->company->fresh()->settings_json['existing']);
        $this->assertSame('Company B', $this->other->fresh()->legal_name);
        $this->assertEquals(100, $product->fresh()->qty);
        $this->assertSame(1, DB::table('company_setup_audits')->count());
    }

    public function test_setup_rejects_foreign_company_and_operator_role(): void
    {
        $this->getJson('/api/v1/company-context/setup', ['X-Company-ID' => $this->other->id])->assertForbidden();
        $this->flushHeaders();
        DB::table('company_user')->where('user_id', 1)->update(['role_id_override' => 4]);
        $this->getJson('/api/v1/company-context/setup')->assertForbidden();
    }

    public function test_warehouse_setup_is_owned_and_retries_do_not_duplicate(): void
    {
        $data = ['branch_id' => $this->branch->id, 'name' => 'New warehouse', 'address' => 'Site'];
        $first = $this->putJson('/api/v1/company-context/setup/warehouse', $data)->assertOk()->json('data.id');
        $this->putJson('/api/v1/company-context/setup/warehouse', $data)->assertJsonPath('data.id', $first);
        $this->putJson('/api/v1/company-context/setup/warehouse', array_replace($data, ['address' => 'Changed']))->assertUnprocessable();
        $this->putJson('/api/v1/company-context/setup/warehouse', array_replace($data, ['branch_id' => $this->otherBranch->id]))->assertNotFound();
        $this->assertSame(1, DB::table('company_setup_audits')->count());
    }

    public function test_navigation_and_direct_api_access_use_capability_permission_and_membership(): void
    {
        $this->getJson('/api/v1/companies')->assertOk()->assertJsonCount(1, 'data')->assertDontSee('Company B');
        config(['operations.enabled' => false]);
        $this->get('/workspace')->assertOk()->assertDontSee('Open workspace</span></a><a class="workspace-card" href="/operations', false);
        DB::table('company_user')->where('user_id', 1)->update(['role_id_override' => 4]);
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->getJson('/api/v1/accounting/chart-of-accounts')->assertForbidden();
        $this->getJson('/api/v1/workspace')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_context_switch_revalidates_whole_tuple_before_session_changes(): void
    {
        $this->post('/workspace/context', ['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'financial_year_id' => $this->year->id])->assertRedirect('/workspace')->assertSessionHas('company_id', $this->company->id);
        $this->postJson('/workspace/context', ['company_id' => $this->company->id, 'branch_id' => $this->otherBranch->id,
            'financial_year_id' => $this->year->id])->assertForbidden();
    }

    public function test_correlation_and_safe_database_failure_do_not_expose_secrets(): void
    {
        Route::middleware('web')->get('/__test/private-db-failure', fn () => throw new \PDOException('password=secret fixture'));
        $this->getJson('/__test/private-db-failure', ['X-Request-ID' => 'review_request_123'])->assertStatus(503)
            ->assertHeader('X-Request-ID', 'review_request_123')->assertDontSee('secret')->assertDontSee('install/step-1');
        $this->get('/workspace')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/backup')->assertStatus(410);
    }

    public function test_browser_updater_is_retired_without_environment_migrations_or_downloads(): void
    {
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')->never();
        $this->get('/new-release')->assertStatus(410);
        $this->post('/version-upgrade', ['purchasecode' => 'untrusted-input'])->assertStatus(410);
        $controller = app(\App\Http\Controllers\HomeController::class);
        $this->assertSame([], $controller->isUpdateAvailable());
        $this->assertNull($controller->versionUpgradeFileUrl('untrusted-input'));
        try { $controller->fileTransferProcess('https://example.invalid/untrusted.zip'); $this->fail('Remote code extraction must fail.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(410, $error->getStatusCode()); }
    }

    public function test_product_resource_is_bounded_and_does_not_expose_new_columns(): void
    {
        $product = $this->material();
        Schema::table('products', fn (Blueprint $t) => $t->string('internal_secret')->nullable());
        DB::table('products')->where('id', $product->id)->update(['internal_secret' => 'private fixture']);
        $this->getJson('/api/v1/products?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/products')->assertOk()->assertDontSee('internal_secret')->assertDontSee('private fixture');
    }

    public function test_branch_and_capability_setup_have_reviewable_audit_history(): void
    {
        $data = ['code' => 'SHOP', 'name' => 'Second shop'];
        $id = $this->putJson('/api/v1/company-context/setup/branch', $data)->assertOk()->json('data.id');
        $this->putJson('/api/v1/company-context/setup/branch', $data)->assertJsonPath('data.id', $id);
        $this->assertTrue(DB::table('company_user_branches')->where('user_id', 1)->where('branch_id', $id)->exists());
        $this->assertSame(1, DB::table('company_setup_audits')->where('action', 'branch_setup')->count());
        app(CapabilityService::class)->enable('sales.wholesale', [], $this->context(), 1);
        app(CapabilityService::class)->disable('sales.wholesale', $this->context(), 1);
        $this->assertSame(2, DB::table('company_setup_audits')->where('action', 'capabilities_changed')->count());
    }

    public function test_workspace_pages_and_optional_browser_fixture(): void
    {
        app(\App\Services\Industry\IndustryProfileService::class)->apply('textile', 'wholesale', $this->context(), 1);
        $this->get('/workspace')->assertOk()->assertSee('Active branch and financial year')->assertSee('Sales');
        $this->get('/workspace/setup')->assertOk()->assertSee('Add branch');
        if (getenv('ERP_EXPORT_WORKSPACE_BROWSER') === '1') {
            $this->assertSame('sqlite', DB::connection()->getDriverName());
            $path = base_path('scratch/workspace-browser-'.bin2hex(random_bytes(6)).'.sqlite');
            DB::statement('VACUUM INTO '.DB::connection()->getPdo()->quote($path));
            file_put_contents(base_path('scratch/operations-browser-path.txt'), $path);
        }
    }
}
