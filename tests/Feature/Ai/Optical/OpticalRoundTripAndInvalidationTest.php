<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\TaskCorrectionService;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use App\Models\AiMediaAsset;
use App\Models\Category;
use App\Models\CommercialOrderItem;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tax\Gstin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class OpticalRoundTripAndInvalidationTest extends TestCase
{
    use UsesAiMysql;

    private Company $opticalCompany;
    private Company $tradingCompany;
    private int $opticalBranchId;
    private int $opticalFyId;
    private int $tradingBranchId;
    private int $tradingFyId;
    private User $admin;
    private Warehouse $optWarehouse;
    private Warehouse $tradeWarehouse;
    private Customer $optCustomer;
    private Supplier $optSupplier;
    private Supplier $tradeSupplier;
    private Product $optLensProduct;
    private Product $tradeProduct;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);
        config()->set('ai.providers.nvidia.key', 'nvapi-optical-r04-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.allow_direct_sales_invoice', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('ai_business_knowledge.enabled', true);
        config()->set('ai_business_knowledge.opticals_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('compliance.enabled', true);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        $role = Role::findById(1, 'web');
        foreach (['sales-add', 'purchases-add'] as $pName) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $pName, 'guard_name' => 'web']));
        }
        $this->admin = User::create([
            'name' => 'Optical RoundTrip Admin',
            'email' => 'opt_r04_' . uniqid() . '@example.com',
            'phone' => '9800022233',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->admin->assignRole($role);

        [$this->opticalCompany, $this->opticalBranchId, $this->opticalFyId, $this->optWarehouse] = $this->seedCompany('Clarity Optics ' . uniqid(), true);
        [$this->tradingCompany, $this->tradingBranchId, $this->tradingFyId, $this->tradeWarehouse] = $this->seedCompany('Plain Trading ' . uniqid(), false);

        $taxCatId = DB::table('tax_categories')->insertGetId([
            'company_id' => $this->opticalCompany->id,
            'name' => 'GST 12%',
            'supply_type' => 'taxable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tax_rates')->insert([
            'company_id' => $this->opticalCompany->id,
            'tax_category_id' => $taxCatId,
            'rate' => 12.0,
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $optUnit = Unit::create(['company_id' => $this->opticalCompany->id, 'unit_code' => 'PCS-' . uniqid(), 'unit_name' => 'Pieces', 'is_active' => true]);
        $optCat = Category::create(['company_id' => $this->opticalCompany->id, 'name' => 'Lenses', 'is_active' => true]);
        $this->optLensProduct = Product::forceCreate([
            'company_id' => $this->opticalCompany->id,
            'name' => 'Crizal Rock 1.56 SV',
            'code' => 'CRZ156-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $optCat->id,
            'unit_id' => $optUnit->id,
            'purchase_unit_id' => $optUnit->id,
            'sale_unit_id' => $optUnit->id,
            'tax_category_id' => $taxCatId,
            'hsn_code' => '9001',
            'cost' => 450,
            'price' => 950,
            'qty' => 50,
            'is_active' => true,
        ]);
        $this->optLensProduct->company_id = $this->opticalCompany->id;
        $this->optLensProduct->save();

        $optCtx = new \App\Services\Platform\CompanyContext($this->opticalCompany->id, $this->opticalBranchId, $this->opticalFyId);
        $optCatalog = app(\App\Services\Optical\OpticalCatalogService::class);
        $optCatalog->registerIdentity($optCtx, $this->optLensProduct, [
            'lens_family' => 'Crizal Rock 1.56 SV',
            'lens_form' => 'finished_stock',
            'lr' => 'RE',
            'sph' => '+2.00',
            'cyl' => '-1.00',
            'axis' => '90',
            'dia' => '70',
        ]);
        $optCatalog->registerIdentity($optCtx, $this->optLensProduct, [
            'lens_family' => 'Crizal Rock 1.56 SV',
            'lens_form' => 'finished_stock',
            'lr' => 'RE',
            'sph' => '+2.25',
            'cyl' => '-1.00',
            'axis' => '90',
            'dia' => '70',
        ]);
        $optCatalog->registerIdentity($optCtx, $this->optLensProduct, [
            'lens_family' => 'Crizal Rock 1.56 SV',
            'lens_form' => 'finished_stock',
            'lr' => 'LE',
            'sph' => '-1.50',
            'dia' => '65',
        ]);
        DB::table('product_warehouse')->insert([
            'company_id' => $this->opticalCompany->id,
            'product_id' => $this->optLensProduct->id,
            'warehouse_id' => $this->optWarehouse->id,
            'qty' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $custGroupId = DB::table('customer_groups')->insertGetId([
            'company_id' => $this->opticalCompany->id,
            'name' => 'Retail Optical',
            'percentage' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $custGstin = Gstin::make('33OPTCL1111A1Z');
        $this->optCustomer = Customer::create([
            'company_id' => $this->opticalCompany->id,
            'customer_group_id' => $custGroupId,
            'name' => 'Focus Optical Mart',
            'company_name' => 'Focus Optical Mart',
            'tax_no' => $custGstin,
            'phone_number' => '9876543210',
            'is_active' => true,
        ]);
        DB::table('party_tax_profiles')->updateOrInsert(
            ['company_id' => $this->opticalCompany->id, 'party_type' => 'customer', 'party_id' => $this->optCustomer->id],
            ['registration_type' => 'regular', 'gstin' => $custGstin, 'state_code' => '33', 'created_at' => now(), 'updated_at' => now()]
        );
        $this->optSupplier = Supplier::create([
            'company_id' => $this->opticalCompany->id,
            'name' => 'Essilor Wholesale',
            'company_name' => 'Essilor Wholesale',
            'vat_number' => Gstin::make('33ESSIL2222B1Z'),
            'phone_number' => '9000011111',
            'is_active' => true,
        ]);
        DB::table('party_tax_profiles')->updateOrInsert(
            ['company_id' => $this->opticalCompany->id, 'party_type' => 'supplier', 'party_id' => $this->optSupplier->id],
            ['registration_type' => 'regular', 'gstin' => $this->optSupplier->vat_number, 'state_code' => '33', 'created_at' => now(), 'updated_at' => now()]
        );

        $tradeTaxCatId = DB::table('tax_categories')->insertGetId([
            'company_id' => $this->tradingCompany->id,
            'name' => 'GST 18%',
            'supply_type' => 'taxable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tax_rates')->insert([
            'company_id' => $this->tradingCompany->id,
            'tax_category_id' => $tradeTaxCatId,
            'rate' => 18.0,
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tradeUnit = Unit::create(['company_id' => $this->tradingCompany->id, 'unit_code' => 'NOS-' . uniqid(), 'unit_name' => 'Nos', 'is_active' => true]);
        $tradeCat = Category::create(['company_id' => $this->tradingCompany->id, 'name' => 'Hardware', 'is_active' => true]);
        $this->tradeProduct = Product::forceCreate([
            'company_id' => $this->tradingCompany->id,
            'name' => 'Steel Bolt',
            'code' => 'BOLT-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $tradeCat->id,
            'unit_id' => $tradeUnit->id,
            'purchase_unit_id' => $tradeUnit->id,
            'sale_unit_id' => $tradeUnit->id,
            'tax_category_id' => $tradeTaxCatId,
            'hsn_code' => '7318',
            'cost' => 10,
            'price' => 20,
            'is_active' => true,
        ]);
        $this->tradeProduct->company_id = $this->tradingCompany->id;
        $this->tradeProduct->save();
        $this->tradeSupplier = Supplier::create([
            'company_id' => $this->tradingCompany->id,
            'name' => 'Metro Fasteners',
            'company_name' => 'Metro Fasteners',
            'vat_number' => Gstin::make('33METRO3333C1Z'),
            'phone_number' => '9111122222',
            'is_active' => true,
        ]);
        DB::table('party_tax_profiles')->updateOrInsert(
            ['company_id' => $this->tradingCompany->id, 'party_type' => 'supplier', 'party_id' => $this->tradeSupplier->id],
            ['registration_type' => 'regular', 'gstin' => $this->tradeSupplier->vat_number, 'state_code' => '33', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function test_mixed_eye_optical_extraction_preserves_specs_through_sales_order_and_invalidates_stale_proposal_on_edit(): void
    {
        $disk = 'local';
        $key = 'ai-media-test/' . uniqid() . '_mixed.png';
        Storage::disk($disk)->put($key, 'optical-sales-order-png');

        $asset = AiMediaAsset::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $this->opticalCompany->id,
            'branch_id' => $this->opticalBranchId,
            'financial_year_id' => $this->opticalFyId,
            'actor_id' => $this->admin->id,
            'source_kind' => 'image',
            'original_file_name_sanitized' => 'mixed_rx_so.png',
            'original_mime' => 'image/png',
            'canonical_mime' => 'image/png',
            'size_bytes' => 120,
            'sha256' => hash('sha256', 'mixed-so-' . uniqid()),
            'private_disk' => $disk,
            'private_key' => $key,
            'derivatives_json' => [['page' => 1, 'key' => $key, 'mime' => 'image/png']],
            'state' => 'ready_for_extraction',
            'expires_at' => now()->addDays(7),
        ]);

        $coGstin = DB::table('tax_registrations')->where('company_id', $this->opticalCompany->id)->value('gstin');
        Http::fake([
            'https://integrate.api.nvidia.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'document_type' => 'sales_order',
                                'document_kind' => 'sales_order',
                                'supplier_name' => $this->opticalCompany->legal_name,
                                'supplier_gstin' => $coGstin,
                                'buyer_name' => $this->optCustomer->name,
                                'buyer_gstin' => $this->optCustomer->tax_no,
                                'invoice_number' => 'CUST-SO-77',
                                'invoice_date' => now()->toDateString(),
                                'lines' => [
                                    [
                                        'page' => 1,
                                        'row_role' => 'item',
                                        'description' => 'RE',
                                        'product_name' => $this->optLensProduct->name,
                                        'optical_spec' => ['lr' => 'RE', 'sph' => '+2.00', 'cyl' => '-1.00', 'axis' => 90, 'dia' => 70],
                                        'quantity' => 1,
                                        'unit_price' => 950,
                                        'line_total' => 950,
                                    ],
                                    [
                                        'page' => 1,
                                        'row_role' => 'item',
                                        'description' => 'LE',
                                        'product_name' => $this->optLensProduct->name,
                                        'optical_spec' => ['lr' => 'LE', 'sph' => '-1.50', 'dia' => 65],
                                        'quantity' => 1,
                                        'unit_price' => 950,
                                        'line_total' => 950,
                                    ],
                                ],
                                'subtotal' => 1900,
                                'total_amount' => 1900,
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ]
                ],
            ], 200),
        ]);

        $extractRes = $this->actingAs($this->admin)->postJson("/api/v1/ai/media/{$asset->id}/extract");
        $extractRes->assertStatus(200);
        $rev1 = (int) $extractRes->json('extraction.revision');

        // Handoff to workflow task & initial preview proposal (v1)
        $confirmRes = $this->actingAs($this->admin)->postJson("/api/v1/ai/media/{$asset->id}/confirm", [
            'expected_revision' => $rev1,
            'companion_text' => 'Sales order at warehouse ' . $this->optWarehouse->name,
        ]);
        $confirmRes->assertStatus(200);

        $handoff = $this->actingAs($this->admin)->postJson("/api/v1/ai/media/{$asset->id}/handoff");
        $handoff->assertStatus(200);
        if ($handoff->json('status') !== 'proposal_created') {
            $this->fail('Expected proposal_created, got: ' . json_encode($handoff->json('clarification')));
        }
        $handoff->assertJsonPath('status', 'proposal_created');

        $taskId = $handoff->json('task_id') ?? $handoff->json('task.id');
        $v1ProposalId = $handoff->json('proposal.proposal_id') ?? $handoff->json('proposal_id');
        $v1Version = (int) ($handoff->json('proposal.version') ?? $handoff->json('version'));
        $previewItems = $handoff->json('proposal.preview.lines') ?? $handoff->json('proposal.preview.items') ?? $handoff->json('preview.lines') ?? $handoff->json('preview.items');
        $this->assertCount(2, $previewItems);
        $this->assertSame('R', $previewItems[0]['lr']);
        $this->assertSame('+2.00', $previewItems[0]['sph']);
        $this->assertSame('90', $previewItems[0]['axis']);
        $this->assertSame('L', $previewItems[1]['lr']);
        $this->assertSame('-1.50', $previewItems[1]['sph']);

        // Edit RE optical spec + qty via TaskCorrectionService BEFORE executing v1
        $taskRow = DB::table('ai_workflow_tasks')->where('id', $taskId)->first();
        $principal = new AiPrincipal(
            actorId: $this->admin->id,
            companyId: $this->opticalCompany->id,
            branchId: $this->opticalBranchId,
            financialYearId: $this->opticalFyId,
            effectiveRole: 'Admin',
        );

        $corrected = app(TaskCorrectionService::class)->applyCorrections(
            principal: $principal,
            taskId: $taskId,
            expectedRevision: (int) $taskRow->revision,
            corrections: [
                ['field' => 'items[line-0].optical_spec.sph', 'value' => '+2.25'],
                ['field' => 'items[line-0].sph', 'value' => '+2.25'],
                ['field' => 'items[line-0].qty', 'value' => 2],
            ],
        );

        // Old v1 proposal must now be superseded and blocked from execution
        $v1Status = DB::table('ai_action_proposals')->where('id', $v1ProposalId)->value('status');
        $this->assertSame('superseded', $v1Status);

        $staleApproveRes = $this->actingAs($this->admin)->postJson("/api/v1/ai/proposals/{$v1ProposalId}/approve", [
            'expected_version' => $v1Version,
        ]);
        $this->assertTrue(in_array($staleApproveRes->getStatusCode(), [409, 422], true), 'Stale v1 proposal must be rejected after task edit.');

        // Prepare regenerated preview and execute it cleanly at bumped version v2
        $v2Prepared = app(TaskCorrectionService::class)->prepareProposal($principal, $corrected['task'], $corrected['evaluation']);
        $this->assertSame('proposal_created', $v2Prepared['status']);
        $v2Id = $v2Prepared['proposal_id'];
        $this->assertSame($v1ProposalId, $v2Id);
        $this->assertSame($v1Version + 1, (int) $v2Prepared['version']);

        // RE line updated while LE sibling stayed untouched!
        $v2Items = $v2Prepared['preview']['lines'] ?? $v2Prepared['preview']['items'];
        $this->assertSame('+2.25', $v2Items[0]['sph']);
        $this->assertSame('2.00', (string) $v2Items[0]['qty']);
        $this->assertSame('L', $v2Items[1]['lr']);
        $this->assertSame('-1.50', $v2Items[1]['sph']);
        $this->assertSame('1.00', (string) $v2Items[1]['qty']);

        $execRes = $this->actingAs($this->admin)->postJson("/api/v1/ai/proposals/{$v2Id}/approve", [
            'expected_version' => (int) $v2Prepared['version'],
            'confirm_execute' => true,
        ]);
        $execRes->assertStatus(200);
        $orderId = (int) ($execRes->json('execution.receipt.resource_id') ?? $execRes->json('execution.resource_id') ?? $execRes->json('receipt.resource_id'));
        $this->assertGreaterThan(0, $orderId, 'Exec response: ' . $execRes->getContent());

        $storedItems = CommercialOrderItem::query()
            ->where('company_id', $this->opticalCompany->id)
            ->where('order_kind', 'sale')
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $storedItems);
        $this->assertSame('+2.25', $storedItems[0]->optical_spec_json['sph'] ?? null);
        $this->assertSame('R', $storedItems[0]->optical_spec_json['lr'] ?? null);
        $this->assertSame('-1.50', $storedItems[1]->optical_spec_json['sph'] ?? null);
        $this->assertSame('L', $storedItems[1]->optical_spec_json['lr'] ?? null);
    }

    public function test_non_optical_company_rejects_optical_fields_on_proposal_creation(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->admin->id,
            companyId: $this->tradingCompany->id,
            branchId: $this->tradingBranchId,
            financialYearId: $this->tradingFyId,
            effectiveRole: 'Admin',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Optical prescription and lens fields are only permitted when Opticals AI is enabled');

        app(ActionProposalService::class)->create(
            principal: $principal,
            key: 'purchase_order.create.v1',
            slots: [
                'supplier_id' => $this->tradeSupplier->id,
                'warehouse_id' => $this->tradeWarehouse->id,
                'date' => now()->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->tradeProduct->id,
                        'qty' => 2,
                        'net_unit_cost' => 10,
                        'sph' => '-2.00',
                        'lr' => 'RE',
                    ]
                ],
            ],
        );
    }

    /**
     * @return array{0: Company, 1: int, 2: int, 3: Warehouse}
     */
    private function seedCompany(string $legalName, bool $isOptical): array
    {
        $company = Company::create([
            'legal_name' => $legalName,
            'trade_name' => $legalName,
            'code' => ($isOptical ? 'OP' : 'TR') . strtoupper(substr(uniqid(), -5)),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $branch = $company->branches()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $fy = FiscalYear::create([
            'company_id' => $company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'is_closed' => false,
        ]);
        $warehouse = Warehouse::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Primary Store',
            'address' => 'Chennai',
            'is_active' => true,
        ]);

        $gstin = Gstin::make('33' . strtoupper(substr(preg_replace('/[^A-Z]/', 'A', strtoupper($company->code)), 0, 5)) . rand(1000, 9999) . 'A1Z');
        DB::table('tax_registrations')->insert([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'gstin' => $gstin,
            'legal_name' => $legalName,
            'trade_name' => $legalName,
            'state_code' => '33',
            'status' => 'active',
            'registration_type' => 'regular',
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['code' => '1000', 'name' => 'Cash in Hand', 'type' => 'asset', 'role' => 'cash'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'role' => 'ar'],
            ['code' => '1400', 'name' => 'Inventory Asset', 'type' => 'asset', 'role' => 'inventory'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'role' => 'ap'],
            ['code' => '2100', 'name' => 'Output CGST', 'type' => 'liability', 'role' => 'output_tax_cgst'],
            ['code' => '2101', 'name' => 'Output SGST', 'type' => 'liability', 'role' => 'output_tax_sgst'],
            ['code' => '2102', 'name' => 'Output IGST', 'type' => 'liability', 'role' => 'output_tax_igst'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'role' => 'sales'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'role' => 'cogs'],
        ] as $acc) {
            ChartOfAccount::forceCreate([
                'company_id' => $company->id,
                'code' => $acc['code'],
                'name' => $acc['name'],
                'type' => $acc['type'],
                'sub_type' => $acc['role'],
                'is_active' => true,
            ]);
        }

        if ($isOptical) {
            DB::table('company_industry_settings')->insert([
                'company_id' => $company->id,
                'profile_key' => 'opticals',
                'subtype' => 'retail_and_wholesale',
                'settings_json' => json_encode(['lens_powers' => true]),
                'updated_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('company_user')->insert([
            'company_id' => $company->id,
            'user_id' => $this->admin->id,
            'is_default' => $isOptical,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('company_user_branches')->insert([
            'company_id' => $company->id,
            'user_id' => $this->admin->id,
            'branch_id' => $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_series')->insert([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'financial_year_id' => $fy->id,
            'document_type' => 'sales_order',
            'code' => 'SO',
            'prefix' => 'SO-',
            'suffix' => '',
            'next_number' => 1,
            'padding' => 6,
            'reset_policy' => 'financial_year',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 250000,
        ]);

        return [$company, $branch->id, $fy->id, $warehouse];
    }
}
