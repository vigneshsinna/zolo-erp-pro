<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionPreparationService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
use App\Models\Biller;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\GoodsReceivedNoteItem;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\ProductQueryService;
use App\Services\Optical\OpticalReturnValidator;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class OpticalCommercialWorkflowParityTest extends TestCase
{
    use UsesAiMysql;

    private AiPrincipal $principal;
    private CompanyContext $context;
    private Company $company;
    private CompanyBranch $branch;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Customer $customer;
    private Product $product;
    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'erp_ai.conversation_enabled' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'commercial.enabled' => true,
            'compliance.enabled' => false,
            'ai_business_knowledge.opticals_enabled' => true,
        ]);
        $this->app->forgetInstance(AiFeatureGate::class);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->company = Company::where('code', 'ZTEX')->firstOrFail();
        DB::table('tax_registrations')->where('company_id', $this->company->id)->update(['status' => 'inactive']);
        DB::table('company_industry_settings')->updateOrInsert(
            ['company_id' => $this->company->id],
            [
                'profile_key' => 'opticals',
                'subtype' => 'wholesale_distributor',
                'settings_json' => json_encode(['optical_defaults' => true]),
                'updated_by' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->branch = $this->company->branches()->firstOrFail();
        $year = $this->company->fiscalYears()->where('status', 'open')->firstOrFail();

        $actor = User::forceCreate([
            'name' => 'Optical Commercial Tester',
            'email' => 'opt_comm_' . uniqid() . '@example.test',
            'phone' => '9811111111',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($actor->id);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $actor->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($actor);

        $this->context = new CompanyContext($this->company->id, $this->branch->id, $year->id);
        $keys = array_keys(app(ActionCapabilityRegistry::class)->all());
        $this->principal = new AiPrincipal(
            actorId: $actor->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $year->id,
            permittedToolNames: $keys,
        );
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)
            ->where('branch_id', $this->branch->id)
            ->firstOrFail();
        $this->supplier = Supplier::where('company_id', $this->company->id)->firstOrFail();
        $this->customer = Customer::where('company_id', $this->company->id)->firstOrFail();

        $this->unitId = Unit::forceCreate([
            'company_id' => $this->company->id,
            'unit_name' => 'Optical Piece',
            'unit_code' => 'OPCS_' . uniqid(),
            'is_active' => true,
        ])->id;

        $prototype = Product::where('company_id', $this->company->id)->where('type', 'standard')->firstOrFail();
        $this->product = $prototype->replicate(['code', 'name']);
        $this->product->forceFill([
            'code' => 'OPTLENS-' . uniqid(),
            'name' => 'Essilor 1.67 BlueCut -1.25/-0.50',
            'qty' => 20,
            'cost' => 120,
            'price' => 250,
            'unit_id' => $this->unitId,
            'sale_unit_id' => $this->unitId,
            'purchase_unit_id' => $this->unitId,
            'tax_category_id' => null,
            'tax_id' => null,
            'base' => '4.00',
            'addition' => '+1.50',
            'lr' => 'RE',
            'dia' => '70',
            'is_batch' => false,
            'is_imei' => false,
            'is_variant' => false,
        ])->save();

        Biller::where('company_id', $this->company->id)->first()
            ?? Biller::forceCreate([
                'company_id' => $this->company->id,
                'name' => 'Optical Test Biller',
                'company_name' => 'Optical Test Co',
                'email' => 'optbiller@example.test',
                'phone_number' => '9811111112',
                'address' => 'Test St',
                'city' => 'Chennai',
                'is_active' => true,
            ]);
    }

    public function test_purchase_order_and_grn_round_trip_optical_specs(): void
    {
        $prep = app(ActionPreparationService::class);
        $approve = app(ActionApprovalService::class);
        $exec = app(ApprovedActionExecutor::class);

        $poProposal = $prep->prepare($this->principal, 'purchase_order.create.v1', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'payment_terms_type' => 'cash',
            'credit_days' => 0,
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 4,
                'net_unit_cost' => 120,
                'sph' => '-1.25',
                'cyl' => '-0.5',
                'axis' => 90,
                'lr' => 'R',
                'dia' => '70',
            ]],
        ]);
        $this->assertSame('ok', $poProposal['status'] ?? null, json_encode($poProposal['required_inputs'] ?? $poProposal));
        $poProposalId = (string) $poProposal['data']['proposal_id'];
        $approve->approve($this->principal, $poProposalId, 1);
        $poResult = $exec->execute($this->principal, $poProposalId, 1);
        $poId = (int) ($poResult['receipt']['resource_id'] ?? 0);
        $this->assertGreaterThan(0, $poId);

        $poItem = ProductPurchase::where('purchase_id', $poId)->firstOrFail();
        $stockDetails = is_array($poItem->stock_details_json)
            ? $poItem->stock_details_json
            : json_decode((string) $poItem->stock_details_json, true);
        $this->assertSame('-1.25', $stockDetails['sph'] ?? null);
        $this->assertSame('R', $stockDetails['lr'] ?? null);

        $grnProposal = $prep->prepare($this->principal, 'grn.create.v1', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'grn_date' => now()->toDateString(),
            'purchase_order_id' => $poId,
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 4,
                'cost' => 120,
                'sph' => '-1.25',
                'cyl' => '-0.50',
                'axis' => 90,
                'lr' => 'RE',
            ]],
        ]);
        $this->assertSame('ok', $grnProposal['status'] ?? null);
        $grnProposalId = (string) $grnProposal['data']['proposal_id'];
        $approve->approve($this->principal, $grnProposalId, 1);
        $grnResult = $exec->execute($this->principal, $grnProposalId, 1);
        $grnId = (int) ($grnResult['receipt']['resource_id'] ?? 0);
        $this->assertGreaterThan(0, $grnId);

        $grnItem = GoodsReceivedNoteItem::where('goods_received_note_id', $grnId)->firstOrFail();
        $grnSpec = is_array($grnItem->optical_spec_json)
            ? $grnItem->optical_spec_json
            : json_decode((string) $grnItem->optical_spec_json, true);
        $this->assertSame('-1.25', $grnSpec['sph'] ?? null);
        $this->assertSame('-0.50', $grnSpec['cyl'] ?? null);
    }

    public function test_purchase_bill_allows_direct_no_po_and_blocks_invoice_before_receipt(): void
    {
        $prep = app(ActionPreparationService::class);
        $approve = app(ActionApprovalService::class);
        $exec = app(ApprovedActionExecutor::class);

        $res = $prep->prepare($this->principal, 'purchase_bill.create.v1', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'supplier_invoice_no' => 'VINV-' . uniqid(),
            'receipt_status' => 'not_received',
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 2,
                'net_unit_cost' => 120,
                'sph' => '-1.25',
            ]],
        ]);
        $this->assertSame('needs_input', $res['status'] ?? null);
        $issueFields = array_column($res['required_inputs'] ?? [], 'field');
        $this->assertContains('receipt_status', $issueFields, 'Invoice-before-receipt must fail closed: ' . json_encode($res['required_inputs'] ?? []));

        $billProposal = $prep->prepare($this->principal, 'purchase_bill.create.v1', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'supplier_invoice_no' => 'VDIR-' . uniqid(),
            'receipt_status' => 'received',
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 2,
                'net_unit_cost' => 120,
                'sph' => '-1.25',
                'cyl' => '-0.50',
                'lr' => 'RE',
            ]],
        ]);
        $this->assertSame('ok', $billProposal['status'] ?? null);
        $billProposalId = (string) $billProposal['data']['proposal_id'];
        $approve->approve($this->principal, $billProposalId, 1);
        $billResult = $exec->execute($this->principal, $billProposalId, 1);
        $this->assertGreaterThan(0, (int) ($billResult['receipt']['resource_id'] ?? 0));
    }

    public function test_sales_order_persists_optical_header_and_lines_and_blocks_when_flag_off(): void
    {
        $prep = app(ActionPreparationService::class);
        $approve = app(ActionApprovalService::class);
        $exec = app(ApprovedActionExecutor::class);

        $soProposal = $prep->prepare($this->principal, 'sales_order.create.v1', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'customer_order_no' => 'DEALER-OPT-44',
            'fitting_charge' => '150.00',
            'tinting_cost' => '75.00',
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 2,
                'net_unit_price' => 250,
                'sph' => '-1.25',
                'cyl' => '-0.50',
                'axis' => 90,
                'lr' => 'RE',
            ]],
        ]);
        $this->assertSame('ok', $soProposal['status'] ?? null);
        $soProposalId = (string) $soProposal['data']['proposal_id'];
        $approve->approve($this->principal, $soProposalId, 1);
        $soResult = $exec->execute($this->principal, $soProposalId, 1);
        $soId = (int) ($soResult['receipt']['resource_id'] ?? 0);
        $this->assertGreaterThan(0, $soId);

        $order = SalesOrder::findOrFail($soId);
        $attrs = is_array($order->attributes_json)
            ? $order->attributes_json
            : json_decode((string) $order->attributes_json, true);
        $this->assertSame('DEALER-OPT-44', $attrs['optical_header']['customer_order_no'] ?? null);
        $this->assertSame('-1.25', $attrs['optical_lines'][0]['sph'] ?? null);

        config()->set('ai_business_knowledge.opticals_enabled', false);
        $blockedSo = $prep->prepare($this->principal, 'sales_order.create.v1', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'qty' => 1,
                'net_unit_price' => 250,
                'sph' => '-1.25',
            ]],
        ]);
        $this->assertSame('needs_input', $blockedSo['status'] ?? null);
        $this->assertFalse($blockedSo['can_prepare_proposal'] ?? true);
    }

    public function test_return_validator_and_product_query_service_optical_gates(): void
    {
        $validator = app(OpticalReturnValidator::class);

        $purchaseId = DB::table('purchases')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->context->financialYearId,
            'reference_no' => 'PO-RET-' . uniqid(),
            'user_id' => $this->principal->actorId,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'item' => 1,
            'total_qty' => 5,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 600,
            'grand_total' => 600,
            'paid_amount' => 0,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ppId = DB::table('product_purchases')->insertGetId([
            'company_id' => $this->company->id,
            'purchase_id' => $purchaseId,
            'product_id' => $this->product->id,
            'qty' => 5,
            'recieved' => 5,
            'purchase_unit_id' => $this->unitId,
            'net_unit_cost' => 120,
            'discount' => 0,
            'tax_rate' => 0,
            'tax' => 0,
            'total' => 600,
            'stock_details_json' => json_encode(['sph' => '-2.00', 'cyl' => '-0.50', 'axis' => '90', 'lr' => 'RE']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $validator->validateReturnLine($this->context, 'purchase', $ppId, ['sph' => '+2.00', 'cyl' => '-0.50'], 1, 'supplier_return');
            $this->fail('Wrong-power return should have failed validation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sph', $e->errors());
        }

        $ok = $validator->validateReturnLine($this->context, 'purchase', $ppId, ['sph' => '-2.00', 'cyl' => '-0.50', 'axis' => 90, 'lr' => 'RE'], 1, 'supplier_return');
        $this->assertTrue($ok['verified']);

        $queryResults = app(ProductQueryService::class)->search($this->product->code, $this->context);
        $this->assertNotEmpty($queryResults);
        $this->assertArrayHasKey('addition', $queryResults[0]);
        $this->assertSame('+1.50', $queryResults[0]['addition']);

        config()->set('ai_business_knowledge.opticals_enabled', false);
        $nonOpticalResults = app(ProductQueryService::class)->search($this->product->code, $this->context);
        $this->assertArrayNotHasKey('addition', $nonOpticalResults[0]);
    }
}
