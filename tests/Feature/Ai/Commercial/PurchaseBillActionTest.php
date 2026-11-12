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
use App\Services\Commercial\MaterialDocumentService;
use App\Services\Commercial\OrderLifecycleService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class PurchaseBillActionTest extends TestCase
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
            'name' => 'Bill Admin User',
            'email' => 'bill_admin_' . uniqid() . '@example.com',
            'phone' => '9777777775',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->restrictedUser = User::where('role_id', 4)->first() ?? User::create([
            'name' => 'Bill Restricted Viewer',
            'email' => 'bill_viewer_' . uniqid() . '@example.com',
            'phone' => '9777777776',
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
                'name' => 'Central Mill Warehouse',
                'code' => 'CMW_' . uniqid(),
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

    public function test_unauthorized_user_cannot_create_purchase_bill_proposal(): void
    {
        $this->actingAs($this->restrictedUser, 'sanctum');

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_bill.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '200.00'],
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_purchase_bill_preview_calculates_gst_breakdown_with_zero_database_writes(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $initialPurchasesCount = Purchase::count();
        $initialMovementsCount = DB::table('stock_movements')->count();
        $initialJournalsCount = DB::table('journal_entries')->count();

        // 50 units @ 200.00 = 10,000 subtotal. 5% intra-state GST = 250 CGST + 250 SGST = 500 tax. Total = 10,500.
        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_bill.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'date' => now()->toDateString(),
                'supplier_invoice_no' => 'INV-SUP-8888',
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '50.00', 'net_unit_cost' => '200.00'],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $proposal = $response->json('proposal');
        $this->assertSame('purchase_bill.create.v1', $proposal['action_key']);
        $this->assertSame(1, $proposal['version']);

        $preview = $proposal['preview'];
        $totals = $preview['totals'];
        $this->assertSame('10000.00', $totals['subtotal']);
        $this->assertSame('500.00', $totals['tax_total']);
        $this->assertSame('10500.00', $totals['grand_total']);
        $this->assertSame('250.00', $totals['tax_breakdown']['cgst']);
        $this->assertSame('250.00', $totals['tax_breakdown']['sgst']);
        $this->assertSame('0.00', $totals['tax_breakdown']['igst']);

        // Assert zero database mutations occurred during preview
        $this->assertSame($initialPurchasesCount, Purchase::count());
        $this->assertSame($initialMovementsCount, DB::table('stock_movements')->count());
        $this->assertSame($initialJournalsCount, DB::table('journal_entries')->count());
    }

    public function test_approved_direct_purchase_bill_creates_voucher_moves_stock_and_posts_financial_ledger(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $createRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_bill.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'date' => now()->toDateString(),
                'supplier_invoice_no' => 'INV-DIR-9001',
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '40.00', 'net_unit_cost' => '200.00'],
                ],
            ],
        ]);
        $createRes->assertStatus(201);
        $proposalId = $createRes->json('proposal.id');

        // Approve proposal (which atomically executes the command)
        $appRes = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
            'expected_version' => 1,
        ]);
        $appRes->assertStatus(200);
        $receipt = $appRes->json('execution.receipt');

        $this->assertSame('purchase_bill', $receipt['resource_type']);
        $this->assertSame('completed', $receipt['status']);
        $purchaseId = (int) $receipt['resource_id'];

        $purchase = Purchase::with('productPurchases')->findOrFail($purchaseId);
        $this->assertSame($this->company->id, (int) $purchase->company_id);
        $this->assertSame($this->warehouse->id, (int) $purchase->warehouse_id);
        $this->assertSame($this->supplier->id, (int) $purchase->supplier_id);
        $this->assertSame(1, (int) $purchase->status); // Status 1 = Completed Bill
        $this->assertSame(40.0, (float) $purchase->total_qty);
        $this->assertSame(8400.0, (float) $purchase->grand_total); // 8,000 + 400 (5% GST)

        // Verify physical stock movement was created for direct bill
        $movement = DB::table('stock_movements')
            ->where('company_id', $this->company->id)
            ->where('source_type', 'purchase')
            ->where('source_id', $purchase->id)
            ->first();
        $this->assertNotNull($movement, 'Direct purchase bill must create physical stock receipt.');

        // Verify Accounts Payable & Tax Credit financial ledger entries were posted
        $journals = DB::table('journal_entries')
            ->where('company_id', $this->company->id)
            ->where('reference_type', 'purchase')
            ->where('reference_id', $purchase->id)
            ->get();
        $this->assertNotEmpty($journals, 'Financial ledger journal entry must be posted for completed purchase bill.');
    }

    public function test_purchase_bill_against_grn_settles_accrual_without_duplicate_stock_movement(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // 1. Create and approve a Goods Received Note first (receives stock & posts accrual journal)
        $grnRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'grn.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '50.00', 'cost' => '200.00'],
                ],
                'lr_no' => 'LR-GRN-SETTLE-01',
            ],
        ]);
        $grnRes->assertStatus(201);
        $grnProposalId = $grnRes->json('proposal.id');

        $grnApp = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$grnProposalId}/approve", [
            'expected_version' => 1,
        ]);
        $grnApp->assertStatus(200);
        $grnId = (int) $grnApp->json('execution.receipt.resource_id');

        $grn = GoodsReceivedNote::findOrFail($grnId);
        $this->assertNotNull($grn->posted_at);

        // Record stock movements count right after GRN intake
        $movementsAfterGrn = DB::table('stock_movements')->where('company_id', $this->company->id)->count();

        // 2. Prepare a Purchase Bill proposal against this GRN
        $billRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_bill.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'grn_id' => $grn->id,
                'supplier_invoice_no' => 'INV-GRN-SETTLE-01',
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '50.00', 'net_unit_cost' => '200.00'],
                ],
            ],
        ]);
        $billRes->assertStatus(201);
        $billProposalId = $billRes->json('proposal.id');

        // Verify preview explains the settlement policy
        $preview = $billRes->json('proposal.preview');
        $this->assertStringContainsString('settles the GRN accrual', $preview['policy']['inventory_impact']);

        // 3. Approve and execute the purchase bill
        $billApp = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$billProposalId}/approve", [
            'expected_version' => 1,
        ]);
        $billApp->assertStatus(200);
        $billReceipt = $billApp->json('execution.receipt');
        $purchaseId = (int) $billReceipt['resource_id'];

        $purchase = Purchase::findOrFail($purchaseId);
        $this->assertSame(1, (int) $purchase->status);

        // 4. CRITICAL INVARIANT: Verify physical stock quantity was NOT duplicated!
        $totalPhysicalQty = DB::table('stock_movement_lines')->where('company_id', $this->company->id)->sum('qty_base');
        $this->assertSame(
            50.0,
            (float) $totalPhysicalQty,
            'Physical inventory quantity must only be received once (50.00), not duplicated!'
        );

        // Verify the bill's movement line is a zero-quantity price/valuation adjustment
        $billMovementLine = DB::table('stock_movement_lines')
            ->where('company_id', $this->company->id)
            ->where('stock_movement_id', '<>', $grn->stock_movement_id)
            ->first();
        if ($billMovementLine) {
            $this->assertSame(0.0, (float) $billMovementLine->qty_base, 'Bill stock movement must carry zero physical quantity.');
            $attributes = json_decode((string) $billMovementLine->attributes_json, true) ?? [];
            $this->assertTrue((bool) ($attributes['price_variance'] ?? false));
        }

        // 5. Verify material settlement was registered
        $settlement = DB::table('material_settlements')
            ->where('company_id', $this->company->id)
            ->where('document_type', 'grn')
            ->where('document_id', $grn->id)
            ->where('settled_by_type', 'purchase')
            ->where('settled_by_id', $purchase->id)
            ->first();
        $this->assertNotNull($settlement, 'Material settlement record must link the GRN to the Purchase Bill.');
    }

    public function test_purchase_bill_against_open_po_consumes_order_billing_stage(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // Create an open Purchase Order first for 100 units
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
        $this->assertSame(100.0, (float) $remainingBefore['items'][0]['remaining_qty']);

        // Now create a Purchase Bill proposal fulfilling 40 units of this PO
        $billRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_bill.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'purchase_order_id' => $poId,
                'supplier_invoice_no' => 'INV-PO-BILL-01',
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '40.00', 'net_unit_cost' => '200.00'],
                ],
            ],
        ]);
        $billRes->assertStatus(201);
        $billProposalId = $billRes->json('proposal.id');

        $billApp = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$billProposalId}/approve", [
            'expected_version' => 1,
        ]);
        $billApp->assertStatus(200);

        // Verify billing stage consumed: remaining billing is now 60!
        $remainingAfter = $orders->remaining('purchase', $poId, $context);
        $this->assertSame(60.0, (float) $remainingAfter['items'][0]['remaining_qty']);
    }

    public function test_tampered_source_state_fails_closed_during_purchase_bill_approval(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $createRes = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_bill.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'date' => now()->toDateString(),
                'supplier_invoice_no' => 'INV-TAMPER-01',
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '200.00'],
                ],
            ],
        ]);
        $createRes->assertStatus(201);
        $proposalId = $createRes->json('proposal.id');

        // Tamper with the supplier tax profile before approval
        DB::table('party_tax_profiles')
            ->where('company_id', $this->company->id)
            ->where('party_type', 'supplier')
            ->where('party_id', $this->supplier->id)
            ->update(['state_code' => '29', 'updated_at' => now()]); // Changed from 33 (TN) to 29 (KA)

        $approveRes = $this->withHeaders($this->contextHeaders())->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
            'expected_version' => 1,
        ]);

        $approveRes->assertStatus(422);
        $approveRes->assertJsonValidationErrors(['source_state']);
    }
}
