<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class NormalizedInputWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $admin;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-workflow-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.allow_direct_purchase_bill', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('compliance.enabled', true);

        $this->company = Company::create([
            'legal_name' => 'Workflow Bridge Co ' . uniqid(),
            'trade_name' => 'Workflow Bridge',
            'code' => 'WFB-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchId = $this->company->branches()->create([
            'name' => 'Bridge Branch',
            'code' => 'BR-WFB-' . uniqid(),
            'is_active' => true,
        ])->id;
        $this->fyId = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        DB::table('tax_registrations')->insert([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'legal_name' => (string) $this->company->legal_name,
            'trade_name' => (string) $this->company->trade_name,
            'gstin' => '33ABCDE1234F1Z7',
            'state_code' => '33',
            'status' => 'active',
            'registration_type' => 'regular',
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Bridge Admin',
            'email' => 'mm_wf_' . uniqid() . '@example.com',
            'phone' => '9833333333',
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
            'name' => 'Main Warehouse',
            'address' => 'Chennai',
            'is_active' => true,
        ]);

        $this->supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Anver Mills',
            'phone_number' => '9876500001',
            'email' => 'anver_' . uniqid() . '@example.com',
            'address' => 'Surat',
            'city' => 'Surat',
            'vat_number' => '33AAACC1206D1ZN',
            'is_active' => true,
        ]);

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

        $unit = Unit::create([
            'company_id' => $this->company->id,
            'unit_code' => 'PCS-' . uniqid(),
            'unit_name' => 'Pieces',
            'is_active' => true,
        ]);
        $cat = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Optical Frames',
            'is_active' => true,
        ]);

        $taxCatId = DB::table('tax_categories')->insertGetId([
            'company_id' => $this->company->id,
            'name' => 'GST 5%',
            'supply_type' => 'taxable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tax_rates')->insert([
            'company_id' => $this->company->id,
            'tax_category_id' => $taxCatId,
            'rate' => 5.0,
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hsn_sac_codes')->insert([
            'company_id' => $this->company->id,
            'code' => '5208',
            'description' => 'Cotton fabric',
            'kind' => 'hsn',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Blue Fabric Frame',
            'code' => 'BFF-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '150.00',
            'price' => '250.00',
            'tax_category_id' => $taxCatId,
            'hsn_code' => '5208',
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
            'goods_received_not_invoiced' => 'liability',
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
    }

    public function test_supplier_invoice_automatic_preparation_and_approved_execution_journey(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PB-100',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1500,
                    'tax_amount' => 75,
                    'total_amount' => 1575,
                    'lines' => [
                        [
                            'description' => 'Blue Fabric Frame',
                            'sku_or_hsn' => '5208',
                            'quantity' => 10,
                            'unit_price' => 150,
                            'line_total' => 1500,
                        ],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 80],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        // 1. Upload the invoice once via the unified prepare endpoint (no separate confirm/stage clicks)
        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'supplier_invoice.jpg', 'image/jpeg', null, true),
            ])
            ->assertStatus(200);

        // 2. Confirm extraction, supplier/product matching and tax calculation happen automatically
        $this->assertSame('proposal_created', $res->json('status'));
        $this->assertSame('purchase_bill.create.v1', $res->json('action_key'));
        $this->assertSame('prepared', $res->json('extraction.confirmation_status'));

        // 3. Verify original invoice number and document type are preserved
        $proposal = $res->json('proposal');
        $this->assertNotEmpty($proposal['proposal_id']);
        $this->assertSame(1, $proposal['version']);
        $this->assertSame('purchase_bill.create.v1', $proposal['action_key']);

        $preview = $proposal['preview'];
        $this->assertSame('PB-100', $preview['supplier_invoice_no']);
        $this->assertSame('Anver Mills', $preview['supplier']['name']);
        $this->assertSame($this->warehouse->id, $preview['warehouse']['id']);

        // Tax calculation: 10 * 150 = 1500. 5% GST = 37.50 CGST + 37.50 SGST = 75.00 total tax. Grand total = 1575.00.
        $totals = $preview['totals'];
        $this->assertSame('1500.00', $totals['subtotal']);
        $this->assertSame('75.00', $totals['tax_total']);
        $this->assertSame('1575.00', $totals['grand_total']);
        $this->assertSame('37.50', $totals['tax_breakdown']['cgst']);
        $this->assertSame('37.50', $totals['tax_breakdown']['sgst']);
        $this->assertSame('0.00', $totals['tax_breakdown']['igst']);

        // 4. Assert 0 Purchase records written prior to human proposal approval
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());

        // 5. Approve once and verify exactly one purchase bill, correct GST and stock/ledger effects
        $proposalId = $proposal['proposal_id'];
        $appRes = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ])
            ->assertStatus(200);

        $this->assertSame('completed', $appRes->json('status'));
        $this->assertSame('purchase_bill', $appRes->json('execution.receipt.resource_type'));

        // Verify database writes
        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());
        $purchase = Purchase::where('company_id', $this->company->id)->first();
        $this->assertNotNull($purchase);
        $this->assertSame('PB-100', $purchase->supplier_invoice_no);
        $this->assertSame(1, (int) $purchase->status); // Status 1 = Completed Bill
        $this->assertSame(10.0, (float) $purchase->total_qty);
        $this->assertSame(1575.0, (float) $purchase->grand_total);
        $this->assertSame(75.0, (float) $purchase->total_tax);

        // Verify physical stock movement was created for direct bill
        $movement = DB::table('stock_movements')
            ->where('company_id', $this->company->id)
            ->where('source_type', 'purchase')
            ->where('source_id', $purchase->id)
            ->first();
        $this->assertNotNull($movement, 'Stock movement must be recorded for completed purchase bill.');

        // Verify Financial Ledger journal entries were posted
        $journals = DB::table('journal_entries')
            ->where('company_id', $this->company->id)
            ->where('reference_type', 'purchase')
            ->where('reference_id', $purchase->id)
            ->get();
        $this->assertNotEmpty($journals, 'Financial ledger journal entry must be posted for completed purchase bill.');

        @unlink($jpg);
    }

    public function test_duplicate_invoice_number_detection_and_warning(): void
    {
        // Pre-seed an existing purchase with the same invoice number
        Purchase::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
            'reference_no' => 'PR-PRE-EXIST-1',
            'supplier_invoice_no' => 'PB-DUP-888',
            'user_id' => $this->admin->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'item' => 1,
            'total_qty' => 5,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 500,
            'grand_total' => 500,
            'paid_amount' => 0,
            'status' => 1,
            'payment_status' => 1,
            'posted_at' => now(),
        ]);

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PB-DUP-888',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 750,
                    'tax_amount' => 0,
                    'total_amount' => 750,
                    'lines' => [
                        ['description' => 'Blue Fabric Frame', 'quantity' => 5, 'unit_price' => 150, 'line_total' => 750],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'dup_bill.jpg', 'image/jpeg', null, true),
            ])
            ->assertStatus(200);

        // Verification checks flag the duplicate purchase record
        $erpChecks = $res->json('extraction.extracted.erp_checks');
        $this->assertNotNull($erpChecks['duplicate_purchase']);
        $this->assertSame('PB-DUP-888', $erpChecks['duplicate_purchase']['supplier_invoice_no']);
        $this->assertSame('PR-PRE-EXIST-1', $erpChecks['duplicate_purchase']['reference_no']);

        $warnings = $res->json('extraction.extracted.warnings');
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains((string) $w, 'Potential duplicate invoice number')));

        @unlink($jpg);
    }

    public function test_missing_warehouse_defaults_to_active_branch_warehouse(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PB-NO-WH',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1500,
                    'tax_amount' => 75,
                    'total_amount' => 1575,
                    'lines' => [
                        ['description' => 'Blue Fabric Frame', 'quantity' => 10, 'unit_price' => 150, 'line_total' => 1500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'no_wh_bill.jpg', 'image/jpeg', null, true),
            ])
            ->assertStatus(200);

        // Active branch's default warehouse is resolved automatically
        $this->assertSame('proposal_created', $res->json('status'));
        $this->assertSame($this->warehouse->id, $res->json('proposal.preview.warehouse.id'));

        @unlink($jpg);
    }

    public function test_edited_proposal_bumps_version_and_updates_totals(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PB-EDIT-1',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1500,
                    'tax_amount' => 75,
                    'total_amount' => 1575,
                    'lines' => [
                        ['description' => 'Blue Fabric Frame', 'quantity' => 10, 'unit_price' => 150, 'line_total' => 1500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'edit_bill.jpg', 'image/jpeg', null, true),
            ])
            ->assertStatus(200);

        $taskId = $res->json('task_id');
        $originalProposalId = $res->json('proposal.proposal_id');
        $this->assertSame(1, $res->json('proposal.version'));

        // Client edits quantity: 10 -> 20 units
        $patchRes = $this->actingAs($this->admin)->withSession($sess)
            ->patchJson("/api/v1/ai/workflow-tasks/{$taskId}/inputs", [
                'expected_revision' => 1,
                'corrections' => [
                    ['field' => 'items[line-0].qty', 'value' => 20],
                ],
            ])
            ->assertStatus(200);

        // Regenerate proposal
        $regenRes = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/workflow-tasks/{$taskId}/prepare-proposal")
            ->assertSuccessful();

        $newProposal = $regenRes->json('proposal');
        $this->assertSame(2, $newProposal['version']);
        $newPreview = json_decode((string) ($newProposal['preview_json'] ?? '{}'), true);
        $this->assertSame('3000.00', $newPreview['totals']['subtotal']);
        $this->assertSame('150.00', $newPreview['totals']['tax_total']);
        $this->assertSame('3150.00', $newPreview['totals']['grand_total']);

        // Approving outdated version 1 fails closed with 422
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$originalProposalId}/approve", [
                'expected_version' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.proposal.0', 'The proposal is stale or already decided.');

        // Approving new version 2 succeeds!
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$newProposal['id']}/approve", [
                'expected_version' => 2,
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());
        $purchase = Purchase::where('company_id', $this->company->id)->first();
        $this->assertSame(20.0, (float) $purchase->total_qty);
        $this->assertSame(3150.0, (float) $purchase->grand_total);

        @unlink($jpg);
    }

    public function test_expired_proposal_fails_closed_on_approval(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PB-EXP-1',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1500,
                    'tax_amount' => 75,
                    'total_amount' => 1575,
                    'lines' => [
                        ['description' => 'Blue Fabric Frame', 'quantity' => 10, 'unit_price' => 150, 'line_total' => 1500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'exp_bill.jpg', 'image/jpeg', null, true),
            ])
            ->assertStatus(200);

        $proposalId = $res->json('proposal.proposal_id');

        // Manually expire proposal in database
        DB::table('ai_action_proposals')
            ->where('id', $proposalId)
            ->update(['expires_at' => now()->subHour()]);

        // Attempting to approve expired proposal must reject with 422
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.proposal.0', 'The proposal is stale or already decided.');

        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());

        @unlink($jpg);
    }

    public function test_repeated_approval_button_clicks_are_idempotent(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PB-IDEMP-1',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 1500,
                    'tax_amount' => 75,
                    'total_amount' => 1575,
                    'lines' => [
                        ['description' => 'Blue Fabric Frame', 'quantity' => 10, 'unit_price' => 150, 'line_total' => 1500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media/prepare', [
                'file' => new UploadedFile($jpg, 'idemp_bill.jpg', 'image/jpeg', null, true),
            ])
            ->assertStatus(200);

        $proposalId = $res->json('proposal.proposal_id');

        // First click: executes command and creates purchase bill
        $firstClick = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ])
            ->assertStatus(200);

        $this->assertSame('completed', $firstClick->json('status'));
        $firstPurchaseId = $firstClick->json('execution.receipt.resource_id');

        // Second click: immediately recovers completed execution without duplicate purchase
        $secondClick = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ])
            ->assertStatus(200);

        $this->assertSame('completed', $secondClick->json('status'));
        $this->assertSame($firstPurchaseId, $secondClick->json('execution.receipt.resource_id'));

        // Assert exactly 1 purchase voucher exists
        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());

        @unlink($jpg);
    }

    public function test_missing_product_enters_unified_clarification_card_and_posted_bill_mutation_blocks(): void
    {
        // Seed a posted purchase invoice
        Purchase::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
            'reference_no' => 'PR-POSTED-9',
            'supplier_invoice_no' => 'POSTED-999',
            'user_id' => $this->admin->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 100,
            'grand_total' => 100,
            'paid_amount' => 0,
            'status' => 1,
            'payment_status' => 1,
            'posted_at' => now(),
        ]);

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_order',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'POSTED-999',
                    'invoice_date' => '2026-11-12',
                    'subtotal' => 500,
                    'tax_amount' => 0,
                    'total_amount' => 500,
                    'lines' => [
                        ['description' => 'Unregistered Titanium Frame', 'quantity' => 5, 'unit_price' => 100, 'line_total' => 500],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 60],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $up = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg, 'missing_prod.jpg', 'image/jpeg', null, true),
                'extract_now' => true,
            ])->assertStatus(201);
        $mediaId = $up->json('media.id');

        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/confirm", ['expected_revision' => 1])
            ->assertStatus(200);

        // Request trying to mutate already posted voucher is blocked cleanly
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff", [
                'companion_text' => 'Edit posted invoice POSTED-999 and change qty',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NORMALIZED_INPUT_CONFLICT');

        // Normal PO draft with unknown product enters Phase 2.1 clarification cleanly!
        $clarifyRes = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff", [
                'companion_text' => 'Prepare purchase order for Main Warehouse',
            ])->assertStatus(200);

        $this->assertSame('needs_input', $clarifyRes->json('status'));
        $this->assertSame('items.0.product', $clarifyRes->json('clarification.missing_field'));
        $this->assertSame('items[line-0].product_id', $clarifyRes->json('clarification.required_inputs.0.field'));

        // Attach confirmed media to chat turn cleanly
        Http::fake([
            'integrate.api.nvidia.com/*' => function ($req) {
                $body = $req->data();
                $this->assertSame('nvidia/nemotron-3.5-lightning-30b-a3b', $body['model']);
                $sysPrompt = $body['messages'][0]['content'] ?? '';
                $this->assertStringContainsString('<untrusted_media_data>', $sysPrompt);
                $this->assertStringContainsString('Unregistered Titanium Frame', $sysPrompt);

                return Http::response([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Reviewed attachment noted.'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 20],
                ], 200);
            },
        ]);

        $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'What product name did we extract from this file?',
                'media_ids' => [$mediaId],
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        @unlink($jpg);
    }

    public function test_unconfirmed_media_attached_to_chat_is_rejected_with_422(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'document_type' => 'purchase_order',
                    'supplier_name' => 'Anver Mills',
                    'invoice_number' => 'PO-404',
                    'lines' => [
                        ['description' => 'Blue Fabric Frame', 'quantity' => 5, 'unit_price' => 150, 'line_total' => 750],
                    ],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $up = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg, 'unconfirmed_invoice.jpg', 'image/jpeg', null, true),
                'extract_now' => true,
            ])->assertStatus(201);
        $mediaId = $up->json('media.id');

        // Unconfirmed extraction must be rejected by /api/v1/ai/chat
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'Create a PO from this attached invoice',
                'media_ids' => [$mediaId],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EXTRACTION_CONFIRMATION_REQUIRED');

        @unlink($jpg);
    }

    public function test_cross_modal_handoff_detects_conflicts_between_document_and_voice(): void
    {
        $callCount = 0;
        Http::fake([
            'integrate.api.nvidia.com/*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // Document extraction (Invoice for Anver Mills)
                    return Http::response([
                        'choices' => [['message' => ['content' => json_encode([
                            'document_type' => 'purchase_order',
                            'supplier_name' => 'Anver Mills',
                            'invoice_number' => 'INV-DOC-1',
                            'lines' => [
                                ['description' => 'Blue Fabric Frame', 'quantity' => 10, 'unit_price' => 150, 'line_total' => 1500],
                            ],
                        ])], 'finish_reason' => 'stop']],
                        'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 50],
                    ], 200);
                }

                // Audio extraction (Voice note mentioning Apex Textiles)
                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'transcript_text' => 'Order 10 frames from Apex Textiles',
                        'detected_language' => 'en',
                        'intent_guess' => 'purchase_order.create.v1',
                        'party_name' => 'Apex Textiles',
                        'confidence' => 0.9,
                        'items' => [
                            ['product_name' => 'Blue Fabric Frame', 'quantity' => 10, 'unit_price' => 150],
                        ],
                    ])], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 45],
                ], 200);
            },
        ]);

        $jpg = $this->makeTempJpeg();
        $wav = $this->makeTempWav();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        // 1. Upload & confirm invoice image
        $upImg = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg, 'anver_bill.jpg', 'image/jpeg', null, true),
                'extract_now' => true,
            ])->assertStatus(201);
        $imgId = $upImg->json('media.id');
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$imgId}/confirm", ['expected_revision' => 1])
            ->assertStatus(200);

        // 2. Upload voice note
        $upWav = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($wav, 'apex_voice.wav', 'audio/wav', null, true),
                'extract_now' => true,
            ])->assertStatus(201);
        $wavId = $upWav->json('media.id');

        // Unconfirmed companion media is rejected
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$imgId}/handoff", [
                'companion_media_id' => $wavId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EXTRACTION_CONFIRMATION_REQUIRED');

        // Confirm voice note
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$wavId}/confirm", ['expected_revision' => 1])
            ->assertStatus(200);

        // 3. Cross-modal handoff detects party name mismatch (Anver Mills vs Apex Textiles)
        $conflictRes = $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$imgId}/handoff", [
                'companion_media_id' => $wavId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NORMALIZED_INPUT_CONFLICT');

        $this->assertStringContainsString('Cross-modal mismatch on party name', $conflictRes->json('error_message'));

        @unlink($jpg);
        @unlink($wav);
    }

    public function test_cross_branch_or_financial_year_handoff_is_forbidden_for_write_actions(): void
    {
        Http::fake([
            'https://integrate.api.nvidia.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test-branch-isolation',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode([
                    'document_type' => 'purchase_order',
                    'supplier_name' => 'Kovai Yarns',
                    'invoice_number' => 'PO-BRANCH-TEST',
                    'subtotal' => 1000.00,
                    'tax_amount' => 50.00,
                    'total_amount' => 1050.00,
                    'lines' => [],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sessBranch1 = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        // 1. Upload in Branch 1
        $res = $this->actingAs($this->admin)->withSession($sessBranch1)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg, 'doc_b1.jpg', 'image/jpeg', null, true),
                'extract_now' => true,
            ])
            ->assertStatus(201);

        $mediaId = $res->json('media.id');

        // Confirm extraction in Branch 1
        $this->actingAs($this->admin)->withSession($sessBranch1)
            ->postJson("/api/v1/ai/media/{$mediaId}/confirm")
            ->assertStatus(200);

        // Create Branch 2 and attach admin
        $branch2Id = $this->company->branches()->create([
            'name' => 'Branch 2 Secondary',
            'code' => 'BR2-' . uniqid(),
            'is_active' => true,
        ])->id;
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'branch_id' => $branch2Id,
        ]);

        // 2. Attempting write handoff from Branch 2 session must reject with 403 CROSS_TENANT_FORBIDDEN
        $sessBranch2 = ['company_id' => $this->company->id, 'branch_id' => $branch2Id, 'financial_year_id' => $this->fyId];
        $headersBranch2 = [
            'X-Company-ID' => (string) $this->company->id,
            'X-Branch-ID' => (string) $branch2Id,
            'X-Financial-Year-ID' => (string) $this->fyId,
        ];
        $this->actingAs($this->admin)->withSession($sessBranch2)
            ->postJson("/api/v1/ai/media/{$mediaId}/handoff", [
                'action_key' => 'purchase_order.create.v1',
            ], $headersBranch2)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'CROSS_TENANT_FORBIDDEN');

        @unlink($jpg);
    }

    public function test_chat_rejects_attached_media_with_unresolved_conflicts(): void
    {
        Http::fake([
            'https://integrate.api.nvidia.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test-conflict-chat',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode([
                    'document_type' => 'purchase_bill',
                    'supplier_name' => 'ABC Traders',
                    'buyer_name' => 'Unrelated Entity Inc', // Company mismatch triggers conflict
                    'invoice_number' => 'DEMO-INV-CONFLICT',
                    'subtotal' => 2000.00,
                    'tax_amount' => 100.00,
                    'total_amount' => 2100.00,
                    'lines' => [],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $sess = ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];

        $res = $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg, 'conflict_bill.jpg', 'image/jpeg', null, true),
                'extract_now' => true,
            ])
            ->assertStatus(201);

        $mediaId = $res->json('media.id');

        $this->actingAs($this->admin)->withSession($sess)
            ->postJson("/api/v1/ai/media/{$mediaId}/confirm")
            ->assertStatus(200);

        // Chat with this media must be rejected due to unresolved company identity conflict
        $this->actingAs($this->admin)->withSession($sess)
            ->postJson('/api/v1/ai/chat', [
                'message' => 'Prepare purchase order from this document',
                'media_ids' => [$mediaId],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'NORMALIZED_INPUT_CONFLICT');

        @unlink($jpg);
    }

    private function makeTempWav(): string
    {
        $sampleRate = 8000;
        $totalSamples = 8000;
        $pcm = '';
        for ($i = 0; $i < $totalSamples; $i++) {
            $sample = (int) round(10000 * sin(2 * M_PI * 440 * ($i / $sampleRate)));
            $pcm .= pack('s', $sample);
        }

        $dataSize = strlen($pcm);
        $header = 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVEfmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', 1)
            . pack('V', $sampleRate)
            . pack('V', $sampleRate * 2)
            . pack('v', 2)
            . pack('v', 16)
            . 'data'
            . pack('V', $dataSize);

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zolo_wf_wav_' . uniqid() . '.wav';
        file_put_contents($path, $header . $pcm);

        return $path;
    }

    private function makeTempJpeg(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zolo_wf_' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(120, 80);
        $bg = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, 120, 80, $bg);
        imagejpeg($im, $path, 85);
        imagedestroy($im);

        return $path;
    }
}
