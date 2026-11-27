<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Ai\Automation\OpticalDeterministicTriggers;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityExposurePolicy;
use App\Ai\Infrastructure\McpClientBindingService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\Biller;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Optical\OpticalCatalogService;
use App\Services\Optical\OpticalRxJobService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class OpticalMcpAndAutomationExpansionTest extends TestCase
{
    use UsesAiMysql;

    private Company $opticalCo;
    private Company $generalCo;
    private CompanyBranch $optBranch;
    private CompanyBranch $genBranch;
    private int $optFyId;
    private int $genFyId;
    private User $optAdmin;
    private User $genAdmin;
    private Customer $customer;
    private Warehouse $warehouse;
    private Product $lens;
    private Biller $biller;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'erp_ai.conversation_enabled' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'commercial.enabled' => true,
            'compliance.enabled' => false,
            'ai_capabilities.shared_discovery_enabled' => true,
            'ai_business_knowledge.enabled' => true,
            'ai_business_knowledge.opticals_enabled' => true,
            'ai_business_knowledge.opticals_lab_enabled' => true,
            'ai_business_knowledge.opticals_mcp_enabled' => false,
            'ai_infrastructure.mcp.enabled' => true,
            'ai_infrastructure.mcp.governed_proposals_enabled' => true,
            'ai_infrastructure.mcp.commercial_prepare_enabled' => false,
        ]);
        $this->app->forgetInstance(AiFeatureGate::class);
        $this->app->forgetInstance(CapabilityCatalog::class);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->opticalCo = Company::create([
            'legal_name' => 'R09 Optical Corp',
            'trade_name' => 'R09 Opticals',
            'code' => 'R09OPT_'.Str::random(5),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);
        DB::table('company_industry_settings')->insert([
            'company_id' => $this->opticalCo->id,
            'profile_key' => 'opticals',
            'subtype' => 'lens_manufacturing',
            'settings_json' => json_encode(['rx_lab' => true]),
            'updated_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->optBranch = $this->opticalCo->branches()->create(['code' => 'O91', 'name' => 'Optical Main', 'branch_type' => 'branch', 'is_active' => true]);
        $this->optFyId = $this->opticalCo->fiscalYears()->create([
            'name' => 'FY 2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;

        $this->generalCo = Company::create([
            'legal_name' => 'R09 General Trading',
            'trade_name' => 'R09 General',
            'code' => 'R09GEN_'.Str::random(5),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);
        $this->genBranch = $this->generalCo->branches()->create(['code' => 'G91', 'name' => 'General Main', 'branch_type' => 'branch', 'is_active' => true]);
        $this->genFyId = $this->generalCo->fiscalYears()->create([
            'name' => 'FY 2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;

        $this->optAdmin = User::create([
            'name' => 'Opt MCP Admin', 'email' => 'optmcp_'.Str::random(6).'@example.test',
            'phone' => '98555'.rand(10000, 99999), 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->genAdmin = User::create([
            'name' => 'Gen MCP Admin', 'email' => 'genmcp_'.Str::random(6).'@example.test',
            'phone' => '98556'.rand(10000, 99999), 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);

        $this->opticalCo->users()->attach($this->optAdmin->id, ['is_default' => true]);
        $this->generalCo->users()->attach($this->genAdmin->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            ['company_id' => $this->opticalCo->id, 'user_id' => $this->optAdmin->id, 'branch_id' => $this->optBranch->id],
            ['company_id' => $this->generalCo->id, 'user_id' => $this->genAdmin->id, 'branch_id' => $this->genBranch->id],
        ]);

        app(AiFeatureGate::class)->setCompanySetting($this->opticalCo->id, true, false, ['mcp_proposals_enabled' => true]);
        app(AiFeatureGate::class)->setCompanySetting($this->generalCo->id, true, false, ['mcp_proposals_enabled' => true]);

        $this->warehouse = Warehouse::forceCreate([
            'company_id' => $this->opticalCo->id, 'branch_id' => $this->optBranch->id,
            'name' => 'Opt Depot', 'address' => 'Chennai', 'is_active' => true,
        ]);
        $this->customer = Customer::forceCreate([
            'company_id' => $this->opticalCo->id, 'customer_group_id' => 1,
            'name' => 'ClearSight Clinic', 'phone_number' => '9855500001', 'is_active' => true,
        ]);
        $this->biller = Biller::forceCreate([
            'company_id' => $this->opticalCo->id, 'name' => 'Opt Biller', 'company_name' => 'R09 Opticals',
            'phone_number' => '9855500002', 'email' => 'ob9@example.test', 'address' => 'Rd 9', 'city' => 'Chennai', 'country' => 'India', 'is_active' => true,
        ]);
        $this->unit = Unit::forceCreate([
            'company_id' => $this->opticalCo->id, 'unit_name' => 'Piece', 'unit_code' => 'PC9_'.Str::random(3), 'is_active' => true,
        ]);
        $catId = Category::firstOrCreate(['company_id' => $this->opticalCo->id, 'name' => 'R09 Lens'], ['is_active' => true])->id;
        $this->lens = Product::forceCreate([
            'company_id' => $this->opticalCo->id, 'code' => 'O9-'.Str::random(5), 'name' => 'CR39 AR -2.00',
            'type' => 'standard', 'barcode_symbology' => 'C128', 'category_id' => $catId,
            'unit_id' => $this->unit->id, 'sale_unit_id' => $this->unit->id, 'purchase_unit_id' => $this->unit->id,
            'cost' => 100, 'price' => 240, 'qty' => 5, 'is_active' => true,
        ]);

        app(OpticalCatalogService::class)->registerIdentity(
            new CompanyContext($this->opticalCo->id, $this->optBranch->id, $this->optFyId),
            $this->lens,
            ['lens_family' => 'CR39 AR', 'sph' => '-2.00', 'cyl' => '-0.25', 'axis' => 90, 'lr' => 'RE'],
        );
    }

    public function test_default_off_flags_block_selective_optical_and_commercial_mcp_exposure(): void
    {
        $service = app(McpClientBindingService::class);

        try {
            $service->issue($this->optAdmin, $this->opticalCo->id, $this->optBranch->id, $this->optFyId, 'c1', ['read'], ['optical.power_matrix.v1']);
            $this->fail('Optical MCP tool binding must fail when server allowlist & opt-in flag are off.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        config(['ai_infrastructure.mcp.tools' => ['optical.power_matrix.v1']]);
        try {
            $service->issue($this->optAdmin, $this->opticalCo->id, $this->optBranch->id, $this->optFyId, 'c2', ['read'], ['optical.power_matrix.v1']);
            $this->fail('Optical MCP tool binding must still fail while opticals_mcp_enabled is false.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        config(['ai_infrastructure.mcp.prepare_tools' => ['sales_invoice.create.v1']]);
        try {
            $service->issue($this->optAdmin, $this->opticalCo->id, $this->optBranch->id, $this->optFyId, 'c3', ['prepare'], [], ['sales_invoice.create.v1']);
            $this->fail('Sales invoice MCP binding must fail while commercial_prepare_enabled is false.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_selective_optical_and_sales_prepare_mcp_and_deterministic_triggers_enforced(): void
    {
        config([
            'ai_business_knowledge.opticals_mcp_enabled' => true,
            'ai_infrastructure.mcp.commercial_prepare_enabled' => true,
            'ai_infrastructure.mcp.tools' => ['optical.lens_candidates.v1', 'optical.power_matrix.v1'],
            'ai_infrastructure.mcp.prepare_tools' => ['sales_invoice.create.v1'],
        ]);

        $service = app(McpClientBindingService::class);
        $grant = $service->issue(
            $this->optAdmin,
            $this->opticalCo->id,
            $this->optBranch->id,
            $this->optFyId,
            'optical-mcp-erp',
            ['read', 'prepare', 'status'],
            ['optical.lens_candidates.v1', 'optical.power_matrix.v1'],
            ['sales_invoice.create.v1'],
        );

        $list = $this->withToken($grant['plain_text_token'])->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ])->assertOk();
        $toolNames = collect($list->json('result.tools'))->pluck('name')->all();
        $this->assertContains('optical_power_matrix', $toolNames);
        $this->assertContains('optical_lens_candidates', $toolNames);
        $this->assertContains('erp_sales_invoice_prepare', $toolNames);
        $this->assertNotContains('erp_purchase_bill_prepare', $toolNames);

        // Call prepare via MCP -> creates draft proposal ONLY, zero Sales rows posted, zero model invocations.
        $prepResp = $this->withToken($grant['plain_text_token'])->postJson('/mcp/erp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'erp_sales_invoice_prepare',
                'arguments' => [
                    'client_request_id' => 'mcp-si-001',
                    'inputs' => [
                        'customer_id' => $this->customer->id,
                        'warehouse_id' => $this->warehouse->id,
                        'biller_id' => $this->biller->id,
                        'date' => now()->toDateString(),
                        'items' => [[
                            'product_id' => $this->lens->id,
                            'qty' => '1',
                            'net_unit_price' => '240',
                            'sph' => '-2.00',
                            'cyl' => '-0.25',
                            'axis' => 90,
                            'lr' => 'RE',
                        ]],
                    ],
                ],
            ],
        ])->assertOk();
        $this->assertFalse((bool) ($prepResp->json('result.isError') ?? false), json_encode($prepResp->json()));
        $this->assertSame(0, Sale::where('company_id', $this->opticalCo->id)->count());
        if (Schema::hasTable('ai_model_invocations')) {
            $this->assertSame(0, DB::table('ai_model_invocations')->where('company_id', $this->opticalCo->id)->count());
        }

        // General non-optical company cannot discover optical read tools even when global opt-in flags are on.
        auth()->forgetGuards();
        $this->actingAs($this->genAdmin);
        $genPrincipal = app(AiPrincipalFactory::class)->createForUser($this->genAdmin->id, $this->generalCo->id, $this->genBranch->id, $this->genFyId);
        $matrixDesc = app(CapabilityCatalog::class)->find('optical.power_matrix.v1', 'read');
        $genGrant = new \App\Ai\Infrastructure\McpClientGrant('b', 'c', 1, $genPrincipal, ['read'], ['optical.power_matrix.v1'], [], []);
        $this->assertFalse(app(CapabilityExposurePolicy::class)->authorize($genPrincipal, 'mcp', $matrixDesc, $genGrant));

        // Revoking token binding deletes the Sanctum token -> immediately returns 401 Unauthorized.
        $service->revoke($grant['binding_id'], $this->opticalCo->id, 'operator revoke');
        auth()->forgetGuards();
        $this->withToken($grant['plain_text_token'])->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list',
        ])->assertUnauthorized();

        // Deterministic automation triggers run with zero model calls & surface overdue Rx job without autonomous writes.
        $ctx = new CompanyContext($this->opticalCo->id, $this->optBranch->id, $this->optFyId);
        app(OpticalRxJobService::class)->createJob($ctx, $this->optAdmin->id, [
            'customer_id' => $this->customer->id,
            'product_id' => $this->lens->id,
            'source_warehouse_id' => $this->warehouse->id,
            'qty_requested' => 1,
            'promised_date' => now()->subDays(2)->toDateString(),
            'optical_spec' => ['sph' => '-2.00', 'cyl' => '-0.25', 'axis' => 90, 'lr' => 'RE'],
        ], 'overdue-trig-1');

        $trig = app(OpticalDeterministicTriggers::class)->evaluate($ctx, $this->optAdmin->id);
        $this->assertTrue($trig['enabled']);
        $this->assertSame(0, $trig['model_calls']);
        $this->assertNotEmpty($trig['triggers']['overdue_rx_lab_jobs']);
    }
}
