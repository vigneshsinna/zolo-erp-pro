<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Capabilities;

use App\Ai\Agents\ErpCopilot;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityDispatcher;
use App\Ai\Capabilities\CapabilityExposurePolicy;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\ApprovedActionExecutor;
use App\Ai\Tools\PreparePurchaseOrder;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Covers U1 integration acceptance cases D06, D07, D08, D09, D10, D11, D12, D14, D16.
 */
final class SharedCapabilityDiscoveryTest extends TestCase
{
    use UsesAiMysql;

    private Company $companyA;
    private Company $companyB;
    private int $branchA1;
    private int $branchA2;
    private int $fyA1;
    private int $fyB1;
    private User $adminA;
    private User $viewerA;
    private User $adminB;
    private Supplier $supplierA;
    private Warehouse $warehouseA;
    private Product $productA;
    private Unit $unitA;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'erp_ai.conversation_enabled' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'ai_infrastructure.scheduling.enabled' => true,
            'ai_capabilities.shared_discovery_enabled' => true,
        ]);

        $this->companyA = Company::create([
            'legal_name' => 'U1 Alpha Textiles', 'trade_name' => 'Alpha',
            'code' => 'U1A-' . Str::random(6), 'status' => 'active', 'country_code' => 'IN',
        ]);
        $this->branchA1 = $this->companyA->branches()->create(['name' => 'Branch A1', 'code' => 'A1', 'is_active' => true])->id;
        $this->branchA2 = $this->companyA->branches()->create(['name' => 'Branch A2', 'code' => 'A2', 'is_active' => true])->id;
        $this->fyA1 = $this->companyA->fiscalYears()->create([
            'name' => 'FY 2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;

        $this->companyB = Company::create([
            'legal_name' => 'U1 Beta Textiles', 'trade_name' => 'Beta',
            'code' => 'U1B-' . Str::random(6), 'status' => 'active', 'country_code' => 'IN',
        ]);
        $branchB = $this->companyB->branches()->create(['name' => 'Branch B1', 'code' => 'B1', 'is_active' => true])->id;
        $this->fyB1 = $this->companyB->fiscalYears()->create([
            'name' => 'FY 2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;

        DB::table('roles')->updateOrInsert(['id' => 4], ['name' => 'Viewer', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminA = User::create([
            'name' => 'Admin A', 'email' => Str::uuid() . '@example.test',
            'phone' => '9000000001', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->viewerA = User::create([
            'name' => 'Viewer A', 'email' => Str::uuid() . '@example.test',
            'phone' => '9000000002', 'password' => bcrypt('secret'), 'role_id' => 4, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->adminB = User::create([
            'name' => 'Admin B', 'email' => Str::uuid() . '@example.test',
            'phone' => '9000000003', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);

        $this->companyA->users()->attach($this->adminA->id, ['is_default' => true]);
        $this->companyA->users()->attach($this->viewerA->id, ['is_default' => true, 'role_id_override' => 4]);
        $this->companyB->users()->attach($this->adminB->id, ['is_default' => true]);

        DB::table('company_user_branches')->insert([
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminA->id, 'branch_id' => $this->branchA1],
            ['company_id' => $this->companyA->id, 'user_id' => $this->viewerA->id, 'branch_id' => $this->branchA1],
            ['company_id' => $this->companyB->id, 'user_id' => $this->adminB->id, 'branch_id' => $branchB],
        ]);

        app(AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false);
        app(AiFeatureGate::class)->setCompanySetting($this->companyB->id, false, true);

        $this->unitA = Unit::forceCreate([
            'unit_code' => 'MTR', 'unit_name' => 'Metre', 'is_active' => true, 'company_id' => $this->companyA->id,
        ]);
        $this->supplierA = Supplier::forceCreate([
            'company_id' => $this->companyA->id, 'name' => 'Alpha Yarn Traders', 'company_name' => 'Alpha Yarn Traders',
            'phone_number' => '9876543210', 'address' => 'Erode', 'city' => 'Erode', 'country' => 'India', 'is_active' => true,
        ]);
        $this->warehouseA = Warehouse::forceCreate([
            'company_id' => $this->companyA->id, 'branch_id' => $this->branchA1,
            'name' => 'Erode Main Warehouse', 'address' => 'Erode', 'is_active' => true,
        ]);
        $category = Category::firstOrCreate(['name' => 'Textiles'], ['is_active' => true]);
        $this->productA = Product::forceCreate([
            'company_id' => $this->companyA->id, 'name' => 'Linen Fabric Blue', 'code' => 'SKU-LINEN-U1',
            'type' => 'standard', 'barcode_symbology' => 'C128', 'category_id' => $category->id,
            'cost' => 120, 'price' => 180,
            'unit_id' => $this->unitA->id, 'purchase_unit_id' => $this->unitA->id, 'sale_unit_id' => $this->unitA->id,
            'is_active' => true, 'qty' => 0,
        ]);
    }

    public function test_d06_d07_d16_rest_discovery_and_revocation_isolate_companies_and_roles(): void
    {
        // Admin A sees eligible capabilities with safe public metadata (no PHP class names)
        $res = $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA1, 'financial_year_id' => $this->fyA1])
            ->getJson(route('api.v1.ai.capabilities.index'))
            ->assertOk();

        $res->assertJsonStructure(['catalog_version', 'capabilities', 'pagination']);
        $res->assertDontSee('PurchaseOrderPreviewAdapter');
        $res->assertDontSee('ActionPreparationService');

        $keys = collect($res->json('capabilities'))->pluck('capability_key')->all();
        $this->assertContains('purchase_order.create.v1', $keys);

        // Company B has AI disabled in ai_feature_settings -> forbidden
        $this->actingAs($this->adminB)
            ->withSession(['company_id' => $this->companyB->id])
            ->getJson(route('api.v1.ai.capabilities.index'))
            ->assertForbidden();

        // Flag off -> 404
        config(['ai_capabilities.shared_discovery_enabled' => false]);
        $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA1, 'financial_year_id' => $this->fyA1])
            ->getJson(route('api.v1.ai.capabilities.index'))
            ->assertNotFound();

        config(['ai_capabilities.shared_discovery_enabled' => true]);

        // D07: Discover, then revoke user membership -> invocation fails immediately
        $principal = app(AiPrincipalFactory::class)->createForUser($this->adminA->id, $this->companyA->id, $this->branchA1, $this->fyA1);
        $descriptor = app(CapabilityCatalog::class)->find('purchase_order.create.v1', 'prepare');
        $this->assertTrue(app(CapabilityExposurePolicy::class)->authorize($principal, 'sdk', $descriptor));

        DB::table('company_user_branches')
            ->where('company_id', $this->companyA->id)
            ->where('user_id', $this->adminA->id)
            ->delete();

        $this->expectException(AuthorizationException::class);
        app(CapabilityDispatcher::class)->dispatch($principal, 'sdk', 'purchase_order.create.v1', 'prepare', [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'items' => [['product_id' => $this->productA->id, 'qty' => '10', 'net_unit_cost' => '120']],
        ]);
    }

    public function test_d08_d09_d10_d14_shared_dispatch_matches_legacy_po_path_and_consumes_zero_credits(): void
    {
        $principal = app(AiPrincipalFactory::class)->createForUser($this->adminA->id, $this->companyA->id, $this->branchA1, $this->fyA1);
        $args = [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'date' => '2026-11-25',
            'payment_terms_type' => 'credit',
            'credit_days' => 30,
            'items' => [
                ['product_id' => $this->productA->id, 'qty' => '100', 'net_unit_cost' => '120.00'],
            ],
        ];

        // 1. Legacy PreparePurchaseOrder with shared discovery OFF
        config(['ai_capabilities.shared_discovery_enabled' => false]);
        $legacyRaw = (new PreparePurchaseOrder($principal))->handle(new ToolRequest($args));
        $legacyData = json_decode($legacyRaw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $legacyData['status']);
        $legacyProposal = DB::table('ai_action_proposals')->where('id', $legacyData['data']['proposal_id'])->first();

        // 2. Shared CapabilityDispatcher with shared discovery ON
        config(['ai_capabilities.shared_discovery_enabled' => true]);
        $sharedData = app(CapabilityDispatcher::class)->dispatch(
            $principal,
            'sdk',
            'purchase_order.create.v1',
            'prepare',
            $args,
        );
        $this->assertSame('ok', $sharedData['status']);
        $sharedProposal = DB::table('ai_action_proposals')->where('id', $sharedData['data']['proposal_id'])->first();

        // D09: Equivalent canonical payload hash, source state hash, and totals
        $this->assertSame($legacyProposal->payload_hash, $sharedProposal->payload_hash);
        $this->assertSame($legacyProposal->source_state_hash, $sharedProposal->source_state_hash);
        $this->assertSame($legacyData['data']['totals'], $sharedData['data']['totals']);

        // D10: Amend shared draft task -> bumps task revision & proposal version, invalidating prior approval
        app(ActionApprovalService::class)->approve($principal, $sharedProposal->id, 1);
        $amended = app(CapabilityDispatcher::class)->dispatch(
            $principal,
            'sdk',
            'purchase_order.create.v1',
            'prepare',
            [
                'task_id' => $sharedData['data']['task_id'],
                'expected_revision' => (int) $sharedData['data']['task_revision'],
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '80', 'net_unit_cost' => '120.00'],
                ],
            ],
        );
        $this->assertSame('ok', $amended['status']);
        $this->assertSame(2, $amended['data']['version']);
        $reloaded = DB::table('ai_action_proposals')->where('id', $sharedProposal->id)->first();
        $this->assertSame('pending', $reloaded->status);

        // Approve & execute v2 -> posts one real purchase order
        app(ActionApprovalService::class)->approve($principal, $sharedProposal->id, 2);
        $exec = app(ApprovedActionExecutor::class)->execute($principal, $sharedProposal->id, 2);
        $this->assertSame('completed', $exec['status']);
        $this->assertSame(1, Purchase::where('company_id', $this->companyA->id)->count());

        // D14: Zero LLM invocations and zero credit debits for structured preparation
        if (Schema::hasTable('ai_model_invocations')) {
            $this->assertSame(0, DB::table('ai_model_invocations')->where('company_id', $this->companyA->id)->count());
        }
        if (Schema::hasTable('ai_credit_ledger')) {
            $this->assertSame(0, DB::table('ai_credit_ledger')->where('company_id', $this->companyA->id)->where('kind', 'usage_debit')->count());
        }
    }

    public function test_d11_d12_copilot_and_automation_builder_discovery_never_grant_execution(): void
    {
        $principal = app(AiPrincipalFactory::class)->createForUser($this->adminA->id, $this->companyA->id, $this->branchA1, $this->fyA1);

        // ErpCopilot exposes bounded shared tools including PreparePurchaseOrder
        $copilot = new ErpCopilot($principal);
        $toolNames = array_map(static fn ($tool): string => $tool->name(), iterator_to_array($copilot->tools()));
        $this->assertContains('CustomerOutstanding', $toolNames);
        $this->assertContains('prepare_purchase_order', $toolNames);
        $this->assertContains('prepare_reviewed_action', $toolNames);

        // Automation Builder discovers prepare-only PO capability without execution grant
        $autoEligible = app(CapabilityExposurePolicy::class)->discover($principal, 'automation');
        $autoOps = array_unique(array_map(static fn ($d): string => $d->operation, $autoEligible));
        $this->assertSame([], array_diff($autoOps, ['read', 'prepare']));
        $this->assertTrue(collect($autoEligible)->contains(
            static fn ($d): bool => $d->capabilityKey === 'purchase_order.create.v1' && $d->requiresHumanApproval
        ));

        // D11: Dispatcher denies execute/approve operations
        $this->expectException(AuthorizationException::class);
        app(CapabilityDispatcher::class)->dispatch($principal, 'sdk', 'purchase_order.create.v1', 'execute', []);
    }
}
