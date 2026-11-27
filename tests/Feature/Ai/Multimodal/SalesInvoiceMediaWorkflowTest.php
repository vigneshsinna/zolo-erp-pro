<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\DeliveryChallanItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialApplicationService;
use App\Services\Commercial\MaterialDocumentService;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class SalesInvoiceMediaWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $admin;
    private Warehouse $warehouse;
    private Customer $customer;
    private Supplier $supplier;
    private Product $product;
    private Unit $unit;
    private string $companyGstin;
    private string $customerGstin;
    private string $supplierGstin;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-sales-test-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.allow_direct_purchase_bill', true);
        config()->set('ai_multimodal.allow_direct_sales_invoice', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('compliance.enabled', true);

        $this->company = Company::create([
            'legal_name' => 'Optic Wholesale Co ' . uniqid(),
            'trade_name' => 'Optic Wholesale',
            'code' => 'OW' . strtoupper(substr(uniqid(), -5)),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchId = $this->company->branches()->create([
            'name' => 'Main Store',
            'code' => 'MS-' . uniqid(),
            'is_active' => true,
        ])->id;
        $this->fyId = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->companyGstin = \App\Services\Tax\Gstin::make('33OWNCO1234F1Z');
        $this->customerGstin = \App\Services\Tax\Gstin::make('33DEALR1206D1Z');
        $this->supplierGstin = \App\Services\Tax\Gstin::make('27VENDR1206D1Z');

        DB::table('tax_registrations')->insert([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'legal_name' => (string) $this->company->legal_name,
            'trade_name' => (string) $this->company->trade_name,
            'gstin' => $this->companyGstin,
            'state_code' => '33',
            'status' => 'active',
            'registration_type' => 'regular',
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Sales Admin',
            'email' => 'mm_sales_' . uniqid() . '@example.com',
            'phone' => '9844444444',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->admin->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'branch_id' => $this->branchId,
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'name' => 'Store A',
            'address' => 'Chennai',
            'is_active' => true,
        ]);

        DB::table('billers')->insert([
            'company_id' => $this->company->id,
            'name' => 'Main Biller',
            'company_name' => 'Optic Wholesale',
            'email' => 'biller_' . uniqid() . '@example.com',
            'phone_number' => '9800000001',
            'address' => 'Chennai',
            'city' => 'Chennai',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerGroupId = DB::table('customer_groups')->insertGetId([
            'company_id' => $this->company->id,
            'name' => 'Optical Dealers ' . uniqid(),
            'percentage' => '0',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'customer_group_id' => $customerGroupId,
            'name' => 'Vision Care Dealers',
            'phone_number' => '9876511111',
            'email' => 'vision_' . uniqid() . '@example.com',
            'address' => 'Madurai',
            'city' => 'Madurai',
            'tax_no' => $this->customerGstin,
            'is_active' => true,
        ]);
        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->company->id,
                'party_type' => 'customer',
                'party_id' => $this->customer->id,
            ],
            [
                'gstin' => $this->customerGstin,
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Essilor Vendor',
            'phone_number' => '9876522222',
            'email' => 'essilor_' . uniqid() . '@example.com',
            'address' => 'Mumbai',
            'city' => 'Mumbai',
            'vat_number' => $this->supplierGstin,
            'is_active' => true,
        ]);
        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->company->id,
                'party_type' => 'supplier',
                'party_id' => $this->supplier->id,
            ],
            [
                'gstin' => $this->supplierGstin,
                'state_code' => '27',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->unit = Unit::create([
            'company_id' => $this->company->id,
            'unit_code' => 'PCS-' . uniqid(),
            'unit_name' => 'Pieces',
            'is_active' => true,
        ]);
        $cat = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Lenses',
            'is_active' => true,
        ]);
        $taxCatId = DB::table('tax_categories')->insertGetId([
            'company_id' => $this->company->id,
            'name' => 'GST 12%',
            'supply_type' => 'taxable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tax_rates')->insert([
            'company_id' => $this->company->id,
            'tax_category_id' => $taxCatId,
            'rate' => 12.0,
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'SV 1.56 HMC Lens',
            'code' => 'SV156-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $cat->id,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'cost' => '150.00',
            'price' => '300.00',
            'tax_category_id' => $taxCatId,
            'hsn_code' => '9001',
            'is_active' => true,
        ]);
        $this->product->company_id = $this->company->id;
        $this->product->save();

        $accountRoles = [
            'inventory' => 'asset',
            'ap' => 'liability',
            'ar' => 'asset',
            'sales' => 'revenue',
            'cogs' => 'expense',
            'input_tax_cgst' => 'asset',
            'input_tax_sgst' => 'asset',
            'input_tax_igst' => 'asset',
            'output_tax_cgst' => 'liability',
            'output_tax_sgst' => 'liability',
            'output_tax_igst' => 'liability',
            'goods_received_not_invoiced' => 'liability',
            'goods_delivered_not_invoiced' => 'asset',
            'rounding' => 'expense',
        ];
        foreach ($accountRoles as $role => $type) {
            $acc = ChartOfAccount::forceCreate([
                'company_id' => $this->company->id,
                'code' => strtoupper($role) . '_' . uniqid(),
                'name' => $role,
                'type' => $type,
                'sub_type' => $role,
                'is_active' => true,
            ]);
            DB::table('semantic_account_mappings')->updateOrInsert(
                ['company_id' => $this->company->id, 'semantic_role' => $role],
                ['account_id' => $acc->id, 'is_active' => true, 'account_code' => $acc->code, 'account_name' => $role, 'category' => 'core']
            );
        }

        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 250000,
        ]);

        DB::table('document_series')->insert([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
            'document_type' => 'sale',
            'code' => 'MAIN',
            'prefix' => 'INV-',
            'suffix' => '',
            'next_number' => 1,
            'padding' => 6,
            'reset_policy' => 'financial_year',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed opening stock via an authoritative direct purchase so sales have genuine stock
        $ctx = new CompanyContext($this->company->id, $this->branchId, $this->fyId);
        app(CommercialApplicationService::class)->create('purchase', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-11-01',
            'status' => 1,
            'payment_status' => 1,
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 20,
                'purchase_unit_id' => $this->unit->id,
                'net_unit_cost' => 150,
            ]],
        ], 'seed-stock-' . uniqid(), $this->admin->id, $ctx);
    }

    public function test_disabled_direct_sales_invoice_flag_preserves_extraction_without_staging_or_forcing_so(): void
    {
        config()->set('ai_multimodal.allow_direct_sales_invoice', false);

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'sales_invoice',
                    'supplier_name' => $this->company->legal_name,
                    'supplier_gstin' => $this->companyGstin,
                    'buyer_name' => 'Vision Care Dealers',
                    'buyer_gstin' => $this->customerGstin,
                    'invoice_number' => 'OUT-101',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 600,
                    'tax_amount' => 72,
                    'total_amount' => 672,
                    'lines' => [
                        ['description' => 'SV 1.56 HMC Lens', 'quantity' => 2, 'unit_price' => 300, 'line_total' => 600],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'sales_bill.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from this image',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'CAPABILITY_UNAVAILABLE');

        $this->assertStringContainsString('Direct Sales Invoice posting from AI is not enabled yet', (string) $res->json('error_message'));
        $this->assertSame(0, Sale::where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('ai_media_extractions')->where('company_id', $this->company->id)->count());

        @unlink($jpg);
    }

    public function test_direct_sales_invoice_media_happy_path_approves_posts_stock_ar_and_is_idempotent(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'sales_invoice',
                    'supplier_name' => $this->company->legal_name,
                    'supplier_gstin' => $this->companyGstin,
                    'buyer_name' => 'Vision Care Dealers',
                    'buyer_gstin' => $this->customerGstin,
                    'invoice_number' => 'REF-SALE-200',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1500,
                    'tax_amount' => 180,
                    'total_amount' => 1680,
                    'lines' => [
                        ['description' => 'SV 1.56 HMC Lens', 'quantity' => 5, 'unit_price' => 300, 'line_total' => 1500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 80],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'sales_bill.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from the image',
            ])
            ->assertStatus(200);

        $this->assertSame('proposal_created', $res->json('status'));
        $this->assertSame('sales_invoice.create.v1', $res->json('action_key'));
        $this->assertSame('Sales Tax Invoice', $res->json('proposal.preview.document_type'));
        $this->assertSame('direct_invoice', $res->json('proposal.preview.route_kind'));
        $this->assertSame('Vision Care Dealers', $res->json('proposal.preview.customer.name'));
        $this->assertSame('1500.00', $res->json('proposal.preview.totals.subtotal'));
        $this->assertSame('180.00', $res->json('proposal.preview.totals.tax_total'));
        $this->assertSame('1680.00', $res->json('proposal.preview.totals.grand_total'));

        // Zero business writes prior to human approval
        $this->assertSame(0, Sale::where('company_id', $this->company->id)->count());

        $proposalId = $res->json('proposal.proposal_id');

        $app1 = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", ['expected_version' => 1])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('execution.receipt.resource_type', 'sale');

        // Repeated approval click is idempotent
        $app2 = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", ['expected_version' => 1])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');
        $this->assertSame($app1->json('execution.receipt.resource_id'), $app2->json('execution.receipt.resource_id'));

        $this->assertSame(1, Sale::where('company_id', $this->company->id)->count());
        $sale = Sale::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($this->customer->id, (int) $sale->customer_id);
        $this->assertNotSame('REF-SALE-200', $sale->reference_no, 'Official serial must be generated by ERP DocumentNumberService');
        $this->assertSame(1680.0, (float) $sale->grand_total);
        $this->assertSame(180.0, (float) $sale->total_tax);

        // Exactly 1 sale stock movement issuing 5 units (remaining 15)
        $movements = DB::table('stock_movements')
            ->where('company_id', $this->company->id)
            ->where('source_type', 'sale')
            ->where('source_id', $sale->id)
            ->get();
        $this->assertCount(1, $movements);

        $onHand = (float) DB::table('product_warehouse')
            ->where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('qty');
        $this->assertSame(15.0, $onHand);

        // Balanced AR/Revenue/Tax journal posted
        $journals = DB::table('journal_entries')
            ->where('company_id', $this->company->id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->get();
        $this->assertNotEmpty($journals);

        @unlink($jpg);
    }

    public function test_missing_customer_never_falls_back_to_issuer_and_incoming_invoice_triggers_resale_conflict(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'sales_invoice',
                    'supplier_name' => 'Essilor Vendor',
                    'supplier_gstin' => $this->supplierGstin,
                    'buyer_name' => null, // Missing buyer!
                    'invoice_number' => 'ESS-99',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 300,
                    'tax_amount' => 36,
                    'total_amount' => 336,
                    'lines' => [
                        ['description' => 'SV 1.56 HMC Lens', 'quantity' => 2, 'unit_price' => 150, 'line_total' => 300],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'missing_buyer.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from this image',
            ])
            ->assertStatus(200);

        $this->assertSame('needs_input', $res->json('status'));
        $this->assertSame('sales_invoice.create.v1', $res->json('action_key'));
        $this->assertArrayNotHasKey('customer_query', $res->json('normalized_input.slots'));
        $this->assertSame(0, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count());

        @unlink($jpg);
    }

    public function test_incoming_supplier_invoice_resale_reference_blocks_cost_leakage_and_requires_customer(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Essilor Vendor',
                    'supplier_gstin' => $this->supplierGstin,
                    'buyer_name' => $this->company->legal_name,
                    'buyer_gstin' => $this->companyGstin,
                    'invoice_number' => 'SUP-RESALE-1',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 300,
                    'tax_amount' => 36,
                    'total_amount' => 336,
                    'lines' => [
                        ['description' => 'SV 1.56 HMC Lens', 'quantity' => 2, 'unit_price' => 150, 'line_total' => 300],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        // Step 1: Initial sales request on incoming supplier invoice returns source_role_conflict (never purchase review!)
        $conflictRes = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'supplier_doc.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from the image',
            ])
            ->assertStatus(200);

        $this->assertSame('source_role_conflict', $conflictRes->json('status'));
        $this->assertSame('SALES_REQUEST_ON_INCOMING_SUPPLIER_INVOICE', $conflictRes->json('decision.reason_code'));
        $this->assertSame(0, DB::table('ai_workflow_tasks')->where('company_id', $this->company->id)->count());

        // Step 2: Explicit user choice to use as reference for a new sale creates sales draft WITHOUT copying vendor cost (150) as selling rate or active company as customer
        $mediaId = $conflictRes->json('media.id');
        $resaleRes = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/prepare", [
                'companion_text' => 'Create sales bill from the image',
                'resolution_mode' => 'use_as_resale_reference',
            ])
            ->assertStatus(200);

        $this->assertSame('needs_input', $resaleRes->json('status'));
        $this->assertSame('sales_invoice.create.v1', $resaleRes->json('action_key'));
        $this->assertTrue($resaleRes->json('normalized_input.source_provenance.resale_reference_only'));
        $this->assertArrayNotHasKey('net_unit_price', $resaleRes->json('normalized_input.slots.items.0'));
        $this->assertArrayNotHasKey('customer_query', $resaleRes->json('normalized_input.slots'));

        @unlink($jpg);
    }

    public function test_insufficient_stock_already_posted_source_and_unauthorized_role_fail_closed(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'sales_invoice',
                    'supplier_name' => $this->company->legal_name,
                    'supplier_gstin' => $this->companyGstin,
                    'buyer_name' => 'Vision Care Dealers',
                    'buyer_gstin' => $this->customerGstin,
                    'invoice_number' => 'OUT-BIG-QTY',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 15000,
                    'tax_amount' => 1800,
                    'total_amount' => 16800,
                    'lines' => [
                        ['description' => 'SV 1.56 HMC Lens', 'quantity' => 50, 'unit_price' => 300, 'line_total' => 15000],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        // 1. Insufficient stock (50 requested vs 20 available) blocks with 422 and zero writes
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'big_qty.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from this image',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->assertSame(0, Sale::where('company_id', $this->company->id)->count());

        // 2. Unauthorized actor lacking sales-add cannot stage sales invoice
        $permUse = Permission::firstOrCreate(['name' => 'ai.use', 'guard_name' => 'web']);
        $readOnlyRole = Role::firstOrCreate(['name' => 'read_only_ai_' . uniqid(), 'guard_name' => 'web'], ['is_active' => true]);
        $readOnlyRole->givePermissionTo($permUse);
        $limitedUser = User::create([
            'name' => 'Viewer User',
            'email' => 'mm_view_' . uniqid() . '@example.com',
            'phone' => '9855555555',
            'password' => bcrypt('secret'),
            'role_id' => $readOnlyRole->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($limitedUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $limitedUser->id,
            'branch_id' => $this->branchId,
        ]);
        $limitedUser->assignRole($readOnlyRole);

        $this->actingAs($limitedUser)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'unauth_sale.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from this image',
            ])
            ->assertStatus(403);

        @unlink($jpg);
    }

    public function test_already_posted_source_invoice_blocks_duplicate_creation(): void
    {
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];
        $this->actingAs($this->admin);
        $ctx = new CompanyContext($this->company->id, $this->branchId, $this->fyId);
        $postedSale = app(CommercialApplicationService::class)->create('sale', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-11-10',
            'sale_status' => 1,
            'paid_amount' => 0,
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 2,
                'sale_unit_id' => $this->unit->id,
                'net_unit_price' => 300,
            ]],
        ], 'seed-posted-sale-' . uniqid(), $this->admin->id, $ctx);

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'sales_invoice',
                    'supplier_name' => $this->company->legal_name,
                    'supplier_gstin' => $this->companyGstin,
                    'buyer_name' => 'Vision Care Dealers',
                    'buyer_gstin' => $this->customerGstin,
                    'invoice_number' => $postedSale->reference_no,
                    'invoice_date' => '2026-11-10',
                    'subtotal' => 600,
                    'tax_amount' => 72,
                    'total_amount' => 672,
                    'lines' => [
                        ['description' => 'SV 1.56 HMC Lens', 'quantity' => 2, 'unit_price' => 300, 'line_total' => 600],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ], 200),
        ]);

        $jpgDup = $this->makeTempJpeg();
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpgDup, 'dup_sale.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from this image',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NORMALIZED_INPUT_CONFLICT');

        @unlink($jpgDup);
    }

    public function test_dc_linked_sales_invoice_via_media_settles_dispatch_without_duplicate_stock_issue(): void
    {
        $ctx = new CompanyContext($this->company->id, $this->branchId, $this->fyId);
        $dc = DeliveryChallan::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
            'challan_no' => 'DC-TEST-' . uniqid(),
            'challan_date' => '2026-11-11',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'purpose' => 'approval',
            'status' => 'pending',
            'total_qty' => 4,
            'total_amount' => 0,
            'total_tax' => 0,
            'grand_total' => 0,
            'created_by' => $this->admin->id,
        ]);
        DeliveryChallanItem::create([
            'company_id' => $this->company->id,
            'delivery_challan_id' => $dc->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->id,
            'qty' => 4,
            'rate' => 0,
            'amount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);
        app(MaterialDocumentService::class)->post($dc, $ctx, $this->admin->id);

        $onHandAfterDc = (float) DB::table('product_warehouse')
            ->where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('qty');
        $this->assertSame(16.0, $onHandAfterDc);

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'sales_invoice',
                    'supplier_name' => $this->company->legal_name,
                    'supplier_gstin' => $this->companyGstin,
                    'buyer_name' => 'Vision Care Dealers',
                    'buyer_gstin' => $this->customerGstin,
                    'invoice_number' => 'DC-INV-1',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1200,
                    'tax_amount' => 144,
                    'total_amount' => 1344,
                    'lines' => [
                        ['description' => 'SV 1.56 HMC Lens', 'quantity' => 4, 'unit_price' => 300, 'line_total' => 1200],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $prep = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'dc_sale.jpg', 'image/jpeg', null, true),
                'companion_text' => 'Create sales bill from this image',
            ])
            ->assertStatus(200);

        $taskId = $prep->json('task_id');
        // Link DC onto task and regenerate proposal
        $this->actingAs($this->admin)->withSession($sess)
            ->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
                'expected_revision' => 1,
                'corrections' => [
                    ['field' => 'delivery_challan_id', 'value' => $dc->id],
                ],
            ])
            ->assertStatus(200);

        $regen = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/prepare-proposal")
            ->assertSuccessful();

        $this->assertSame('dc_linked', $regen->json('proposal.preview.route_kind'));

        $proposalId = $regen->json('proposal.proposal_id');
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", ['expected_version' => 2])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        // Stock must remain 16.0 (DC already issued the 4 units, no duplicate issue!)
        $onHandAfterInv = (float) DB::table('product_warehouse')
            ->where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('qty');
        $this->assertSame(16.0, $onHandAfterInv);

        @unlink($jpg);
    }

    private function makeTempJpeg(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zolo_sales_' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(120, 80);
        $bg = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, 120, 80, $bg);
        $ink = imagecolorallocate($im, random_int(10, 200), random_int(10, 200), random_int(10, 200));
        imagestring($im, 2, 5, 5, uniqid(), $ink);
        imagejpeg($im, $path, 85);
        imagedestroy($im);

        return $path;
    }
}
