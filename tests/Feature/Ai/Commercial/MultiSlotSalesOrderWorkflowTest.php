<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionExecutionService;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\RecordResolutionService;
use App\Ai\Tools\PrepareSalesOrder;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Validates the Multi-Slot Sales Order Intake, Resolution, Clarification,
 * Proposal Generation, and Governed Execution via OrderLifecycleService::createSale.
 * Tests strict compliance with:
 * - Tenancy Law 1: Zero unscoped reads/writes.
 * - Single-Pass Batch Record Resolution: Customer, Warehouse, Products, and UOMs.
 * - Clarification Card: Consolidated feedback with Three-Way Disambiguation Triad.
 * - Non-blocking Stock Advisory: Low stock warns without failing closed.
 * - Untrusted Model, Authoritative Server: Zero autonomous mutations before human approval.
 */
final class MultiSlotSalesOrderWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private Company $companyA;
    private Company $companyB;
    private CompanyBranch $branchA;
    private FiscalYear $fiscalYearA;
    private Warehouse $warehouseA;
    private Customer $customerAlphaApparels;
    private Customer $customerAlphaFashion;
    private Product $productFabric;
    private Product $productThread;
    private Unit $unitMeter;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Sales Admin User',
            'email' => 'sales_admin_' . uniqid() . '@example.com',
            'phone' => '9777777790',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        // Company A Setup
        $this->companyA = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Vardhman Mills Ltd',
            'legal_name' => 'Vardhman Mills Ltd',
            'code' => 'VARDHMAN_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false);

        $this->branchA = $this->companyA->branches()->where('code', 'MAIN')->first()
            ?? $this->companyA->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'branch_type' => 'branch']);

        $this->fiscalYearA = $this->companyA->fiscalYears()->first()
            ?? $this->companyA->fiscalYears()->create([
                'name' => 'FY 2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open',
                'is_closed' => false,
            ]);

        $this->companyA->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branchA->id]
        );

        $this->warehouseA = Warehouse::where('company_id', $this->companyA->id)->where('branch_id', $this->branchA->id)->first()
            ?? Warehouse::create([
                'name' => 'Finished Goods Warehouse',
                'address' => '88 Textile Park, Tiruppur',
                'is_active' => true,
                'company_id' => $this->companyA->id,
                'branch_id' => $this->branchA->id,
            ]);

        // Unit & Category
        $this->unitMeter = Unit::where('company_id', $this->companyA->id)->where('unit_code', 'MTR')->first()
            ?? Unit::forceCreate(['unit_code' => 'MTR', 'unit_name' => 'Meter', 'company_id' => $this->companyA->id, 'is_active' => true]);

        $category = Category::where('company_id', $this->companyA->id)->first()
            ?? Category::forceCreate(['name' => 'Finished Garments', 'company_id' => $this->companyA->id, 'is_active' => true]);

        $taxCatId = DB::table('tax_categories')->where('company_id', $this->companyA->id)->where('name', 'GST 5%')->value('id')
            ?? DB::table('tax_categories')->insertGetId([
                'company_id' => $this->companyA->id,
                'name' => 'GST 5%',
                'supply_type' => 'taxable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('tax_rates')->updateOrInsert(
            ['company_id' => $this->companyA->id, 'tax_category_id' => $taxCatId],
            [
                'rate' => 5.0,
                'effective_from' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Products for Company A
        $this->productFabric = Product::where('company_id', $this->companyA->id)->where('code', 'FAB-COTTON-01')->first()
            ?? Product::forceCreate([
                'name' => 'Cotton Cambric 60s',
                'code' => 'FAB-COTTON-01',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => '120.00',
                'price' => '180.00',
                'unit_id' => $this->unitMeter->id,
                'purchase_unit_id' => $this->unitMeter->id,
                'sale_unit_id' => $this->unitMeter->id,
                'category_id' => $category->id,
                'tax_category_id' => $taxCatId,
                'hsn_code' => '5208',
                'company_id' => $this->companyA->id,
                'is_active' => true,
            ]);

        $this->productThread = Product::where('company_id', $this->companyA->id)->where('code', 'THRD-SEW-01')->first()
            ?? Product::forceCreate([
                'name' => 'Sewing Thread Spool',
                'code' => 'THRD-SEW-01',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => '35.00',
                'price' => '60.00',
                'unit_id' => $this->unitMeter->id,
                'purchase_unit_id' => $this->unitMeter->id,
                'sale_unit_id' => $this->unitMeter->id,
                'category_id' => $category->id,
                'tax_category_id' => $taxCatId,
                'hsn_code' => '5401',
                'company_id' => $this->companyA->id,
                'is_active' => true,
            ]);

        // Customer Group
        $customerGroupId = (int) (DB::table('customer_groups')->where('company_id', $this->companyA->id)->value('id') ?? 1);

        // Ambiguous Customers in Company A
        $validGstin = $this->gstin('33');
        $this->customerAlphaApparels = Customer::forceCreate([
            'name' => 'Alpha Apparels Hub',
            'company_name' => 'Alpha Apparels Pvt Ltd',
            'phone_number' => '9840011111',
            'tax_no' => $validGstin,
            'address' => '10 Garment Ave, Tiruppur',
            'city' => 'Tiruppur',
            'state' => 'Tamil Nadu',
            'is_active' => true,
            'company_id' => $this->companyA->id,
            'customer_group_id' => $customerGroupId,
        ]);

        $this->customerAlphaFashion = Customer::forceCreate([
            'name' => 'Alpha Fashion Retailers',
            'company_name' => 'Alpha Fashion Ltd',
            'phone_number' => '9840022222',
            'tax_no' => $validGstin,
            'address' => '20 Fashion Street, Chennai',
            'city' => 'Chennai',
            'state' => 'Tamil Nadu',
            'is_active' => true,
            'company_id' => $this->companyA->id,
            'customer_group_id' => $customerGroupId,
        ]);

        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->companyA->id,
                'party_type' => 'customer',
                'party_id' => $this->customerAlphaApparels->id,
            ],
            [
                'gstin' => $validGstin,
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->companyA->id,
                'party_type' => 'customer',
                'party_id' => $this->customerAlphaFashion->id,
            ],
            [
                'gstin' => $validGstin,
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Isolated Company B for Tenancy Isolation Verification
        $this->companyB = Company::create([
            'name' => 'Foreign Corp Ltd',
            'legal_name' => 'Foreign Corp Ltd',
            'code' => 'FOREIGN_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '27',
        ]);
    }

    private function gstin(string $state = '33'): string
    {
        $prefix = $state . 'ABCDE1234F1Z';
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $factor = 2;
        $sum = 0;
        for ($i = 13; $i >= 0; $i--) {
            $n = strpos($alphabet, $prefix[$i]) * $factor;
            $sum += intdiv($n, 36) + $n % 36;
            $factor = $factor === 2 ? 1 : 2;
        }
        return $prefix . $alphabet[(36 - $sum % 36) % 36];
    }

    private function contextHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->companyA->id,
            'X-Branch-Id' => (string) $this->branchA->id,
            'X-Financial-Year-Id' => (string) $this->fiscalYearA->id,
        ];
    }

    private function principal(int $companyId = 0): AiPrincipal
    {
        $cid = $companyId ?: $this->companyA->id;
        $bid = $companyId ? 1 : $this->branchA->id;
        $fid = $companyId ? 1 : $this->fiscalYearA->id;

        return new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $cid,
            branchId: $bid,
            financialYearId: $fid,
            permittedToolNames: ['sales_order.create.v1', 'stock_availability', 'party_balance'],
            requestId: 'test-' . uniqid(),
            effectiveRole: 'Admin',
        );
    }

    /**
     * Test 1: Ambiguous Customer Query returns a Consolidated Clarification Card
     * containing candidates and the Three-Way Disambiguation Triad, while simultaneously
     * retaining line items in confirmed_fields.
     */
    public function test_ambiguous_customer_presents_disambiguation_options_with_triad(): void
    {
        $tool = app(PrepareSalesOrder::class, ['principal' => $this->principal()]);

        // User says: "Create a sales order for Alpha for 50 meters of Cotton Cambric 60s"
        $rawResponse = $tool->handle(new ToolRequest([
            'customer_query' => 'Alpha', // Ambiguous: matches Alpha Apparels and Alpha Fashion
            'warehouse_id' => $this->warehouseA->id,
            'items' => [
                [
                    'product_query' => 'Cotton Cambric 60s',
                    'qty' => 50,
                    'net_unit_price' => 180.0,
                ],
            ],
        ]));

        $response = json_decode($rawResponse, true);

        // Must return needs_input without early failure
        $this->assertSame('needs_input', $response['status']);
        $this->assertSame('clarification_card', $response['presentation'] ?? 'clarification_card');
        $this->assertNotEmpty($response['task_id']);

        // Check required_inputs has customer_id with candidates and resolution options
        $customerIssue = collect($response['required_inputs'])->firstWhere('field', 'customer_id');
        $this->assertNotNull($customerIssue);
        $this->assertSame('ambiguous', $customerIssue['status']);
        $this->assertCount(2, $customerIssue['candidates']);

        // Check Three-Way Disambiguation Triad
        $this->assertNotEmpty($customerIssue['resolution_options']);
        $triadKeys = array_column($customerIssue['resolution_options'], 'key');
        $this->assertContains('select_existing', $triadKeys);
        $this->assertContains('create_new', $triadKeys);
        $this->assertContains('correct_name', $triadKeys);

        // Check that line items are NOT dropped: product was resolved and preserved
        $confirmed = collect($response['confirmed_fields'] ?? [])->keyBy('field');
        $this->assertTrue($confirmed->has('items[line-0].product_id'));
        $this->assertStringContainsString('FAB-COTTON-01', $confirmed->get('items[line-0].product_id')['display']);
        $this->assertStringContainsString('50.00', $confirmed->get('items[line-0].qty')['display']);
    }

    /**
     * Test 2: Low-stock advisory warning surfaces non-blockingly without stopping workflow.
     */
    public function test_low_stock_advisory_warning_surfaces_non_blockingly(): void
    {
        $tool = app(PrepareSalesOrder::class, ['principal' => $this->principal()]);

        // Provide exact customer and warehouse, but request 100,000 meters when stock is 0
        $rawResponse = $tool->handle(new ToolRequest([
            'customer_query' => $this->customerAlphaApparels->name,
            'warehouse_id' => $this->warehouseA->id,
            'items' => [
                [
                    'product_query' => $this->productFabric->name,
                    'qty' => 100000,
                    'net_unit_price' => 180.0,
                ],
            ],
        ]));

        $response = json_decode($rawResponse, true);

        // Because all required inputs are present, proposal is prepared even with low stock warning
        $this->assertSame('ok', $response['status']);
        $this->assertNotNull($response['data']['proposal_id']);
        $this->assertSame('sales_order.create.v1', $response['data']['action_key']);

        // Non-blocking advisory check in data
        $this->assertArrayHasKey('stock_availability', $response['data']);
        $this->assertFalse($response['data']['stock_availability']['has_sufficient_stock']);
        $this->assertNotEmpty($response['data']['stock_availability']['stock_issues']);
    }

    /**
     * Test 3: Proposal preparation creates governed action proposal with Zero ERP ledger writes.
     */
    public function test_proposal_preparation_creates_governed_action_proposal_without_erp_mutations(): void
    {
        $salesOrderCountBefore = SalesOrder::where('company_id', $this->companyA->id)->count();
        $salesCountBefore = DB::table('sales')->where('company_id', $this->companyA->id)->count();

        $tool = app(PrepareSalesOrder::class, ['principal' => $this->principal()]);

        $rawResponse = $tool->handle(new ToolRequest([
            'customer_query' => $this->customerAlphaApparels->name,
            'warehouse_id' => $this->warehouseA->id,
            'items' => [
                [
                    'product_query' => 'Cotton Cambric 60s',
                    'qty' => 20,
                    'net_unit_price' => 180.0,
                ],
                [
                    'product_query' => 'Sewing Thread Spool',
                    'qty' => 10,
                    'net_unit_price' => 60.0,
                ],
            ],
            'note' => 'Deliver by express road freight',
        ]));

        $response = json_decode($rawResponse, true);

        $this->assertSame('ok', $response['status']);
        $proposalId = $response['data']['proposal_id'];
        $proposal = DB::table('ai_action_proposals')->where('id', $proposalId)->first();
        $this->assertNotNull($proposal);
        $this->assertSame('sales_order.create.v1', $proposal->action_key);
        $this->assertSame('pending', $proposal->status);
        $this->assertSame(1, (int) $proposal->version);

        // Check proposal preview numbers match commercial calculations
        $preview = json_decode((string) $proposal->preview_json, true);
        $this->assertSame($this->customerAlphaApparels->name, $preview['customer']['name']);
        $this->assertCount(2, $preview['lines']);

        // Invariant: Zero ERP mutations before human approval
        $this->assertSame($salesOrderCountBefore, SalesOrder::where('company_id', $this->companyA->id)->count());
        $this->assertSame($salesCountBefore, DB::table('sales')->where('company_id', $this->companyA->id)->count());
    }

    /**
     * Test 4: Approving Sales Order proposal executes via SalesOrderCommandAdapter,
     * reserving document number and creating authoritative SalesOrder record.
     */
    public function test_approving_sales_order_proposal_executes_via_command_adapter(): void
    {
        $tool = app(PrepareSalesOrder::class, ['principal' => $this->principal()]);

        $rawResponse = $tool->handle(new ToolRequest([
            'customer_query' => $this->customerAlphaApparels->name,
            'warehouse_id' => $this->warehouseA->id,
            'items' => [
                [
                    'product_query' => 'Cotton Cambric 60s',
                    'qty' => 15,
                    'net_unit_price' => 180.0,
                ],
            ],
        ]));

        $response = json_decode($rawResponse, true);
        $proposalId = $response['data']['proposal_id'];

        $approveResponse = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
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
        $orderId = $execution['receipt']['resource_id'];
        $this->assertGreaterThan(0, $orderId);

        // Verify SalesOrder was created in ERP database
        $createdOrder = SalesOrder::where('company_id', $this->companyA->id)->find($orderId);
        $this->assertNotNull($createdOrder);
        $this->assertSame($this->customerAlphaApparels->id, $createdOrder->customer_id);
        $this->assertSame($this->warehouseA->id, $createdOrder->warehouse_id);
        $this->assertNotEmpty($createdOrder->reference_no);

        // Test Idempotency: re-execution with same proposal returns same receipt
        $repeatResponse = $this->actingAs($this->adminUser)
            ->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);
        $repeatResponse->assertStatus(200);
        $this->assertSame($orderId, $repeatResponse->json('execution.receipt.resource_id'));
    }

    /**
     * Test 5: Strict Tenancy Law 1 - Company B customer cannot be resolved or accessed by Company A actor.
     */
    public function test_strict_tenancy_cross_company_isolation(): void
    {
        // Create customer in isolated Company B
        $foreignCustomer = Customer::forceCreate([
            'name' => 'Foreign Exclusive Buyer',
            'company_name' => 'Foreign Exclusive Pvt Ltd',
            'phone_number' => '9999911111',
            'tax_no' => $this->gstin('27'),
            'address' => '50 Marine Drive, Mumbai',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'is_active' => true,
            'company_id' => $this->companyB->id,
            'customer_group_id' => 1,
        ]);

        $resolver = app(RecordResolutionService::class);
        $contextA = new CompanyContext($this->companyA->id, $this->branchA->id, $this->fiscalYearA->id);

        // Company A resolver cannot see Company B customer
        $result = $resolver->resolveCustomer($contextA, 'Foreign Exclusive Buyer');
        $this->assertSame('none', $result['status']);
        $this->assertNull($result['customer']);
        $this->assertEmpty($result['candidates']);

        // PrepareSalesOrder for Company B customer by Company A actor treats customer as missing/unresolved
        $tool = app(PrepareSalesOrder::class, ['principal' => $this->principal()]);
        $rawResponse = $tool->handle(new ToolRequest([
            'customer_query' => 'Foreign Exclusive Buyer',
            'warehouse_id' => $this->warehouseA->id,
            'items' => [
                ['product_query' => 'Cotton Cambric 60s', 'qty' => 10, 'net_unit_price' => 180.0],
            ],
        ]));

        $response = json_decode($rawResponse, true);
        $this->assertSame('needs_input', $response['status']);
        $custIssue = collect($response['required_inputs'])->firstWhere('field', 'customer_id');
        $this->assertNotNull($custIssue);
        $this->assertSame('missing', $custIssue['status']);
    }
}
