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

        // Every row can be edited from the page; an unused series takes a new format.
        $id = \Illuminate\Support\Facades\DB::table('document_series')->where('company_id', 1)->where('code', strtoupper($uniqueCode))->value('id');
        $this->actingAs($this->adminUser)->get('/document-series')->assertOk()->assertSee('series-edit', false)->assertSee('RET-0101');
        $this->actingAs($this->adminUser)->put('/document-series/'.$id, ['prefix' => 'RT/', 'suffix' => '', 'padding' => 3, 'next_number' => 5])
            ->assertSessionHasNoErrors()->assertRedirect(route('document-series.index'));
        $this->assertDatabaseHas('document_series', ['id' => $id, 'prefix' => 'RT/', 'padding' => 3, 'next_number' => 5]);
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

    public function test_under_gst_a_party_with_a_malformed_gstin_is_refused(): void
    {
        // A malformed GSTIN would make the party silently unregistered: no GST on any of its bills.
        config(['compliance.enabled' => true]);
        if (!\App\Services\Tax\GstSetupService::available()) $this->markTestSkipped('GST tables are not installed.');
        $party = ['party_type' => 'supplier', 'name' => 'Short GSTIN Supplier', 'company_name' => 'Short GSTIN Traders',
            'phone_number' => '9842100099', 'address' => '1 Mill Road', 'city' => 'Erode', 'country' => 'India'];
        $this->actingAs($this->adminUser)->postJson('/parties/quick-store', $party + ['vat_number' => '33BAGP6366L1ZN'])
            ->assertStatus(422)->assertJsonValidationErrors('vat_number');
        $this->actingAs($this->adminUser)->postJson('/parties/quick-store', $party + ['vat_number' => '33ABCFS7714N1ZV'])->assertOk();
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
        // The opening due is a posted open item on Debtors, not an unposted "Opening balance" sale.
        $customerId = $response->json('customer.id');
        $entry = \App\Models\Accounting\JournalEntry::withoutGlobalScopes()->where('reference_type', 'customer_opening')
            ->where('reference_id', $customerId)->sole();
        $this->assertEquals(500, $entry->total_debit);
        $this->assertEquals(500, \App\Models\Accounting\AccountOpenItem::withoutGlobalScopes()->where('party_type', 'customer')
            ->where('party_id', $customerId)->value('open_amount'));
        $this->assertDatabaseMissing('sales', ['customer_id' => $customerId]);
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
        \App\Models\Company::where('id', 1)->update(['state_code' => '33']);
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

    public function test_gst_lookup_fetches_and_normalizes_from_gstinapi_provider(): void
    {
        \Illuminate\Support\Facades\Cache::forget('gst-provider:33AAACC1206D1ZN');
        \Illuminate\Support\Facades\Http::fake([
            '*/v1/gstin/33AAACC1206D1ZN' => \Illuminate\Support\Facades\Http::response([
                'success' => true,
                'gstin' => '33AAACC1206D1ZN',
                'data' => [
                    'gstin' => '33AAACC1206D1ZN',
                    'legal_name' => 'CENTRAL WAREHOUSING CORPORATION',
                    'trade_name' => 'CENTRAL WAREHOUSING CORPORATION',
                    'status' => 'Active',
                    'address' => [
                        'building_no' => 'No. 4',
                        'street' => 'Safdarjung Enclave',
                        'location' => 'New Delhi',
                        'district' => 'South West',
                        'city' => 'New Delhi',
                        'state' => 'Tamil Nadu',
                        'pincode' => '600001',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/parties/gst-lookup', [
                'gstin' => '33AAACC1206D1ZN',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_existing' => true,
            'party' => [
                'name' => 'CENTRAL WAREHOUSING CORPORATION',
                'company_name' => 'CENTRAL WAREHOUSING CORPORATION',
                'address' => 'No. 4, Safdarjung Enclave, New Delhi, South West',
                'city' => 'New Delhi',
                'state' => 'Tamil Nadu',
                'postal_code' => '600001',
                'registration_status' => 'Active',
                'source' => 'live_api',
            ],
        ]);
    }

    public function test_gst_lookup_captures_provider_error_message(): void
    {
        \Illuminate\Support\Facades\Cache::forget('gst-provider:33AAACC1206D1ZN');
        \Illuminate\Support\Facades\Http::fake([
            '*/v1/gstin/33AAACC1206D1ZN' => \Illuminate\Support\Facades\Http::response([
                'error' => 'Your free lookup credits are waiting — confirm your email address to unlock them.',
                'code' => 'email_unverified_credits_held',
            ], 402),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/parties/gst-lookup', [
                'gstin' => '33AAACC1206D1ZN',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'is_existing' => false,
            'party' => null,
            'provider_message' => 'Your free lookup credits are waiting — confirm your email address to unlock them.',
        ]);
    }

    public function test_party_forms_offer_gstin_autofill(): void
    {
        foreach (['/supplier/create', '/supplier/1/edit', '/customer/create'] as $url) {
            $this->actingAs($this->adminUser)->get($url)
                ->assertStatus(200)
                ->assertSee('GSTIN / ', false)
                ->assertSee(route('parties.gst-lookup'), false);
        }
    }

    public function test_supplier_saves_with_only_name_and_phone(): void
    {
        $this->actingAs($this->adminUser)->post('/supplier', [
            'name' => 'Minimal Supplier', 'phone_number' => '9000000001',
        ])->assertRedirect('supplier');

        $this->assertDatabaseHas('suppliers', ['name' => 'Minimal Supplier', 'address' => null, 'city' => null]);
    }

    public function test_gst_lookup_fills_from_provider_when_local_match_has_no_address(): void
    {
        config(['compliance.gst_lookup_url' => 'https://provider.test/v1/gstin', 'compliance.gst_lookup_token' => 'gak_test',
            'compliance.gst_lookup_header' => 'x-api-key']);
        \Illuminate\Support\Facades\Http::fake(['provider.test/*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => [
            'gstin' => '33AAACC1206D1ZN', 'legal_name' => 'CENTRAL WAREHOUSING CORPORATION', 'trade_name' => 'CWC',
            'address' => '12 MOUNT ROAD, CHENNAI', 'city' => 'CHENNAI', 'pincode' => '600002', 'status' => 'Active',
        ]])]);
        Supplier::where('id', 1)->update(['vat_number' => '33AAACC1206D1ZN', 'address' => null]);

        $this->actingAs($this->adminUser)->postJson(route('parties.gst-lookup'), ['gstin' => '33AAACC1206D1ZN'])
            ->assertOk()
            ->assertJsonPath('party.source', 'live_api')
            ->assertJsonPath('party.company_name', 'CWC')
            ->assertJsonPath('party.address', '12 MOUNT ROAD, CHENNAI')
            ->assertJsonPath('party.postal_code', '600002')
            ->assertJsonPath('party.state', 'Tamil Nadu')
            ->assertJsonPath('party.registration_status', 'Active');
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'gak_test')
            && $request->url() === 'https://provider.test/v1/gstin/33AAACC1206D1ZN');
    }

    private function fakeMastersIndia(): void
    {
        config(['compliance.mastersindia' => ['username' => 'u@test', 'password' => 'p', 'client_id' => 'cid', 'client_secret' => 'sec']]);
        $registration = ['gstin' => '33AAACC1206D1ZN', 'lgnm' => 'CENTRAL WAREHOUSING CORPORATION', 'tradeNam' => 'CWC CHENNAI', 'sts' => 'Active',
            'pradr' => ['addr' => ['bno' => '12', 'st' => 'MOUNT ROAD', 'loc' => 'GUINDY', 'dst' => 'Chennai', 'city' => '', 'stcd' => 'Tamil Nadu', 'pncd' => '600032']]];
        \Illuminate\Support\Facades\Http::fake([
            'commonapi.mastersindia.co/oauth/access_token' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok', 'expires_in' => 21600]),
            'commonapi.mastersindia.co/commonapis/searchgstin*' => \Illuminate\Support\Facades\Http::response(['error' => false, 'data' => $registration]),
            'commonapi.mastersindia.co/commonapis/searchlegalname*' => \Illuminate\Support\Facades\Http::response(['error' => false, 'data' => [$registration]]),
        ]);
    }

    public function test_masters_india_fills_party_from_gstin(): void
    {
        $this->fakeMastersIndia();

        $this->actingAs($this->adminUser)->postJson(route('parties.gst-lookup'), ['gstin' => '33AAACC1206D1ZN'])
            ->assertOk()
            ->assertJsonPath('party.source', 'live_api')
            ->assertJsonPath('party.name', 'CENTRAL WAREHOUSING CORPORATION')
            ->assertJsonPath('party.company_name', 'CWC CHENNAI')
            ->assertJsonPath('party.address', '12, MOUNT ROAD, GUINDY')
            ->assertJsonPath('party.city', 'Chennai')
            ->assertJsonPath('party.postal_code', '600032');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'searchgstin?gstin=33AAACC1206D1ZN')
            && $r->hasHeader('client_id', 'cid') && $r->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_company_name_search_returns_gstin_candidates(): void
    {
        $this->fakeMastersIndia();

        $this->actingAs($this->adminUser)->postJson(route('parties.gst-search-name'), ['name' => 'Central Warehousing'])
            ->assertOk()
            ->assertJsonPath('provider', true)
            ->assertJsonPath('matches.0.gstin', '33AAACC1206D1ZN')
            ->assertJsonPath('matches.0.status', 'Active');

        $this->actingAs($this->adminUser)->postJson(route('parties.gst-search-name'), ['name' => 'ab'])->assertStatus(422);
    }
}
