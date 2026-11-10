<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use App\Ai\Services\AiToolManifest;
use App\Ai\Agents\ErpCopilot;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

class OperationalAndActionProposalsTest extends TestCase
{
    use UsesAiMysql;

    protected User $user;
    protected Company $company;
    protected int $branch1Id;
    protected int $fyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Operational Admin',
            'email' => 'op_admin_' . uniqid() . '@example.com',
            'phone' => '9999900060',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->actingAs($this->user);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Op Test Company',
            'code' => 'OP-CO-' . uniqid(),
            'status' => 'active',
        ]);

        if (!$this->company->users()->where('users.id', $this->user->id)->exists()) {
            $this->company->users()->attach($this->user->id, ['is_default' => true]);
        }

        $b1 = $this->company->branches()->first() ?? $this->company->branches()->create([
            'name' => 'Branch Op-1',
            'code' => 'BR-OP-1-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branch1Id = $b1->id;

        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $this->branch1Id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $fy = FiscalYear::where('company_id', $this->company->id)->first() ?? FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $this->fyId = $fy->id;

        $this->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
        ]);

        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
    }

    /**
     * P2: Manufacturing BOMs Tool
     */
    public function test_manufacturing_boms_tool_returns_recipe_components(): void
    {
        config(['operations.enabled' => true]);
        $capabilities = \Mockery::mock(\App\Services\Platform\CapabilityService::class)->makePartial();
        $capabilities->shouldReceive('enabled')->with('manufacturing.bom', \Mockery::type(\App\Services\Platform\CompanyContext::class))->andReturn(true);
        $this->app->instance(\App\Services\Platform\CapabilityService::class, $capabilities);
        $unit = Unit::first() ?? Unit::create(['unit_code' => 'PCS', 'unit_name' => 'Pieces', 'is_active' => true]);
        $category = Category::where('is_active', true)->first() ?? Category::create(['name' => 'Cat ' . uniqid(), 'is_active' => true]);

        $finishedGood = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Finished Desk',
            'code' => 'FG-DSK-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '500.00',
            'price' => '1000.00',
            'is_active' => true,
        ]);

        $rawWood = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Raw Timber Plank',
            'code' => 'RAW-WD-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '100.00',
            'price' => '120.00',
            'is_active' => true,
        ]);

        $bomId = DB::table('boms')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'created_by' => $this->user->id,
            'effective_from' => '2026-04-01',
            'product_id' => $finishedGood->id,
            'code' => 'BOM-DSK-01',
            'version' => 1,
            'output_qty' => 1,
            'output_uom_id' => $unit->id,
            'is_default' => true,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bom_lines')->insert([
            'bom_id' => $bomId,
            'line_no' => 1,
            'component_product_id' => $rawWood->id,
            'qty' => 4,
            'uom_id' => $unit->id,
            'scrap_percent' => 5,
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['manufacturing.boms.v1'],
            requestId: 'mfg-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('manufacturing.boms.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request(['product_id' => $finishedGood->id])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('manufacturing.boms.v1', $res['tool']);
        $this->assertSame(1, $res['data']['total_boms_found']);
        $this->assertSame($finishedGood->name, $res['data']['boms'][0]['output_product']['name']);
        $this->assertSame(1, $res['data']['boms'][0]['component_count']);
        $this->assertSame($rawWood->name, $res['data']['boms'][0]['components'][0]['component_name']);
        $this->assertSame(4.0, (float) $res['data']['boms'][0]['components'][0]['qty']);
    }

    /**
     * P2: Open Orders Tool
     */
    public function test_open_orders_tool_lists_unfulfilled_sales_and_purchases(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Order Test Customer',
            'phone_number' => '9999900061',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $wh = Warehouse::where('company_id', $this->company->id)->where('branch_id', $this->branch1Id)->first() ?? Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'name' => 'WH Orders',
            'is_active' => true,
        ]);

        $order = \App\Models\SalesOrder::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'warehouse_id' => $wh->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'reference_no' => 'SO-ORD-' . uniqid(),
            'business_date' => '2026-10-01',
            'status' => 'open',
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['orders.open.v1'],
            requestId: 'orders-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('orders.open.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request(['type' => 'sale'])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('orders.open.v1', $res['tool']);
        $this->assertGreaterThanOrEqual(1, $res['data']['summary']['total_open_orders']);
        $this->assertSame($order->reference_no, $res['data']['orders'][0]['reference_no']);
        $this->assertNull($res['data']['orders'][0]['grand_total']);
        $this->assertNull($res['data']['orders'][0]['open_balance']);
    }

    public function test_proposals_remain_quarantined_with_write_flags_enabled(): void
    {
        $id = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $id,
            'actor_id' => $this->user->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'action_type' => 'draft_quotation',
            'action_title' => 'Existing quotation',
            'payload_json' => '{}',
            'preview_json' => '{}',
            'status' => 'pending',
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['proposals.draft.v1'],
        );

        $this->assertSame([], (new ErpCopilot($principal))->tools());
        $before = DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count();
        $erpTables = ['sales', 'stock_movements', 'journal_entries', 'document_dispatch_logs'];
        $erpCounts = array_map(fn ($table) => DB::table($table)->where('company_id', $this->company->id)->count(), $erpTables);
        $result = app(ErpToolExecutor::class)->execute($principal->requestId, $principal, 'proposals.draft.v1', []);
        $this->assertSame('AI_TOOL_NOT_FOUND', $result->errorCode);

        foreach (['/ai', '/api/v1/ai'] as $prefix) {
            $this->getJson("$prefix/proposals")->assertForbidden()->assertJsonPath('error_code', 'AI_ACTIONS_DISABLED');
            foreach ([$id, $id, (string) Str::uuid()] as $proposalId) {
                foreach (['approve', 'reject'] as $operation) {
                    $this->postJson("$prefix/proposals/$proposalId/$operation")
                        ->assertForbidden()->assertJsonPath('error_code', 'AI_ACTIONS_DISABLED');
                }
            }
        }

        $this->assertSame($before, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count());
        $this->assertDatabaseHas('ai_action_proposals', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseMissing('ai_action_approvals', ['proposal_id' => $id]);
        $this->assertDatabaseMissing('ai_action_executions', ['proposal_id' => $id]);
        $this->assertSame($erpCounts, array_map(fn ($table) => DB::table($table)->where('company_id', $this->company->id)->count(), $erpTables));
    }

    public function test_sales_permission_does_not_grant_purchase_preview_or_orders(): void
    {
        $role = DB::table('roles')->insertGetId(['name' => 'Sales AI fixture', 'guard_name' => 'web', 'is_active' => true]);
        foreach (['ai.use', 'sales-index'] as $name) {
            $permission = DB::table('permissions')->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('role_has_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->user->id)
            ->update(['role_id_override' => $role]);
        $principal = app(\App\Ai\Services\AiPrincipalFactory::class)->createForUser(
            $this->user->id, $this->company->id, $this->branch1Id, $this->fyId,
        );

        $manifest = app(AiToolManifest::class);
        $preview = $manifest->sdkTool('documents.preview.v1', $principal);
        $orders = $manifest->sdkTool('orders.open.v1', $principal);
        $this->assertSame('AI_TOOL_FORBIDDEN', json_decode($preview->handle(new Request(['document_type' => 'purchase', 'document_id' => 1])), true)['error_code']);
        $this->assertSame('AI_TOOL_FORBIDDEN', json_decode($orders->handle(new Request(['type' => 'purchase'])), true)['error_code']);
        $allOrders = json_decode($orders->handle(new Request(['type' => 'all'])), true);
        $this->assertSame(['sale'], $allOrders['data']['included_types']);
        $this->assertNull($allOrders['data']['summary']['total_purchase_value']);
    }

    /**
     * P4: Document Preview Tool
     */
    public function test_document_preview_tool_returns_verified_link(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Preview Customer',
            'phone_number' => '9999900065',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $wh = Warehouse::where('company_id', $this->company->id)->where('branch_id', $this->branch1Id)->first() ?? Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'name' => 'WH Preview',
            'is_active' => true,
        ]);

        $biller = \App\Models\Biller::where('company_id', $this->company->id)->first() ?? \App\Models\Biller::create([
            'company_id' => $this->company->id,
            'name' => 'Preview Biller',
            'code' => 'PREV-BIL-' . uniqid(),
            'is_active' => true,
        ]);

        $sale = Sale::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'warehouse_id' => $wh->id,
            'customer_id' => $customer->id,
            'biller_id' => $biller->id,
            'user_id' => $this->user->id,
            'reference_no' => 'INV-PREV-' . uniqid(),
            'item' => 1,
            'total_qty' => 2,
            'total_discount' => '0.00',
            'total_tax' => '0.00',
            'total_price' => '1200.00',
            'grand_total' => '1200.00',
            'paid_amount' => '1200.00',
            'sale_status' => 1,
            'payment_status' => 4,
            'created_at' => now(),
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['documents.preview.v1'],
            requestId: 'prev-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('documents.preview.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request([
            'document_type' => 'sale',
            'document_id' => $sale->id,
        ])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('documents.preview.v1', $res['tool']);
        $this->assertSame($sale->reference_no, $res['data']['reference_no']);
        $this->assertSame($customer->name, $res['data']['party']['name']);
        $this->assertNotEmpty($res['data']['view_link']);
    }

    /**
     * P4: Company Knowledge Query Tool
     */
    public function test_company_knowledge_does_not_invent_payment_terms(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['knowledge.query.v1'],
            requestId: 'know-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('knowledge.query.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request(['topic' => 'payment_terms'])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('knowledge.query.v1', $res['tool']);
        $this->assertSame([], $res['data']['knowledge']);
        $this->assertNotEmpty($res['warnings']);
    }
}
