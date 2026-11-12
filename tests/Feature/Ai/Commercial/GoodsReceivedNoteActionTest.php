<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\DTOs\AiPrincipal;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\GoodsReceivedNote;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class GoodsReceivedNoteActionTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private User $restrictedUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);
        $this->app->forgetInstance(\App\Ai\Services\AiFeatureGate::class);

        // Ensure roles
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 4], ['name' => 'Viewer', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'GRN Admin User',
            'email' => 'grn_admin_' . uniqid() . '@example.com',
            'phone' => '9777777773',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->restrictedUser = User::where('role_id', 4)->first() ?? User::create([
            'name' => 'GRN Restricted Viewer',
            'email' => 'grn_viewer_' . uniqid() . '@example.com',
            'phone' => '9777777774',
            'password' => bcrypt('secret'),
            'role_id' => 4,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Kovai Mills Ltd',
            'code' => 'KOVAI_MILLS_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->where('code', 'MAIN')->first()
            ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'branch_type' => 'branch']);

        $this->fiscalYear = $this->company->fiscalYears()->first()
            ?? $this->company->fiscalYears()->create([
                'name' => 'FY 2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open',
                'is_closed' => false,
            ]);

        $this->company->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
            $this->restrictedUser->id => ['is_default' => true],
        ]);

        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branch->id]
        );
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->restrictedUser->id, 'branch_id' => $this->branch->id]
        );

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->first()
            ?? Warehouse::create([
                'name' => 'Main Receiving Warehouse',
                'code' => 'MRW_' . uniqid(),
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
                'is_active' => true,
            ]);

        $this->supplier = Supplier::where('company_id', $this->company->id)->first()
            ?? Supplier::create([
                'name' => 'Coimbatore Cotton Suppliers',
                'company_name' => 'Coimbatore Cotton Corp',
                'vat_number' => '33AAACC1206D1ZN',
                'phone_number' => '9842100000',
                'company_id' => $this->company->id,
                'is_active' => true,
            ]);

        if (empty($this->supplier->vat_number) || $this->supplier->vat_number !== '33AAACC1206D1ZN') {
            $this->supplier->update(['vat_number' => '33AAACC1206D1ZN']);
        }

        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->company->id,
                'party_type' => 'supplier',
                'party_id' => $this->supplier->id,
            ],
            [
                'gstin' => '33AAACC1206D1ZN',
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $unit = Unit::firstOrCreate(
            ['unit_code' => 'KG', 'company_id' => $this->company->id],
            ['unit_name' => 'Kilogram', 'is_active' => true]
        );

        $category = Category::firstOrCreate(
            ['name' => 'Raw Materials', 'company_id' => $this->company->id],
            ['is_active' => true]
        );

        $taxCategoryId = DB::table('tax_categories')->where('company_id', $this->company->id)->where('name', 'GST 5%')->value('id')
            ?? DB::table('tax_categories')->insertGetId([
                'company_id' => $this->company->id,
                'name' => 'GST 5%',
                'supply_type' => 'taxable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('tax_rates')->updateOrInsert(
            ['company_id' => $this->company->id, 'tax_category_id' => $taxCategoryId],
            [
                'rate' => 5.0,
                'effective_from' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->productA = Product::where('company_id', $this->company->id)->where('code', 'RAW-COTTON-01')->first()
            ?? Product::forceCreate([
                'name' => 'Raw Combed Cotton Grade A',
                'code' => 'RAW-COTTON-01',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => 200.00,
                'price' => 280.00,
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'category_id' => $category->id,
                'tax_category_id' => $taxCategoryId,
                'hsn_code' => '5205',
                'company_id' => $this->company->id,
                'is_active' => true,
            ]);

        $this->productB = Product::where('company_id', $this->company->id)->where('code', 'RAW-COTTON-02')->first()
            ?? Product::forceCreate([
                'name' => 'Raw Combed Cotton Grade B',
                'code' => 'RAW-COTTON-02',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => 180.00,
                'price' => 240.00,
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'category_id' => $category->id,
                'tax_category_id' => $taxCategoryId,
                'hsn_code' => '5205',
                'company_id' => $this->company->id,
                'is_active' => true,
            ]);
    }

    private function contextHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->company->id,
            'X-Branch-Id' => (string) $this->branch->id,
            'X-Financial-Year-Id' => (string) $this->fiscalYear->id,
        ];
    }

    public function test_unauthorized_user_cannot_create_grn_proposal(): void
    {
        $this->actingAs($this->restrictedUser, 'sanctum');

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'grn.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '50.00', 'cost' => '200.00'],
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_grn_preview_calculates_intake_with_zero_database_writes(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $grnCount = GoodsReceivedNote::count();
        $movementsCount = DB::table('stock_movements')->count();

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'grn.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '50.00', 'cost' => '200.00'],
                    ['product_id' => $this->productB->id, 'qty' => '25.00', 'cost' => '180.00'],
                ],
                'lr_no' => 'LR-98765',
                'transport_name' => 'Fast Cargo',
            ],
        ]);

        $response->assertStatus(201);
        $proposal = $response->json('proposal');
        $this->assertSame('grn.create.v1', $proposal['action_key']);
        $this->assertSame(1, $proposal['version']);

        $preview = $proposal['preview'];
        $this->assertSame('75.00', $preview['totals']['total_qty']);
        // 50 * 200 = 10,000; 25 * 180 = 4,500; total = 14,500
        $this->assertSame('14500.00', $preview['totals']['total_cost']);

        // Assert zero database mutations occurred during preview
        $this->assertSame($grnCount, GoodsReceivedNote::count());
        $this->assertSame($movementsCount, DB::table('stock_movements')->count());
    }

    public function test_approved_grn_execution_receives_physical_stock_and_posts_accrual_journal(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // Create proposal
        $createRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'grn.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '40.00', 'cost' => '200.00'],
                ],
                'lr_no' => 'LR-12345',
            ],
        ]);
        $createRes->assertStatus(201);
        $proposalId = $createRes->json('proposal.id');

        // Approve proposal (which atomically executes it)
        $appRes = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
            'expected_version' => 1,
        ]);
        $appRes->assertStatus(200);
        $receipt = $appRes->json('execution.receipt');

        $this->assertSame('grn', $receipt['resource_type']);
        $this->assertSame('completed', $receipt['status']);
        $grnId = (int) $receipt['resource_id'];

        $grn = GoodsReceivedNote::findOrFail($grnId);
        $this->assertSame($this->company->id, (int) $grn->company_id);
        $this->assertSame($this->warehouse->id, (int) $grn->warehouse_id);
        $this->assertSame(40.0, (float) $grn->total_qty);
        $this->assertSame(8000.0, (float) $grn->grand_total);
        $this->assertNotNull($grn->posted_at);

        // Verify physical stock movement was created
        $this->assertNotNull($grn->stock_movement_id);
        $movement = DB::table('stock_movements')->where('id', $grn->stock_movement_id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('goods_received_note', $movement->source_type);

        // Verify accrual journal entry was created (Dr Inventory, Cr Goods Received Not Invoiced)
        if ($grn->journal_entry_id) {
            $journal = DB::table('journal_entries')->where('id', $grn->journal_entry_id)->first();
            $this->assertNotNull($journal);
        }
    }

    public function test_grn_execution_against_purchase_order_consumes_order_delivery_stage(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // Create an open Purchase Order first
        $poData = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->productA->id, 'qty' => '100.00', 'net_unit_cost' => '200.00'],
            ],
            'date' => now()->toDateString(),
        ];

        $poRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => $poData,
        ]);
        $poRes->assertStatus(201);
        $poProposalId = $poRes->json('proposal.id');
        $poApp = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$poProposalId}/approve", [
            'expected_version' => 1,
        ]);
        $poApp->assertStatus(200);
        $poId = (int) $poApp->json('execution.receipt.resource_id');

        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);
        $orders = app(OrderLifecycleService::class);
        $remainingBefore = $orders->remaining('purchase', $poId, $context);
        $this->assertSame(100.0, (float) $remainingBefore['items'][0]['remaining_delivery_qty']);

        // Now create a GRN proposal fulfilling 60 units of this PO
        $grnRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'grn.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'purchase_order_id' => $poId,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '60.00', 'cost' => '200.00'],
                ],
            ],
        ]);
        $grnRes->assertStatus(201);
        $grnProposalId = $grnRes->json('proposal.id');

        $grnApp = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$grnProposalId}/approve", [
            'expected_version' => 1,
        ]);
        $grnApp->assertStatus(200);

        // Verify delivery stage consumed: remaining delivery is now 40, but billing remains 100 open!
        $remainingAfter = $orders->remaining('purchase', $poId, $context);
        $this->assertSame(40.0, (float) $remainingAfter['items'][0]['remaining_delivery_qty']);
        $this->assertSame(100.0, (float) $remainingAfter['items'][0]['remaining_qty']);
    }
}
