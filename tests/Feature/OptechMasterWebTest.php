<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Area;
use App\Models\BillSundry;
use App\Models\SaleType;
use App\Models\PurchaseType;
use App\Models\Product;
use App\Models\StandardRemark;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OptechMasterWebTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commercial.enabled' => true]);
        putenv('ERP_OPTIONAL_ACTIVATION_READY=true');
        $_ENV['ERP_OPTIONAL_ACTIVATION_READY'] = 'true';
        \Illuminate\Support\Facades\Cache::flush();
        $this->adminUser = User::first();

        if (!\Illuminate\Support\Facades\DB::table('suppliers')->where('id', 1)->exists()) {
            \Illuminate\Support\Facades\DB::table('suppliers')->insert([
                'id' => 1,
                'company_id' => 1,
                'name' => 'Test Supplier',
                'company_name' => 'Test Supplier Co',
                'phone_number' => '1234567890',
                'email' => 'supplier@test.com',
                'address' => 'Test Address',
                'city' => 'Test City',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_sales_page_loads_with_database_masters_and_the_document_workspace(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/sales?new=1');

        $response->assertStatus(200);
        $response->assertViewHas('saleTypes');
        $response->assertViewHas('agents');
        $response->assertViewHas('areas');
        $response->assertViewHas('billSundries');
        $response->assertViewHas('standardRemarks');
        $response->assertSee('Sales Command Center');
        $response->assertSee('Tax Classification');
        $response->assertSee('charges-drawer');
        $response->assertSee('Charges & remarks', false);
        $response->assertSee('GRAND TOTAL');
        $response->assertSee('id="document-tabs"', false);
        $response->assertSee('js/document-workspace.js', false);
        $response->assertDontSee('Fast Entry');
    }

    public function test_purchase_page_loads_with_database_masters_and_the_document_workspace(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/purchases?new=1');

        $response->assertStatus(200);
        $response->assertViewHas('purchaseTypes');
        $response->assertViewHas('agents');
        $response->assertViewHas('areas');
        $response->assertViewHas('billSundries');
        $response->assertSee('Purchase Command Center');
        $response->assertSee('Supplier Bill No');
        $response->assertSee('charges-drawer');
        $response->assertSee('id="document-tabs"', false);
        $response->assertDontSee('Fast Entry');
    }
    public function test_inline_creation_of_all_optech_masters(): void
    {
        // 1. Inline Agent
        $resAgent = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale/masters/agents', [
                'name' => 'Agent Test Apex',
                'code' => 'AGT-999',
                'phone' => '9876543210',
                'commission_rate' => 2.5,
                'idempotency_key' => 'key-agent-' . uniqid(),
            ]);
        $resAgent->assertStatus(201);
        $this->assertDatabaseHas('agents', ['name' => 'Agent Test Apex']);

        // 2. Inline Area
        $resArea = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale/masters/areas', [
                'name' => 'Area Metro North',
                'code' => 'AREA-NORTH',
                'city' => 'Bengaluru',
                'idempotency_key' => 'key-area-' . uniqid(),
            ]);
        $resArea->assertStatus(201);
        $this->assertDatabaseHas('areas', ['name' => 'Area Metro North']);

        // 3. Inline Bill Sundry
        $resSundry = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale/masters/bill-sundries', [
                'name' => 'Special Handling Charge',
                'nature' => 'sales',
                'calculation_type' => 'percentage',
                'default_value' => 3.5,
                'idempotency_key' => 'key-sundry-' . uniqid(),
            ]);
        $resSundry->assertStatus(201);
        $this->assertDatabaseHas('bill_sundries', ['name' => 'Special Handling Charge']);

        // 4. Inline Sale Type
        $resSaleType = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale/masters/sale-types', [
                'name' => 'GST 12% Special Regional',
                'code' => 'ST-12-REG',
                'tax_nature' => 'local',
                'tax_rate' => 12,
                'idempotency_key' => 'key-saletype-' . uniqid(),
            ]);
        $resSaleType->assertStatus(201);
        $this->assertDatabaseHas('sale_types', ['name' => 'GST 12% Special Regional']);

        // 5. Inline Purchase Type
        $resPurType = $this->actingAs($this->adminUser)
            ->postJson('/commercial/purchase/masters/purchase-types', [
                'name' => 'Interstate Raw Material 18%',
                'code' => 'PT-18-RAW',
                'tax_nature' => 'interstate',
                'tax_rate' => 18,
                'idempotency_key' => 'key-purtype-' . uniqid(),
            ]);
        $resPurType->assertStatus(201);
        $this->assertDatabaseHas('purchase_types', ['name' => 'Interstate Raw Material 18%']);

        // 6. Inline Remark
        $resRemark = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale/masters/remarks', [
                'title' => 'Goods once sold cannot be returned',
                'remark' => 'Goods once sold will strictly not be taken back without approval.',
                'idempotency_key' => 'key-remark-' . uniqid(),
            ]);
        $resRemark->assertStatus(201);
        $this->assertDatabaseHas('standard_remarks', ['title' => 'Goods once sold cannot be returned']);
    }

    public function test_master_management_pages_load(): void
    {
        $routes = [
            '/bill-sundry',
            '/sale-type',
            '/purchase-type',
            '/area',
            '/agent',
            '/standard-remark',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($this->adminUser)->get($route);
            $response->assertStatus(200);
        }
    }

    public function test_sale_posting_persists_optech_sale_type_and_agent(): void
    {
        $this->configurePostingAccounts();
        $saleType = SaleType::where('company_id', 1)->first();
        $agent = Agent::where('company_id', 1)->first();

        $product = Product::forceCreate([
            'company_id' => 1,
            'name' => 'Optech Active Sale Item',
            'code' => 'ITM-SALE-' . uniqid(),
            'type' => 'standard',
            'price' => 100,
            'cost' => 80,
            'unit_id' => 1,
            'sale_unit_id' => 1,
            'purchase_unit_id' => 1,
            'category_id' => 1,
            'barcode_symbology' => 'C128',
            'qty' => 50,
            'is_active' => true,
        ]);

        $purType = PurchaseType::where('company_id', 1)->first();
        $resPur = $this->actingAs($this->adminUser)
            ->postJson('/commercial/purchase', [
                'warehouse_id' => 1,
                'business_date' => now()->toDateString(),
                'supplier_id' => 1,
                'purchase_type_id' => $purType->id,
                'status' => 1,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'qty' => 10,
                        'net_unit_cost' => 80,
                        'purchase_unit_id' => 1,
                    ]
                ],
                'paid_amount' => 0,
                'paying_method' => 'Cash',
                'account_id' => 1,
                'order_discount' => 0,
                'shipping_cost' => 0,
            ], ['Idempotency-Key' => 'test-stock-in-' . uniqid()])->assertCreated();

        $data = [
            'warehouse_id' => 1,
            'business_date' => now()->toDateString(),
            'customer_id' => 1,
            'sale_type_id' => $saleType->id,
            'agent_id' => $agent->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'net_unit_price' => 100,
                    'sale_unit_id' => 1,
                ]
            ],
            'paid_amount' => 0,
            'paying_method' => 'Cash',
            'account_id' => 1,
            'order_discount' => 0,
            'shipping_cost' => 0,
        ];

        $res = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale', $data, ['Idempotency-Key' => 'test-sale-' . uniqid()]);
        $res->assertCreated();
        $saleId = $res->json('data.id');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'sale_type_id' => $saleType->id,
            'agent_id' => $agent->id,
        ]);
    }

    public function test_purchase_posting_persists_optech_purchase_type_and_agent_and_supplier_invoice(): void
    {
        $this->configurePostingAccounts();
        $purType = PurchaseType::where('company_id', 1)->first();
        $agent = Agent::where('company_id', 1)->first();

        $product = Product::forceCreate([
            'company_id' => 1,
            'name' => 'Optech Active Purchase Item',
            'code' => 'ITM-PUR-' . uniqid(),
            'type' => 'standard',
            'price' => 100,
            'cost' => 80,
            'unit_id' => 1,
            'sale_unit_id' => 1,
            'purchase_unit_id' => 1,
            'category_id' => 1,
            'barcode_symbology' => 'C128',
            'qty' => 50,
            'is_active' => true,
        ]);

        $purData = [
            'warehouse_id' => 1,
            'business_date' => now()->toDateString(),
            'supplier_id' => 1,
            'purchase_type_id' => $purType->id,
            'agent_id' => $agent->id,
            'supplier_invoice_no' => 'INV-SUP-9988',
            'supplier_invoice_date' => '2026-10-01',
            'status' => 1,
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 1,
                    'net_unit_cost' => 80,
                    'purchase_unit_id' => 1,
                ]
            ],
            'paid_amount' => 0,
            'paying_method' => 'Cash',
            'account_id' => 1,
            'order_discount' => 0,
            'shipping_cost' => 0,
        ];

        $resPur = $this->actingAs($this->adminUser)
            ->postJson('/commercial/purchase', $purData, ['Idempotency-Key' => 'test-pur-' . uniqid()]);
        $resPur->assertCreated();
        $purId = $resPur->json('data.id');

        $this->assertDatabaseHas('purchases', [
            'id' => $purId,
            'purchase_type_id' => $purType->id,
            'agent_id' => $agent->id,
            'supplier_invoice_no' => 'INV-SUP-9988',
            'supplier_invoice_date' => '2026-10-01',
        ]);
    }

    public function test_document_series_management_page_and_creation(): void
    {
        $res = $this->actingAs($this->adminUser)->get('/document-series');
        $res->assertStatus(200);
        $res->assertSee('Voucher Series');
        $res->assertSee('SALES');

        $uniqueCode = 'RETAIL-' . uniqid();
        $storeRes = $this->actingAs($this->adminUser)->post('/document-series', [
            'document_type' => 'sale',
            'code' => $uniqueCode,
            'prefix' => 'RET-',
            'suffix' => '',
            'next_number' => 101,
            'padding' => 4,
            'reset_policy' => 'financial_year',
        ]);
        $storeRes->assertRedirect(route('document-series.index'));
        $this->assertDatabaseHas('document_series', [
            'company_id' => 1,
            'code' => $uniqueCode,
            'prefix' => 'RET-',
        ]);
    }

    public function test_inline_creation_of_document_series(): void
    {
        $uniqueCode = 'SERIES-INLINE-' . uniqid();
        $res = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale/masters/series', [
                'code' => $uniqueCode,
                'prefix' => 'INL-',
                'next_number' => 1,
                'idempotency_key' => 'key-series-' . uniqid(),
            ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('document_series', [
            'company_id' => 1,
            'code' => $uniqueCode,
            'prefix' => 'INL-',
        ]);
    }

    public function test_sale_posting_persists_transport_and_addins_and_returns_rate_history(): void
    {
        $this->configurePostingAccounts();
        $saleType = SaleType::where('company_id', 1)->first();
        $agent = Agent::where('company_id', 1)->first();

        $product = Product::forceCreate([
            'company_id' => 1,
            'name' => 'Transport Item Test',
            'code' => 'ITM-TRANS-' . uniqid(),
            'type' => 'standard',
            'price' => 150,
            'cost' => 120,
            'unit_id' => 1,
            'sale_unit_id' => 1,
            'purchase_unit_id' => 1,
            'category_id' => 1,
            'barcode_symbology' => 'C128',
            'qty' => 50,
            'is_active' => true,
        ]);

        $purType = PurchaseType::where('company_id', 1)->first();
        $this->actingAs($this->adminUser)
            ->postJson('/commercial/purchase', [
                'warehouse_id' => 1,
                'business_date' => now()->toDateString(),
                'supplier_id' => 1,
                'purchase_type_id' => $purType->id,
                'status' => 1,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'qty' => 10,
                        'net_unit_cost' => 120,
                        'purchase_unit_id' => 1,
                    ]
                ],
                'paid_amount' => 0,
                'paying_method' => 'Cash',
                'account_id' => 1,
                'order_discount' => 0,
                'shipping_cost' => 0,
            ], ['Idempotency-Key' => 'test-stock-in-' . uniqid()])->assertCreated();

        $saleData = [
            'warehouse_id' => 1,
            'business_date' => now()->toDateString(),
            'customer_id' => 1,
            'sale_type_id' => $saleType->id,
            'agent_id' => $agent->id,
            'bale_no' => 'BALE-99',
            'no_of_bales' => 5,
            'lr_no' => 'LR-KPN-555',
            'lr_date' => '2026-10-05',
            'transport_name' => 'KPN Speed Parcel',
            'station_to' => 'Coimbatore',
            'order_no' => 'PO-CUST-101',
            'credit_days' => 45,
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                    'net_unit_price' => 150,
                    'sale_unit_id' => 1,
                ]
            ],
            'paid_amount' => 0,
            'paying_method' => 'Cash',
            'account_id' => 1,
            'order_discount' => 0,
            'shipping_cost' => 0,
        ];

        $resSale = $this->actingAs($this->adminUser)
            ->postJson('/commercial/sale', $saleData, ['Idempotency-Key' => 'test-sale-trans-' . uniqid()]);
        $resSale->assertCreated();
        $saleId = $resSale->json('data.id');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'bale_no' => 'BALE-99',
            'no_of_bales' => 5,
            'lr_no' => 'LR-KPN-555',
            'lr_date' => '2026-10-05',
            'transport_name' => 'KPN Speed Parcel',
            'station_to' => 'Coimbatore',
            'order_no' => 'PO-CUST-101',
            'credit_days' => 45,
        ]);

        // Check previous-rates endpoint returns the posted rate!
        $ratesRes = $this->actingAs($this->adminUser)
            ->getJson('/commercial/sale/previous-rates?customer_id=1&product_id=' . $product->id);
        $ratesRes->assertStatus(200);
        $this->assertEquals(150, (float) $ratesRes->json('last_sale.rate'));
        $this->assertEquals(120, (float) $ratesRes->json('last_purchase.cost'));
    }

    public function test_quick_store_party_creates_supplier_inline(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/parties/quick-store', [
                'party_type' => 'supplier',
                'name' => 'Apex Textiles Supplier',
                'company_name' => 'Apex Spinners Ltd',
                'vat_number' => '33AABCA1234F1Z1',
                'opening_balance' => 0,
                'email' => 'apex@spinners.com',
                'phone_number' => '9842100000',
                'wa_number' => '9842100000',
                'credit_days' => 45,
                'city' => 'Tirupur',
                'address' => '12 Mill Road, Cotton Nagar',
                'state' => 'Tamil Nadu',
                'postal_code' => '641601',
                'country' => 'India',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'party_type' => 'supplier',
        ]);
        $this->assertNotNull($response->json('supplier.id'));
        $this->assertEquals('Apex Textiles Supplier', $response->json('supplier.name'));
        $this->assertEquals('Apex Spinners Ltd', $response->json('supplier.company_name'));
        $this->assertEquals('33AABCA1234F1Z1', $response->json('supplier.vat_number'));

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Apex Textiles Supplier',
            'company_name' => 'Apex Spinners Ltd',
            'city' => 'Tirupur',
            'credit_days' => 45,
        ]);
    }

    public function test_quick_store_party_creates_customer_inline(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/parties/quick-store', [
                'party_type' => 'customer',
                'customer_group_id' => 1,
                'name' => 'Sri Krishna Retailer',
                'company_name' => 'Sri Krishna Silks',
                'vat_number' => '33XYZ1234F1Z5',
                'opening_balance' => 500,
                'email' => 'krishna@silks.com',
                'phone_number' => '9443200000',
                'wa_number' => '9443200000',
                'credit_days' => 30,
                'city' => 'Salem',
                'address' => '45 Bazaar Street',
                'state' => 'Tamil Nadu',
                'postal_code' => '636001',
                'country' => 'India',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'party_type' => 'customer',
        ]);
        $this->assertNotNull($response->json('customer.id'));
        $this->assertEquals('Sri Krishna Retailer', $response->json('customer.name'));
        $this->assertEquals('Sri Krishna Silks', $response->json('customer.company_name'));

        $this->assertDatabaseHas('customers', [
            'name' => 'Sri Krishna Retailer',
            'company_name' => 'Sri Krishna Silks',
            'city' => 'Salem',
            'credit_days' => 30,
        ]);
    }

    public function test_quick_store_party_creates_both_supplier_and_customer(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/parties/quick-store', [
                'party_type' => 'supplier',
                'both' => 1,
                'customer_group_id' => 1,
                'name' => 'Dual Trading Entity',
                'company_name' => 'Dual Enterprises',
                'vat_number' => '33DUAL1234F1Z9',
                'opening_balance' => 0,
                'email' => 'dual@enterprise.com',
                'phone_number' => '9123456789',
                'city' => 'Erode',
                'address' => '88 Market Complex',
                'state' => 'Tamil Nadu',
                'postal_code' => '638001',
                'country' => 'India',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'party_type' => 'both',
        ]);
        $this->assertNotNull($response->json('supplier.id'));
        $this->assertNotNull($response->json('customer.id'));

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Dual Trading Entity',
            'company_name' => 'Dual Enterprises',
        ]);
        $this->assertDatabaseHas('customers', [
            'name' => 'Dual Trading Entity',
            'company_name' => 'Dual Enterprises',
        ]);
    }

    private function configurePostingAccounts(): void
    {
        foreach (\App\Services\Accounting\SemanticAccountResolver::ROLES as $role => [$subType, $types]) {
            \App\Models\Accounting\ChartOfAccount::forceCreate(['company_id' => 1, 'code' => 'TEST-POST-'.$role,
                'name' => 'Test '.$role, 'type' => is_array($types) ? $types[0] : $types,
                'sub_type' => $subType, 'is_active' => true, 'is_system' => true,
            ]);
        }
        app(\App\Services\Accounting\SemanticAccountResolver::class)->seedCompany(1);
    }

    public function test_gst_lookup_decodes_statutory_gstin_and_state(): void
    {
        // 33AIUPN6412D1ZA -> Tamil Nadu, PAN: AIUPN6412D, Proprietorship
        $response = $this->actingAs($this->adminUser)
            ->postJson('/parties/gst-lookup', [
                'gstin' => '33AIUPN6412D1ZA',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'gstin' => '33AIUPN6412D1ZA',
            'is_valid' => true,
            'state_code' => '33',
            'state_name' => 'Tamil Nadu',
            'pan' => 'AIUPN6412D',
            'constitution' => 'Proprietorship / Individual',
            'is_local' => true,
            'tax_rule' => 'Local Intra-State (CGST + SGST)',
        ]);
    }

    public function test_gst_lookup_recalls_existing_party_from_database(): void
    {
        // Pre-create supplier with a GSTIN
        \App\Models\Supplier::create([
            'company_id' => 1,
            'name' => 'Ravi Textiles',
            'company_name' => 'Ravi Silk Mills',
            'vat_number' => '33AABCR1234F1Z8',
            'phone_number' => '9842100000',
            'email' => 'ravi@textiles.com',
            'address' => '12 Mill Road',
            'city' => 'Coimbatore',
            'state' => 'Tamil Nadu',
            'postal_code' => '641001',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/parties/gst-lookup', [
                'gstin' => '33AABCR1234F1Z8',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_existing' => true,
            'party' => [
                'name' => 'Ravi Textiles',
                'company_name' => 'Ravi Silk Mills',
                'city' => 'Coimbatore',
            ],
        ]);
    }
}


