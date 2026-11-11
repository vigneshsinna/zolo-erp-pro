<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ReadOpenOrders;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class PurchaseOrderActionTest extends TestCase
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
            'name' => 'PO Admin User',
            'email' => 'po_admin_' . uniqid() . '@example.com',
            'phone' => '9777777771',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->restrictedUser = User::where('role_id', 4)->first() ?? User::create([
            'name' => 'PO Restricted Viewer',
            'email' => 'po_viewer_' . uniqid() . '@example.com',
            'phone' => '9777777772',
            'password' => bcrypt('secret'),
            'role_id' => 4,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        // Base company context
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

        // Memberships
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

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('branch_id', $this->branch->id)->first()
            ?? Warehouse::create([
                'name' => 'Main Yarn Store',
                'address' => 'Plot 10, Weaving Sector',
                'is_active' => true,
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
            ]);

        $this->supplier = Supplier::where('company_id', $this->company->id)->first()
            ?? Supplier::create([
                'name' => 'Sri Lakshmi Cotton Traders',
                'company_name' => 'Sri Lakshmi Cotton Ltd',
                'vat_number' => '33AAACC1206D1ZN',
                'phone_number' => '9842100001',
                'email' => 'cotton@lakshmi.test',
                'address' => '12 Mill Road, Coimbatore',
                'city' => 'Coimbatore',
                'state' => 'Tamil Nadu',
                'is_active' => true,
                'company_id' => $this->company->id,
            ]);

        if (empty($this->supplier->vat_number) || $this->supplier->vat_number !== '33AAACC1206D1ZN') {
            $this->supplier->update(['vat_number' => '33AAACC1206D1ZN', 'state' => 'Tamil Nadu']);
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

        $unit = Unit::where('company_id', $this->company->id)->first()
            ?? Unit::forceCreate(['unit_code' => 'KG', 'unit_name' => 'Kilogram', 'company_id' => $this->company->id, 'is_active' => true]);
        $category = Category::where('company_id', $this->company->id)->first()
            ?? Category::forceCreate(['name' => 'Raw Cotton', 'company_id' => $this->company->id, 'is_active' => true]);

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

        $this->productA = Product::where('company_id', $this->company->id)->where('code', 'YARN-40S')->first()
            ?? Product::forceCreate([
                'name' => 'Combed Cotton Yarn 40s',
                'code' => 'YARN-40S',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => '250.00',
                'price' => '290.00',
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'category_id' => $category->id,
                'tax_category_id' => $taxCategoryId,
                'hsn_code' => '5205',
                'company_id' => $this->company->id,
                'is_active' => true,
            ]);

        $this->productB = Product::where('company_id', $this->company->id)->where('code', 'YARN-60S')->first()
            ?? Product::forceCreate([
                'name' => 'Carded Cotton Yarn 60s',
                'code' => 'YARN-60S',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => '320.00',
                'price' => '360.00',
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

    public function test_unauthorized_user_cannot_create_purchase_order_proposal(): void
    {
        $this->actingAs($this->restrictedUser, 'sanctum');

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_purchase_order_preview_computes_deterministic_pricing_with_zero_database_writes(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $purchasesCount = Purchase::count();
        $linesCount = DB::table('product_purchases')->count();
        $orderItemsCount = DB::table('commercial_order_items')->count();

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '50.00', 'net_unit_cost' => '240.00', 'discount' => '0.00', 'tax_rate' => '5.00'],
                    ['product_id' => $this->productB->id, 'qty' => '25.00', 'net_unit_cost' => '300.00', 'discount' => '0.00', 'tax_rate' => '5.00'],
                ],
                'note' => 'Initial spinning sample PO',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'pending',
            'proposal' => [
                'action_key' => 'purchase_order.create.v1',
                'version' => 1,
            ],
        ]);

        $proposal = $response->json('proposal');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $proposal['payload_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $proposal['source_state_hash']);
        $expectedSourceHash = \App\Ai\Adapters\PurchaseOrderPreviewAdapter::computeSourceStateHash(
            new \App\Services\Platform\CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id),
            $this->supplier->id,
            $this->warehouse->id,
            [$this->productA->id, $this->productB->id],
        );
        $this->assertSame($expectedSourceHash, $proposal['source_state_hash']);

        // Check preview calculation:
        // Line A: 50 * 240 = 12000, 5% tax = 600, total = 12600
        // Line B: 25 * 300 = 7500, 5% tax = 375, total = 7875
        // Grand total: 12600 + 7875 = 20475
        $preview = $proposal['preview'];
        $this->assertSame('19500.00', $preview['totals']['subtotal']);
        $this->assertSame('975.00', $preview['totals']['tax_total']);
        $this->assertSame('20475.00', $preview['totals']['grand_total']);
        $this->assertSame('75.00', $preview['totals']['total_qty']);

        // Zero writes to core ERP tables
        $this->assertSame($purchasesCount, Purchase::count());
        $this->assertSame($linesCount, DB::table('product_purchases')->count());
        $this->assertSame($orderItemsCount, DB::table('commercial_order_items')->count());
    }

    public function test_change_request_updates_line_items_and_increments_version(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $createResponse = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);

        $proposalId = $createResponse->json('proposal.id');
        $v1Hash = $createResponse->json('proposal.payload_hash');

        // Apply change request: increase quantity to 20
        $changeResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/change-request", [
                'expected_version' => 1,
                'changes' => [
                    'items' => [
                        ['product_id' => $this->productA->id, 'qty' => '20.00', 'net_unit_cost' => '250.00'],
                    ],
                ],
            ]);

        $changeResponse->assertStatus(200);
        $changeResponse->assertJson([
            'status' => 'pending',
            'proposal' => [
                'id' => $proposalId,
                'version' => 2,
            ],
        ]);

        $v2Hash = $changeResponse->json('proposal.payload_hash');
        $this->assertNotSame($v1Hash, $v2Hash);
        $this->assertSame('5250.00', $changeResponse->json('proposal.preview.totals.grand_total'));
        $this->assertSame('5000.00', $changeResponse->json('proposal.preview.totals.subtotal'));
        $this->assertSame('250.00', $changeResponse->json('proposal.preview.totals.tax_total'));

        // Editing stale version 1 fails
        $staleResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/change-request", [
                'expected_version' => 1,
                'changes' => ['items' => []],
            ]);

        $staleResponse->assertStatus(422);
    }

    public function test_execution_creates_status_4_purchase_order_in_order_register_atomically(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $createResponse = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '40.00', 'net_unit_cost' => '250.00', 'tax_rate' => '5.00'],
                ],
                'note' => 'Authoritative AI Purchase Order',
            ],
        ]);

        $proposalId = $createResponse->json('proposal.id');

        // Approve and execute
        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $approveResponse->assertJson([
            'status' => 'completed',
            'proposal_id' => $proposalId,
            'version' => 1,
        ]);

        $execution = $approveResponse->json('execution');
        $this->assertSame('completed', $execution['status']);
        $purchaseId = $execution['receipt']['resource_id'];
        $this->assertGreaterThan(0, $purchaseId);

        // Verify created Purchase in order register
        $purchase = Purchase::find($purchaseId);
        $this->assertNotNull($purchase);
        $this->assertSame(4, (int) $purchase->status); // Status 4 = Purchase Order (Ordered)
        $this->assertSame(2, (int) $purchase->payment_status); // Payment status 2 = Due
        $this->assertSame($this->company->id, (int) $purchase->company_id);
        $this->assertSame($this->supplier->id, (int) $purchase->supplier_id);
        $this->assertSame($this->warehouse->id, (int) $purchase->warehouse_id);

        // Lines in product_purchases
        $this->assertSame(1, $purchase->productPurchases()->count());
        $line = $purchase->productPurchases()->first();
        $this->assertSame($this->productA->id, (int) $line->product_id);
        $this->assertEquals(40.0, (float) $line->qty);

        // Lines in commercial_order_items (Order Lifecycle)
        $orderItem = DB::table('commercial_order_items')->where('order_id', $purchase->id)->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(40.0, (float) $orderItem->qty);

        // Zero physical stock additions & zero GL entries
        $stockMovementsCount = DB::table('stock_movements')
            ->where('company_id', $this->company->id)
            ->where('source_type', 'purchase')
            ->where('source_id', $purchase->id)
            ->count();
        $this->assertSame(0, $stockMovementsCount);

        $journalEntriesCount = DB::table('journal_entries')
            ->where('company_id', $this->company->id)
            ->where('reference_type', 'purchase')
            ->where('reference_id', $purchase->id)
            ->count();
        $this->assertSame(0, $journalEntriesCount);

        // Idempotency: re-execution returns cached receipt without duplication
        $purchasesCount = Purchase::count();
        $retryResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $retryResponse->assertStatus(200);
        $this->assertSame($purchaseId, $retryResponse->json('execution.receipt.resource_id'));
        $this->assertSame($purchasesCount, Purchase::count());

        // Verify read facade ReadOpenOrders detects this newly created purchase order
        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);
        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['orders.open.v1'],
        );

        $openOrdersReader = app(ReadOpenOrders::class);
        $openOrdersResult = $openOrdersReader->execute($principal, type: 'purchase', limit: 10);
        $this->assertSame('ok', $openOrdersResult->status);

        $orders = $openOrdersResult->data['orders'] ?? [];
        $found = collect($orders)->firstWhere('order_id', $purchaseId);
        $this->assertNotNull($found, 'Open orders read tool must list the newly created purchase order.');
        $this->assertEquals(10000.0, (float) $found['grand_total']);
    }

    public function test_execution_fails_closed_when_underlying_source_state_changes_after_approval(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $initialPurchasesCount = Purchase::count();

        // 1. Create initial proposal
        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '20.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');

        // 2. Underlying ERP state mutates after proposal creation: product cost changes
        $this->productA->update(['cost' => '320.00']);

        // 3. Execution must fail closed with 422 because live source state hash diverges
        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(422);
        $this->assertStringContainsString('Underlying ERP business state changed', (string) $approveResponse->json('errors.source_state.0'));

        // Zero records created
        $this->assertSame($initialPurchasesCount, Purchase::count());
    }

    public function test_proposals_endpoint_enforces_actor_specific_filtering_and_approval_permission(): void
    {
        // Grant ai.use and purchases-index to restrictedUser so they have AI read access, but NOT ai.action.approve
        $aiUsePermId = DB::table('permissions')->where('name', 'ai.use')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'ai.use', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $purchasesIndexPermId = DB::table('permissions')->where('name', 'purchases-index')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'purchases-index', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('role_has_permissions')->updateOrInsert(
            ['permission_id' => $aiUsePermId, 'role_id' => $this->restrictedUser->role_id]
        );
        DB::table('role_has_permissions')->updateOrInsert(
            ['permission_id' => $purchasesIndexPermId, 'role_id' => $this->restrictedUser->role_id]
        );

        $this->actingAs($this->adminUser, 'sanctum');

        $responseAdmin = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);
        $responseAdmin->assertStatus(201);
        $adminProposalId = $responseAdmin->json('proposal.id');

        // Ordinary user (restrictedUser) viewing proposals only sees their own
        $this->actingAs($this->restrictedUser, 'sanctum');
        $listResponse = $this->withHeaders($this->contextHeaders())->getJson('/api/v1/ai/proposals');
        $listResponse->assertStatus(200);
        $proposals = $listResponse->json('proposals');
        $this->assertEmpty(collect($proposals)->where('id', $adminProposalId));

        // Unauthorized user requesting all=1 is forbidden with 403
        $allForbidden = $this->withHeaders($this->contextHeaders())->getJson('/api/v1/ai/proposals?all=1');
        $allForbidden->assertStatus(403);
        $this->assertSame('UNAUTHORIZED', $allForbidden->json('error_code'));

        // Admin with ai.action.approve can inspect all proposals
        $this->actingAs($this->adminUser, 'sanctum');
        $allAllowed = $this->withHeaders($this->contextHeaders())->getJson('/api/v1/ai/proposals?all=1');
        $allAllowed->assertStatus(200);
        $this->assertNotEmpty(collect($allAllowed->json('proposals'))->where('id', $adminProposalId));
    }

    public function test_tax_rate_or_fiscal_year_modification_fails_closed_with_source_state_validation_exception(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);
        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');

        // Mutate underlying state: update fiscal year updated_at
        DB::table('fiscal_years')->where('id', $this->fiscalYear->id)->update([
            'updated_at' => now()->addMinutes(10),
        ]);

        // Attempting to approve proposal must fail closed with 422 source_state
        $approve = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approve->assertStatus(422);
        $approve->assertJsonValidationErrors(['source_state']);
    }

    public function test_order_grand_total_discrepancy_fails_closed_with_totals_validation_error(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $adapter = app(\App\Ai\Adapters\PurchaseOrderCommandAdapter::class);
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get('purchase_order.create.v1');
        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);

        $hash = \App\Ai\Adapters\PurchaseOrderPreviewAdapter::computeSourceStateHash(
            $context,
            $this->supplier->id,
            $this->warehouse->id,
            [$this->productA->id],
        );

        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'qty' => '10.00',
                    'net_unit_cost' => '250.00',
                    'tax_rate' => '5.00',
                    'purchase_unit_id' => $this->productA->unit_id,
                ],
            ],
            // Discrepancy: Expected total does not match actual domain calculation
            'expected_totals' => [
                'grand_total' => '99999.00',
            ],
        ];

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $adapter->execute(
            $definition,
            $payload,
            $context,
            $this->adminUser->id,
            'test-totals-discrepancy-' . uniqid(),
            $hash,
        );
    }
}
