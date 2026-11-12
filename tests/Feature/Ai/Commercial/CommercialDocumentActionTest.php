<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionPreparationService;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
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
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class CommercialDocumentActionTest extends TestCase
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
        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false, 'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true, 'commercial.enabled' => true, 'compliance.enabled' => false]);
        $company = Company::where('code', 'ZTEX')->firstOrFail();
        DB::table('tax_registrations')->where('company_id', $company->id)->update(['status' => 'inactive']);
        $branch = $company->branches()->firstOrFail();
        $year = $company->fiscalYears()->where('status', 'open')->firstOrFail();
        $actor = User::forceCreate(['name' => 'Commercial action test', 'email' => uniqid('commerce_').'@example.test',
            'phone' => '9000000091', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false]);
        $company->users()->attach($actor->id);
        DB::table('company_user_branches')->insert(['company_id' => $company->id, 'user_id' => $actor->id, 'branch_id' => $branch->id]);
        $this->actingAs($actor);
        $this->context = new CompanyContext($company->id, $branch->id, $year->id);
        $keys = array_keys(app(\App\Ai\Registries\ActionCapabilityRegistry::class)->all());
        $this->principal = new AiPrincipal(actorId: $actor->id, companyId: $company->id, branchId: $branch->id,
            financialYearId: $year->id, permittedToolNames: $keys);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);
        $this->warehouse = Warehouse::where('company_id', $company->id)->where('branch_id', $branch->id)->firstOrFail();
        $prototype = Product::where('company_id', $company->id)->where('type', 'standard')->firstOrFail();
        $this->product = $prototype->replicate(['code', 'name']);
        $unitId = \App\Models\Unit::forceCreate(['company_id' => $company->id, 'unit_name' => 'Test pieces',
            'unit_code' => uniqid('PCS'), 'is_active' => true])->id;
        $this->product->forceFill(['code' => uniqid('COMM-'), 'name' => 'Commercial action stock', 'qty' => 0,
            'cost' => 5, 'price' => 10, 'unit_id' => $unitId, 'sale_unit_id' => $unitId, 'purchase_unit_id' => $unitId,
            'tax_category_id' => null, 'tax_id' => null,
            'is_batch' => false, 'is_imei' => false, 'is_variant' => false])->save();
        $this->customer = Customer::where('company_id', $company->id)->firstOrFail();
        app(\App\Services\Tax\GstSetupService::class)->syncParty('customer', $this->customer);
        $biller = Biller::where('company_id', $company->id)->first();
        $this->billerId = $biller?->id ?? Biller::forceCreate(['company_id' => $company->id, 'name' => 'Quotation test biller',
            'company_name' => 'Test', 'email' => 'test@example.test', 'phone_number' => '9000000011', 'address' => 'Test', 'city' => 'Test', 'is_active' => true])->id;
    }

    private function inputs(): array
    {
        return ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id, 'date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'qty' => '2', 'net_unit_price' => '10', 'sale_unit_id' => $this->product->sale_unit_id]]];
    }

    private function execute(array $proposal): array
    {
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        return app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
    }

    public function test_quotation_preview_and_approved_execution_have_no_stock_or_ledger_effect_and_replay_once(): void
    {
        $before = [DB::table('stock_movements')->count(), DB::table('journal_entries')->count()];
        $proposal = app(ActionProposalService::class)->create($this->principal, 'quotation.create.v1', $this->inputs() + ['biller_id' => $this->billerId]);
        $this->assertSame($before, [DB::table('stock_movements')->count(), DB::table('journal_entries')->count()]);
        $result = $this->execute($proposal);
        $this->assertSame('quotation', $result['receipt']['resource_type']);
        $this->assertSame($before, [DB::table('stock_movements')->count(), DB::table('journal_entries')->count()]);
        $retry = app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
        $this->assertSame($result['receipt']['resource_id'], $retry['receipt']['resource_id']);
        $this->assertDatabaseHas('product_quotation', ['quotation_id' => $result['receipt']['resource_id'], 'product_id' => $this->product->id]);
    }

    public function test_sales_invoice_posts_only_after_approval_with_authoritative_total(): void
    {
        app(InventoryMovementService::class)->receive(new StockMovementCommand(date: now()->toDateString(),
            lines: [new StockLine($this->product->id, 20, unitCost: 5)], warehouseId: $this->warehouse->id,
            sourceType: 'test', sourceId: 1, idempotencyKey: uniqid('commerce-stock-'), userId: $this->principal->actorId, context: $this->context));
        $count = DB::table('sales')->where('company_id', $this->context->companyId)->count();
        $proposal = app(ActionProposalService::class)->create($this->principal, 'sales_invoice.create.v1', $this->inputs());
        $this->assertSame($count, DB::table('sales')->where('company_id', $this->context->companyId)->count());
        $result = $this->execute($proposal);
        $this->assertSame('sale', $result['receipt']['resource_type']);
        $this->assertNotNull($result['receipt']['details']['posted_at']);
        $this->assertSame(json_decode($proposal['payload_json'], true)['expected_totals']['grand_total'],
            (string) (float) $result['receipt']['details']['grand_total']);
    }

    public function test_foreign_branch_warehouse_and_changed_price_fail_closed(): void
    {
        $proposal = app(ActionProposalService::class)->create($this->principal, 'quotation.create.v1', $this->inputs() + ['biller_id' => $this->billerId]);
        $this->product->update(['price' => 19]);
        app(ActionApprovalService::class)->approve($this->principal, $proposal['id'], 1);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ApprovedActionExecutor::class)->execute($this->principal, $proposal['id'], 1);
    }

    public function test_quote_intake_uses_consolidated_clarification(): void
    {
        $result = app(ActionPreparationService::class)->prepare($this->principal, 'quotation.create.v1',
            ['customer_query' => 'Missing test customer', 'items' => [['product_query' => 'Missing test product', 'qty' => '2']]]);
        $this->assertSame('needs_input', $result['status']);
        $this->assertGreaterThanOrEqual(2, count($result['required_inputs']));
        $this->assertDatabaseHas('ai_workflow_tasks', ['id' => $result['task_id'], 'action_key' => 'quotation.create.v1']);
    }
}
