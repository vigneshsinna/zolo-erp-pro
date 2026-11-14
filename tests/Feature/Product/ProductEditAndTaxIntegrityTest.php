<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Models\Company;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\User;
use App\Services\ERP\ProductMasterService;
use App\Services\Platform\CompanyContext;
use App\Services\Tax\GstSetupService;
use App\Services\Tax\TaxDeterminationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comprehensive Feature Regression Test Suite for:
 * 1. Product Edit (Product 693 save, AJAX JSON responses, missing field errors).
 * 2. Company-scoped product code uniqueness.
 * 3. Optical profile vs product capability isolation (no forced optical on shirts).
 * 4. Initial stock persistence and auto-purchase on edit.
 * 5. GST Registration state_code handling in GstSettingsController.
 * 6. Missing customer/supplier party state resolution without silent fallback.
 * 7. Rule-driven Reverse Charge Mechanism (RCM) statutory eligibility.
 * 8. Statutory cross-validation between HSN codes, supply types, and tax categories.
 * 9. Product API v1 endpoints (metadata, store, update).
 */
class ProductEditAndTaxIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected CompanyContext $context;
    protected int $companyId = 80;
    protected int $branchId = 61;
    protected int $financialYearId = 61;
    protected int $productId = 693;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'compliance.enabled' => true,
            'commercial.enabled' => true,
        ]);

        $this->user = User::findOrFail(1);
        $this->context = new CompanyContext(
            companyId: $this->companyId,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId
        );

        $this->actingAs($this->user);
        $this->withSession([
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->financialYearId,
        ]);
        $this->withHeaders([
            'X-Company-ID' => (string) $this->companyId,
            'X-Branch-ID' => (string) $this->branchId,
            'X-Financial-Year-ID' => (string) $this->financialYearId,
        ]);
    }

    /**
     * Scenario 1: Product 693 can be updated with no image attachment and returns structured JSON 200.
     */
    public function test_product_693_can_be_updated_without_image_attachment_and_returns_json_success(): void
    {
        $response = $this->postJson('/products/update', [
            'id' => $this->productId,
            'name' => 'Cotton Shirt Blue',
            'code' => 'SKU-COTTONSHIR',
            'barcode_symbology' => 'C128',
            'type' => 'standard',
            'cost' => 500,
            'price' => 500,
            'unit_id' => 53,
            'category_id' => 214,
            'tax_category_id' => 48, // GST 18%
            'hsn_code' => '6205',     // Mens apparel HSN
            'is_optical' => 0,
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'product' => [
                'id' => $this->productId,
                'name' => 'Cotton Shirt Blue',
                'code' => 'SKU-COTTONSHIR',
            ],
        ]);
        $this->assertNotEmpty($response->json('redirect_url'));

        // Verify optical specifications are cleanly null in the database
        $product = Product::findOrFail($this->productId);
        $this->assertNull($product->base);
        $this->assertNull($product->addition);
        $this->assertNull($product->lr);
        $this->assertNull($product->dia);
        $this->assertNull($product->product_type_id);
        $this->assertSame('C128', $product->barcode_symbology);
        $this->assertSame('6205', $product->hsn_code);
    }

    /**
     * Scenario 2: Missing required fields return HTTP 422 JSON with explicit errors, never 302 redirect.
     */
    public function test_product_update_missing_required_fields_returns_422_with_structured_errors_never_redirect(): void
    {
        $response = $this->postJson('/products/update', [
            'id' => $this->productId,
            'name' => '', // missing name
            'code' => 'SKU-COTTONSHIR',
            'barcode_symbology' => '', // missing symbology
            'type' => 'standard',
            'category_id' => 214,
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
        $response->assertJsonValidationErrors(['name', 'barcode_symbology']);
    }

    /**
     * Scenario 3: Product code uniqueness is strictly scoped to the active company.
     */
    public function test_product_code_uniqueness_is_scoped_to_active_company(): void
    {
        // 1. Create a sibling product in the same company (80)
        $sibling = new Product();
        $sibling->forceFill([
            'company_id' => $this->companyId,
            'name' => 'Existing Co 80 Item',
            'code' => 'SKU-EXIST-80',
            'type' => 'standard',
            'unit_id' => 53,
            'sale_unit_id' => 53,
            'purchase_unit_id' => 53,
            'category_id' => 214,
            'cost' => 100,
            'price' => 120,
            'barcode_symbology' => 'C128',
            'is_active' => true,
        ])->save();

        // Attempting to reuse sibling's code in Company 80 must fail
        $response = $this->postJson('/products/update', [
            'id' => $this->productId,
            'name' => 'Cotton Shirt Blue',
            'code' => 'SKU-EXIST-80', // duplicate in same company
            'barcode_symbology' => 'C128',
            'type' => 'standard',
            'cost' => 500,
            'price' => 500,
            'unit_id' => 53,
            'category_id' => 214,
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);

        // 2. A code used in ANOTHER company (e.g. Company 1) should be ALLOWED in Company 80
        $otherCompanyProduct = new Product();
        $otherCompanyProduct->forceFill([
            'company_id' => 1,
            'name' => 'Other Company Item',
            'code' => 'SKU-OTHER-CO-UNIQUE',
            'type' => 'standard',
            'unit_id' => 53,
            'sale_unit_id' => 53,
            'purchase_unit_id' => 53,
            'category_id' => 214,
            'cost' => 200,
            'price' => 250,
            'barcode_symbology' => 'C128',
            'is_active' => true,
        ])->save();

        $responseOther = $this->postJson('/products/update', [
            'id' => $this->productId,
            'name' => 'Cotton Shirt Blue',
            'code' => 'SKU-OTHER-CO-UNIQUE', // allowed across tenants
            'barcode_symbology' => 'C128',
            'type' => 'standard',
            'cost' => 500,
            'price' => 500,
            'unit_id' => 53,
            'category_id' => 214,
            'tax_category_id' => 48,
            'hsn_code' => '6205',
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $responseOther->assertStatus(200);
        $responseOther->assertJson(['success' => true]);
    }

    /**
     * Scenario 4: Optical toggle defaults OFF and does NOT force optical fields onto garments in optical company.
     */
    public function test_optical_toggle_defaults_off_and_does_not_force_optical_fields_for_ordinary_garment(): void
    {
        // Ensure product 693 has no optical attributes
        Product::where('id', $this->productId)->update([
            'base' => null,
            'addition' => null,
            'lr' => null,
            'dia' => null,
            'product_type_id' => null,
        ]);

        $response = $this->get('/products/' . $this->productId . '/edit');
        $response->assertStatus(200);

        $html = $response->getContent();

        // 1. Barcode symbology pre-selected server-side
        $this->assertStringContainsString('value="C128" selected', $html);

        // 2. Optical specifications section hidden and toggle unchecked
        $this->assertTrue(
            str_contains($html, 'id="optical-container" style="display:none;"') ||
            str_contains($html, 'id="optical-container" style="display: none;"')
        );

        // 3. Validation message containers exist beside mandatory selects
        $this->assertStringContainsString('id="barcode_symbology-error"', $html);
        $this->assertStringContainsString('id="type-error"', $html);

        // 4. Company GST Registration guidance badge is displayed
        $this->assertStringContainsString('Company GST Registration', $html);
    }

    /**
     * Scenario 5: Initial Stock toggle persistence and auto-purchase execution on edit.
     */
    public function test_initial_stock_persistence_and_auto_purchase_on_edit(): void
    {
        $warehouse = DB::table('warehouses')->where('company_id', $this->companyId)->first();
        $this->assertNotNull($warehouse);

        // Remove any existing stock for this product in this warehouse
        Product_Warehouse::where('product_id', $this->productId)
            ->where('warehouse_id', $warehouse->id)
            ->delete();

        // Submit initial stock of 25 units
        $response = $this->postJson('/products/update', [
            'id' => $this->productId,
            'name' => 'Cotton Shirt Blue',
            'code' => 'SKU-COTTONSHIR',
            'barcode_symbology' => 'C128',
            'type' => 'standard',
            'cost' => 500,
            'price' => 500,
            'unit_id' => 53,
            'category_id' => 214,
            'is_initial_stock' => 1,
            'stock_warehouse_id' => [$warehouse->id],
            'stock' => [25],
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertStatus(200);

        // Verify stock entry was created via auto-purchase
        $pw = Product_Warehouse::where('product_id', $this->productId)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertNotNull($pw);
        $this->assertEquals(25.0, (float) $pw->qty);
    }

    /**
     * Scenario 6: GST Registration in GstSettingsController requires and persists state_code.
     */
    public function test_gst_settings_registration_saves_and_validates_state_code(): void
    {
        $setup = app(GstSetupService::class);

        // 1. Valid registration with explicit state_code (33 = Tamil Nadu)
        $setup->registerCompany([
            'gstin' => null,
            'legal_name' => 'Lenz Pvt Ltd',
            'trade_name' => 'Lenz',
            'address' => '123 Cross Cut Road, Coimbatore',
            'registration_type' => 'unregistered',
            'state_code' => '33',
            'effective_from' => '2026-05-01',
        ], $this->context, $this->user->id);

        $reg = DB::table('tax_registrations')->where('company_id', $this->companyId)->latest('id')->first();
        $this->assertSame('33', $reg->state_code);
        $this->assertSame('unregistered', $reg->registration_type);
        $this->assertSame('33', Company::whereKey($this->companyId)->value('state_code'));

        // 2. Missing state_code for unregistered company must throw ValidationException
        $this->expectException(ValidationException::class);
        $setup->registerCompany([
            'gstin' => null,
            'legal_name' => 'Lenz Pvt Ltd',
            'address' => '123 Cross Cut Road',
            'registration_type' => 'unregistered',
            'state_code' => null, // missing state
            'effective_from' => '2026-06-01',
        ], $this->context, $this->user->id);
    }

    /**
     * Scenario 7: Missing customer/supplier party state returns null and fails determination rather than silently assuming company state.
     */
    public function test_missing_party_state_does_not_silently_default_to_company_state(): void
    {
        $setup = app(GstSetupService::class);

        // Party object with NO state and NO gstin
        $party = (object) [
            'id' => 99999,
            'name' => 'Unregistered Cash Customer',
            'tax_no' => null,
            'state' => null,
        ];

        $derived = $setup->deriveParty('customer', $party);
        // Must return null (cannot determine state)
        $this->assertNull($derived);

        // When TaxDeterminationService attempts to prepare with a state-less party, it must throw ValidationException
        $taxService = app(TaxDeterminationService::class);
        $this->expectException(ValidationException::class);

        $taxService->prepare(
            [
                'party_type' => 'customer',
                'party_id' => $party->id,
                'items' => [
                    ['product_id' => $this->productId, 'qty' => 1, 'net_unit_price' => 500],
                ],
            ],
            'sale',
            [$this->productId => Product::findOrFail($this->productId)],
            $party,
            $this->context,
            '2026-11-14'
        );
    }

    /**
     * Scenario 8: Statutory Reverse Charge Mechanism (RCM) eligibility enforcement.
     */
    public function test_reverse_charge_eligibility_enforcement(): void
    {
        $taxService = app(TaxDeterminationService::class);

        // Ensure company has an active Regular GST registration
        DB::table('tax_registrations')->where('company_id', $this->companyId)->where('branch_id', $this->branchId)->update([
            'gstin' => '33AABCL1234F1Z5',
            'legal_name' => 'Lenz Private Limited',
            'registration_type' => 'regular',
            'state_code' => '33',
            'status' => 'active',
            'effective_to' => null,
        ]);

        // 1. Registered supplier supplying standard goods under RCM must be REJECTED (restricted to notified categories)
        $registeredSupplier = (object) [
            'id' => 88881,
            'company_id' => $this->companyId,
            'name' => 'Vendor Corp',
            'vat_number' => '33AAAFL4101K1ZT', // Valid Mod-36 GSTIN checksum
            'state' => 'Tamil Nadu',
        ];

        try {
            $taxService->prepare(
                [
                    'party_type' => 'supplier',
                    'party_id' => $registeredSupplier->id,
                    'gst' => ['reverse_charge' => true], // Attempting RCM on standard goods (Cotton Shirt)
                    'items' => [
                        ['product_id' => $this->productId, 'qty' => 1, 'net_unit_cost' => 400],
                    ],
                ],
                'purchase',
                [$this->productId => Product::findOrFail($this->productId)],
                $registeredSupplier,
                $this->context,
                '2026-11-14'
            );
            $this->fail('Expected ValidationException for non-notified RCM purchase from registered supplier.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('restricted to notified categories', $e->getMessage());
        }

        // 2. Outward sale under RCM to an unregistered recipient must be REJECTED
        $unregisteredCustomer = (object) [
            'id' => 77771,
            'company_id' => $this->companyId,
            'name' => 'Walk-in Buyer',
            'tax_no' => null,
            'state' => 'Tamil Nadu',
        ];

        try {
            $taxService->prepare(
                [
                    'party_type' => 'customer',
                    'party_id' => $unregisteredCustomer->id,
                    'gst' => ['reverse_charge' => true],
                    'items' => [
                        ['product_id' => $this->productId, 'qty' => 1, 'net_unit_price' => 500],
                    ],
                ],
                'sale',
                [$this->productId => Product::findOrFail($this->productId)],
                $unregisteredCustomer,
                $this->context,
                '2026-11-14'
            );
            $this->fail('Expected ValidationException for outward RCM to unregistered customer.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('requires a recipient registered under Regular GST', $e->getMessage());
        }
    }

    /**
     * Scenario 9: Statutory cross-validation between HSN codes, supply types, and tax categories.
     */
    public function test_product_hsn_and_gst_tax_category_cross_validation(): void
    {
        $service = app(ProductMasterService::class);
        $product = Product::findOrFail($this->productId);

        // 1. Non-GST supply category (43) on an apparel HSN (6205) must be REJECTED
        try {
            $service->update($product, [
                'name' => 'Cotton Shirt Blue',
                'code' => 'SKU-COTTONSHIR',
                'type' => 'standard',
                'unit_id' => 53,
                'category_id' => 214,
                'cost' => 500,
                'price' => 500,
                'barcode_symbology' => 'C128',
            ], [
                'tax_category_id' => 43, // Non-GST
                'hsn_code' => '6205',    // Apparel HSN (not Ch 22 or 27)
            ], $this->context);
            $this->fail('Expected ValidationException for Non-GST supply on apparel HSN 6205.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tax_category_id', $e->errors());
            $this->assertStringContainsString('Non-GST supply is only applicable to alcoholic liquor', $e->getMessage());
        }

        // 2. Non-GST supply category (43) on a perfume HSN (3303) must be REJECTED
        try {
            $service->update($product, [
                'name' => 'Cotton Shirt Blue',
                'code' => 'SKU-COTTONSHIR',
                'type' => 'standard',
                'unit_id' => 53,
                'category_id' => 214,
                'cost' => 500,
                'price' => 500,
                'barcode_symbology' => 'C128',
            ], [
                'tax_category_id' => 43, // Non-GST
                'hsn_code' => '3303',    // Perfume HSN
            ], $this->context);
            $this->fail('Expected ValidationException for Non-GST supply on perfume HSN 3303.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tax_category_id', $e->errors());
        }

        // 3. Service SAC code (99xxxx) on standard goods (standard) must be REJECTED
        try {
            $service->update($product, [
                'name' => 'Cotton Shirt Blue',
                'code' => 'SKU-COTTONSHIR',
                'type' => 'standard', // Goods
                'unit_id' => 53,
                'category_id' => 214,
                'cost' => 500,
                'price' => 500,
                'barcode_symbology' => 'C128',
            ], [
                'tax_category_id' => 48,
                'hsn_code' => '998311', // SAC code for consulting
            ], $this->context);
            $this->fail('Expected ValidationException for SAC code on standard goods.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('hsn_code', $e->errors());
            $this->assertStringContainsString('SAC code (starting with 99) is for services', $e->getMessage());
        }
    }

    /**
     * Scenario 10: Product API v1 endpoints (metadata, store, update).
     */
    public function test_product_api_v1_endpoints_metadata_store_and_update(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        // 1. Metadata endpoint
        $metaResponse = $this->getJson('/api/v1/products/metadata');
        $metaResponse->assertStatus(200);
        $metaResponse->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'brands',
                'categories',
                'units',
                'taxes',
                'tax_categories',
                'product_types',
                'warehouses',
                'barcode_symbologies',
                'product_types_allowed',
            ],
        ]);

        // 2. Store endpoint (POST /api/v1/products)
        $uniqueCode = 'API-PROD-' . uniqid();
        $storeResponse = $this->postJson('/api/v1/products', [
            'name' => 'API Created Product',
            'code' => $uniqueCode,
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'unit_id' => 53,
            'category_id' => 214,
            'cost' => 150,
            'price' => 200,
            'tax_category_id' => 48,
            'hsn_code' => '6205',
        ]);

        $storeResponse->assertStatus(201);
        $storeResponse->assertJson([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => [
                'name' => 'API Created Product',
                'code' => $uniqueCode,
            ],
        ]);
        $createdId = $storeResponse->json('data.id');
        $this->assertNotNull($createdId);

        // 3. Update endpoint (PUT /api/v1/products/{id})
        $updateResponse = $this->putJson('/api/v1/products/' . $createdId, [
            'name' => 'API Updated Product Name',
            'code' => $uniqueCode,
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'unit_id' => 53,
            'category_id' => 214,
            'cost' => 175,
            'price' => 225,
            'tax_category_id' => 48,
            'hsn_code' => '6205',
        ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJson([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => [
                'id' => $createdId,
                'name' => 'API Updated Product Name',
                'price' => 225.0,
            ],
        ]);
    }
}
