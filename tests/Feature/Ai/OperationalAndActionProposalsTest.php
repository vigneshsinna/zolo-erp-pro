<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionProposalService;
use App\Ai\Tools\CompanyKnowledge;
use App\Ai\Tools\DocumentPreview;
use App\Ai\Tools\DraftProposal;
use App\Ai\Tools\JobWorkStatus;
use App\Ai\Tools\ManufacturingBoms;
use App\Ai\Tools\OpenOrders;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Accounting\FiscalYear;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

class OperationalAndActionProposalsTest extends TestCase
{
    use UsesAiMysql;

    protected User $user;
    protected Company $company;
    protected int $branch1Id;
    protected int $fyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Operational Admin',
            'email' => 'op_admin_' . uniqid() . '@example.com',
            'phone' => '9999900060',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->actingAs($this->user);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Op Test Company',
            'code' => 'OP-CO-' . uniqid(),
            'status' => 'active',
        ]);

        if (!$this->company->users()->where('users.id', $this->user->id)->exists()) {
            $this->company->users()->attach($this->user->id, ['is_default' => true]);
        }

        $b1 = $this->company->branches()->first() ?? $this->company->branches()->create([
            'name' => 'Branch Op-1',
            'code' => 'BR-OP-1-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branch1Id = $b1->id;

        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $this->branch1Id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        $fy = FiscalYear::where('company_id', $this->company->id)->first() ?? FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $this->fyId = $fy->id;

        $this->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
        ]);

        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
    }

    /**
     * P2: Manufacturing BOMs Tool
     */
    public function test_manufacturing_boms_tool_returns_recipe_components(): void
    {
        $unit = Unit::first() ?? Unit::create(['unit_code' => 'PCS', 'unit_name' => 'Pieces', 'is_active' => true]);
        $category = Category::where('is_active', true)->first() ?? Category::create(['name' => 'Cat ' . uniqid(), 'is_active' => true]);

        $finishedGood = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Finished Desk',
            'code' => 'FG-DSK-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '500.00',
            'price' => '1000.00',
            'is_active' => true,
        ]);

        $rawWood = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Raw Timber Plank',
            'code' => 'RAW-WD-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '100.00',
            'price' => '120.00',
            'is_active' => true,
        ]);

        $bomId = DB::table('boms')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'created_by' => $this->user->id,
            'effective_from' => '2026-04-01',
            'product_id' => $finishedGood->id,
            'code' => 'BOM-DSK-01',
            'version' => 1,
            'output_qty' => 1,
            'output_uom_id' => $unit->id,
            'is_default' => true,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bom_lines')->insert([
            'bom_id' => $bomId,
            'line_no' => 1,
            'component_product_id' => $rawWood->id,
            'qty' => 4,
            'uom_id' => $unit->id,
            'scrap_percent' => 5,
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['manufacturing.boms.v1'],
            requestId: 'mfg-test-01'
        );

        $tool = new ManufacturingBoms($principal);
        $res = json_decode((string) $tool->handle(new Request(['product_id' => $finishedGood->id])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('manufacturing.boms.v1', $res['tool']);
        $this->assertSame(1, $res['data']['total_boms_found']);
        $this->assertSame($finishedGood->name, $res['data']['boms'][0]['output_product']['name']);
        $this->assertSame(1, $res['data']['boms'][0]['component_count']);
        $this->assertSame($rawWood->name, $res['data']['boms'][0]['components'][0]['component_name']);
        $this->assertSame(4.0, (float) $res['data']['boms'][0]['components'][0]['qty']);
    }

    /**
     * P2: Open Orders Tool
     */
    public function test_open_orders_tool_lists_unfulfilled_sales_and_purchases(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Order Test Customer',
            'phone_number' => '9999900061',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $wh = Warehouse::where('company_id', $this->company->id)->where('branch_id', $this->branch1Id)->first() ?? Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'name' => 'WH Orders',
            'is_active' => true,
        ]);

        $biller = \App\Models\Biller::where('company_id', $this->company->id)->first() ?? \App\Models\Biller::create([
            'company_id' => $this->company->id,
            'name' => 'Order Biller',
            'code' => 'ORD-BIL-' . uniqid(),
            'is_active' => true,
        ]);

        $sale = Sale::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'warehouse_id' => $wh->id,
            'customer_id' => $customer->id,
            'biller_id' => $biller->id,
            'user_id' => $this->user->id,
            'reference_no' => 'SO-ORD-' . uniqid(),
            'item' => 2,
            'total_qty' => 5,
            'total_discount' => '0.00',
            'total_tax' => '0.00',
            'total_price' => '5000.00',
            'grand_total' => '5000.00',
            'paid_amount' => '2000.00',
            'sale_status' => 2, // pending fulfilment
            'payment_status' => 2,
            'created_at' => now(),
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['orders.open.v1'],
            requestId: 'orders-test-01'
        );

        $tool = new OpenOrders($principal);
        $res = json_decode((string) $tool->handle(new Request(['type' => 'sale'])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('orders.open.v1', $res['tool']);
        $this->assertGreaterThanOrEqual(1, $res['data']['summary']['total_open_orders']);
        $this->assertSame($sale->reference_no, $res['data']['orders'][0]['reference_no']);
        $this->assertSame('5000.00', $res['data']['orders'][0]['grand_total']);
        $this->assertSame('3000.00', $res['data']['orders'][0]['open_balance']);
    }

    /**
     * P3: Action Proposal Creation & Deterministic Preview
     */
    public function test_action_proposal_draft_creates_pending_proposal(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Proposal Target Customer',
            'phone_number' => '9999900062',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $unit = Unit::first() ?? Unit::create(['unit_code' => 'PCS', 'unit_name' => 'Pieces', 'is_active' => true]);
        $category = Category::where('is_active', true)->first() ?? Category::create(['name' => 'Cat ' . uniqid(), 'is_active' => true]);

        $product = Product::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'Proposal Widget',
            'code' => 'P-WID-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '100.00',
            'price' => '250.00',
            'is_active' => true,
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['proposals.draft.v1'],
            requestId: 'prop-test-01'
        );

        $tool = new DraftProposal($principal);
        $res = json_decode((string) $tool->handle(new Request([
            'action_type' => 'draft_quotation',
            'action_title' => 'Draft Quotation for ' . $customer->name,
            'payload' => [
                'customer_id' => $customer->id,
                'items' => [
                    ['product_id' => $product->id, 'qty' => 10, 'price' => 250],
                ],
                'note' => 'Special promotion pricing',
            ],
        ])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('proposals.draft.v1', $res['tool']);
        $this->assertSame('pending', $res['data']['status']);
        $this->assertSame('r1_draft', $res['data']['risk_tier']);
        $this->assertSame('2500.00', $res['data']['preview']['subtotal']);
        $this->assertSame('2625.00', $res['data']['preview']['grand_total']); // 2500 + 5% (125)

        // Verify DB persistence
        $this->assertDatabaseHas('ai_action_proposals', [
            'id' => $res['data']['proposal_id'],
            'company_id' => $this->company->id,
            'status' => 'pending',
        ]);
    }

    /**
     * P3: Human Approval & Safe Transactional Execution
     */
    public function test_human_approval_executes_action_proposal_safely(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Approve Customer',
            'phone_number' => '9999900063',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $service = app(ActionProposalService::class);
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['proposals.draft.v1'],
            requestId: 'approve-test-01'
        );

        $proposal = $service->propose(
            principal: $principal,
            actionType: 'draft_payment_reminder',
            actionTitle: 'Payment reminder for ' . $customer->name,
            payload: [
                'customer_id' => $customer->id,
                'amount' => '12500.00',
                'channel' => 'whatsapp',
            ]
        );

        $proposalId = $proposal['proposal_id'];

        // Approve and execute
        $execResult = $service->approve($principal, $proposalId, 'Authorized by Manager');

        $this->assertSame('executed', $execResult['status']);
        $this->assertSame($proposalId, $execResult['proposal_id']);
        $this->assertSame('dispatched', $execResult['execution']['status']);

        // Verify DB states
        $this->assertDatabaseHas('ai_action_proposals', [
            'id' => $proposalId,
            'status' => 'executed',
        ]);

        $this->assertDatabaseHas('ai_action_approvals', [
            'proposal_id' => $proposalId,
            'approver_id' => $this->user->id,
            'decision' => 'approved',
        ]);

        $this->assertDatabaseHas('ai_action_executions', [
            'proposal_id' => $proposalId,
            'status' => 'completed',
        ]);
    }

    /**
     * P3: Proposal Rejection
     */
    public function test_action_proposal_rejection_marks_proposal_rejected(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Reject Customer',
            'phone_number' => '9999900064',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $service = app(ActionProposalService::class);
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['proposals.draft.v1'],
            requestId: 'reject-test-01'
        );

        $proposal = $service->propose(
            principal: $principal,
            actionType: 'draft_payment_reminder',
            actionTitle: 'Declined reminder',
            payload: ['customer_id' => $customer->id, 'amount' => '500.00']
        );

        $rejectResult = $service->reject($principal, $proposal['proposal_id'], 'Payment already settled via bank');

        $this->assertSame('rejected', $rejectResult['status']);
        $this->assertDatabaseHas('ai_action_proposals', [
            'id' => $proposal['proposal_id'],
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('ai_action_approvals', [
            'proposal_id' => $proposal['proposal_id'],
            'decision' => 'rejected',
        ]);
    }

    /**
     * P4: Document Preview Tool
     */
    public function test_document_preview_tool_returns_verified_link(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Preview Customer',
            'phone_number' => '9999900065',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $wh = Warehouse::where('company_id', $this->company->id)->where('branch_id', $this->branch1Id)->first() ?? Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'name' => 'WH Preview',
            'is_active' => true,
        ]);

        $biller = \App\Models\Biller::where('company_id', $this->company->id)->first() ?? \App\Models\Biller::create([
            'company_id' => $this->company->id,
            'name' => 'Preview Biller',
            'code' => 'PREV-BIL-' . uniqid(),
            'is_active' => true,
        ]);

        $sale = Sale::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'warehouse_id' => $wh->id,
            'customer_id' => $customer->id,
            'biller_id' => $biller->id,
            'user_id' => $this->user->id,
            'reference_no' => 'INV-PREV-' . uniqid(),
            'item' => 1,
            'total_qty' => 2,
            'total_discount' => '0.00',
            'total_tax' => '0.00',
            'total_price' => '1200.00',
            'grand_total' => '1200.00',
            'paid_amount' => '1200.00',
            'sale_status' => 1,
            'payment_status' => 4,
            'created_at' => now(),
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['documents.preview.v1'],
            requestId: 'prev-test-01'
        );

        $tool = new DocumentPreview($principal);
        $res = json_decode((string) $tool->handle(new Request([
            'document_type' => 'sale',
            'document_id' => $sale->id,
        ])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('documents.preview.v1', $res['tool']);
        $this->assertSame($sale->reference_no, $res['data']['reference_no']);
        $this->assertSame($customer->name, $res['data']['party']['name']);
        $this->assertNotEmpty($res['data']['view_link']);
    }

    /**
     * P4: Company Knowledge Query Tool
     */
    public function test_company_knowledge_tool_returns_grounded_terms(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['knowledge.query.v1'],
            requestId: 'know-test-01'
        );

        $tool = new CompanyKnowledge($principal);
        $res = json_decode((string) $tool->handle(new Request(['topic' => 'payment_terms'])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('knowledge.query.v1', $res['tool']);
        $this->assertArrayHasKey('payment_terms', $res['data']['knowledge']);
        $this->assertSame(30, $res['data']['knowledge']['payment_terms']['default_due_days']);
    }
}
