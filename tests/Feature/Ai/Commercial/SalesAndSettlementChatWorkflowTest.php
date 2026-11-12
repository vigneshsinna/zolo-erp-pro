<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\Agents\ErpCopilot;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
use App\Ai\Tools\PreparePaymentReceipt;
use App\Ai\Tools\PreparePaymentReminder;
use App\Ai\Tools\PrepareQuotation;
use App\Ai\Tools\PrepareSalesInvoice;
use App\Models\Accounting\Account;
use App\Models\Biller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class SalesAndSettlementChatWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private AiPrincipal $principal;
    private CompanyContext $context;
    private Product $product;
    private Customer $customer;
    private Warehouse $warehouse;
    private int $billerId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'commercial.enabled' => true,
            'compliance.enabled' => false,
        ]);

        $company = Company::where('code', 'ZTEX')->firstOrFail();
        DB::table('tax_registrations')->where('company_id', $company->id)->update(['status' => 'inactive']);
        $branch = $company->branches()->firstOrFail();
        $year = $company->fiscalYears()->where('status', 'open')->firstOrFail();

        $actor = User::forceCreate([
            'name' => 'Sales Settlement Tester',
            'email' => uniqid('sales_settle_') . '@example.test',
            'phone' => '9000000092',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $company->users()->attach($actor->id);
        DB::table('company_user_branches')->insert([
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($actor);
        $this->context = new CompanyContext($company->id, $branch->id, $year->id);
        \App\Ai\Registries\ExtendedActionCatalog::register(app(\App\Ai\Registries\ActionCapabilityRegistry::class));
        $keys = array_keys(app(\App\Ai\Registries\ActionCapabilityRegistry::class)->all());
        $this->principal = new AiPrincipal(
            actorId: $actor->id,
            companyId: $company->id,
            branchId: $branch->id,
            financialYearId: $year->id,
            permittedToolNames: $keys,
        );

        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);
        $this->warehouse = Warehouse::where('company_id', $company->id)->where('branch_id', $branch->id)->firstOrFail();

        $unitId = \App\Models\Unit::forceCreate([
            'company_id' => $company->id,
            'unit_name' => 'Pieces',
            'unit_code' => uniqid('PCS'),
            'is_active' => true,
        ])->id;

        $prototype = Product::where('company_id', $company->id)->where('type', 'standard')->firstOrFail();
        $this->product = $prototype->replicate(['code', 'name']);
        $this->product->forceFill([
            'code' => uniqid('SLS-'),
            'name' => 'Sales Settlement Stock',
            'qty' => 0,
            'cost' => 10,
            'price' => 20,
            'unit_id' => $unitId,
            'sale_unit_id' => $unitId,
            'purchase_unit_id' => $unitId,
            'tax_category_id' => null,
            'tax_id' => null,
            'is_batch' => false,
            'is_imei' => false,
            'is_variant' => false,
        ])->save();

        $this->customer = Customer::where('company_id', $company->id)->firstOrFail();
        app(\App\Services\Tax\GstSetupService::class)->syncParty('customer', $this->customer);

        $biller = Biller::where('company_id', $company->id)->first();
        $this->billerId = $biller?->id ?? Biller::forceCreate([
            'company_id' => $company->id,
            'name' => 'Sales Workflow Biller',
            'company_name' => 'Sales Biller Co',
            'email' => 'salesbiller@example.test',
            'phone_number' => '9000000012',
            'address' => 'HQ Address',
            'city' => 'City',
            'is_active' => true,
        ])->id;
    }

    public function test_erp_copilot_registers_sales_and_settlement_tools(): void
    {
        $copilot = new ErpCopilot($this->principal);
        $toolNames = array_map(fn ($tool) => is_object($tool) ? (method_exists($tool, 'name') ? $tool->name() : get_class($tool)) : (string) $tool, (array) $copilot->tools());

        $this->assertContains('prepare_quotation', $toolNames);
        $this->assertContains('prepare_sales_invoice', $toolNames);
        $this->assertContains('prepare_payment_receipt', $toolNames);
        $this->assertContains('prepare_payment_reminder', $toolNames);
        $this->assertContains('prepare_stock_transfer', $toolNames);
        $this->assertContains('prepare_stock_adjustment', $toolNames);
        $this->assertContains('prepare_journal_entry', $toolNames);
        $this->assertContains('prepare_production_order', $toolNames);
    }

    public function test_prepare_quotation_tool_creates_proposal_and_executes_into_quotation(): void
    {
        $tool = new PrepareQuotation($this->principal);
        $request = new ToolRequest([
            'customer_query' => $this->customer->name,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->billerId,
            'items' => [
                [
                    'product_query' => $this->product->name,
                    'qty' => 5,
                    'net_unit_price' => 25,
                ],
            ],
        ]);

        $responseJson = $tool->handle($request);
        $response = json_decode($responseJson, true);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('quotation.create.v1', $response['tool']);
        $this->assertNotEmpty($response['data']['proposal_id']);

        $proposalId = $response['data']['proposal_id'];
        app(ActionApprovalService::class)->approve($this->principal, $proposalId, 1);
        $execution = app(ApprovedActionExecutor::class)->execute($this->principal, $proposalId, 1);

        $this->assertSame('completed', $execution['status']);
        $this->assertSame('quotation', $execution['receipt']['resource_type']);
        $quotationId = $execution['receipt']['resource_id'];

        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'company_id' => $this->principal->companyId,
            'customer_id' => $this->customer->id,
        ]);
        $this->assertDatabaseHas('product_quotation', [
            'quotation_id' => $quotationId,
            'product_id' => $this->product->id,
            'qty' => 5,
        ]);
    }

    public function test_prepare_sales_invoice_tool_creates_proposal_and_posts_invoice(): void
    {
        // Receive initial stock so inventory is available
        app(InventoryMovementService::class)->receive(new StockMovementCommand(
            date: now()->toDateString(),
            lines: [new StockLine($this->product->id, 20, unitCost: 10, uomId: $this->product->unit_id)],
            warehouseId: $this->warehouse->id,
            context: $this->context,
            userId: $this->principal->actorId,
        ));

        $tool = new PrepareSalesInvoice($this->principal);
        $request = new ToolRequest([
            'customer_query' => $this->customer->name,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->billerId,
            'items' => [
                [
                    'product_query' => $this->product->name,
                    'qty' => 3,
                    'net_unit_price' => 30,
                ],
            ],
        ]);

        $responseJson = $tool->handle($request);
        $response = json_decode($responseJson, true);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('sales_invoice.create.v1', $response['tool']);

        $proposalId = $response['data']['proposal_id'];
        app(ActionApprovalService::class)->approve($this->principal, $proposalId, 1);
        $execution = app(ApprovedActionExecutor::class)->execute($this->principal, $proposalId, 1);

        $this->assertSame('completed', $execution['status']);
        $this->assertSame('sale', $execution['receipt']['resource_type']);
        $saleId = $execution['receipt']['resource_id'];

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'company_id' => $this->principal->companyId,
            'customer_id' => $this->customer->id,
        ]);
    }
}
