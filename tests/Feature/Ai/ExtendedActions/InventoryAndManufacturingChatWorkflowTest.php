<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ExtendedActions;

use App\Ai\Agents\ErpCopilot;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Registries\ExtendedActionCatalog;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
use App\Ai\Tools\PrepareJournalEntry;
use App\Ai\Tools\PrepareProductionOrder;
use App\Ai\Tools\PrepareStockAdjustment;
use App\Ai\Tools\PrepareStockTransfer;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
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

final class InventoryAndManufacturingChatWorkflowTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private CompanyContext $context;
    private AiPrincipal $principal;
    private Product $product;
    private Warehouse $sourceWarehouse;
    private Warehouse $destinationWarehouse;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
        ]);

        $company = Company::where('code', 'ZTEX')->firstOrFail();
        $branch = $company->branches()->firstOrFail();
        $year = $company->fiscalYears()->where('status', 'open')->firstOrFail();

        $this->actor = User::forceCreate([
            'name' => 'Extended Action Tester',
            'email' => uniqid('ext_chat_') . '@example.test',
            'phone' => '9000000093',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $company->users()->attach($this->actor->id);
        DB::table('company_user_branches')->insert([
            'company_id' => $company->id,
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($this->actor);
        $this->context = new CompanyContext($company->id, $branch->id, $year->id);
        ExtendedActionCatalog::register(app(ActionCapabilityRegistry::class));
        $keys = array_keys(app(ActionCapabilityRegistry::class)->all());
        $this->principal = new AiPrincipal(
            actorId: $this->actor->id,
            companyId: $company->id,
            branchId: $branch->id,
            financialYearId: $year->id,
            permittedToolNames: $keys,
        );

        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);

        $this->sourceWarehouse = Warehouse::where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->firstOrFail();

        $this->destinationWarehouse = Warehouse::forceCreate([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'name' => 'Destination Branch WH',
            'address' => 'Test Dest',
            'is_active' => true,
        ]);

        $unit = Unit::where('company_id', $company->id)->first();
        if (!$unit) {
            $unit = Unit::forceCreate([
                'company_id' => $company->id,
                'unit_code' => 'MTR',
                'unit_name' => 'Meter',
                'is_active' => true,
            ]);
        }

        $prototype = Product::where('company_id', $company->id)->where('type', 'standard')->firstOrFail();
        $this->product = $prototype->replicate(['code', 'name']);
        $this->product->forceFill([
            'code' => uniqid('EXT-INV-'),
            'name' => 'Inventory Action Stock',
            'qty' => 0,
            'cost' => 15,
            'price' => 30,
            'unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'is_batch' => false,
            'is_imei' => false,
            'is_variant' => false,
        ])->save();
    }

    public function test_prepare_stock_transfer_creates_proposal_and_executes(): void
    {
        // Provide stock in source warehouse
        app(InventoryMovementService::class)->receive(new StockMovementCommand(
            date: now()->toDateString(),
            lines: [new StockLine($this->product->id, 25, unitCost: 15, uomId: $this->product->unit_id)],
            warehouseId: $this->sourceWarehouse->id,
            context: $this->context,
            userId: $this->principal->actorId,
        ));

        $tool = new PrepareStockTransfer($this->principal);
        $request = new ToolRequest([
            'warehouse_id' => $this->sourceWarehouse->id,
            'to_warehouse_id' => $this->destinationWarehouse->id,
            'reason' => 'Showroom replenishment',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 10,
                ],
            ],
        ]);

        $responseJson = $tool->handle($request);
        $response = json_decode($responseJson, true);

        $this->assertSame('ok', $response['status']);
        $proposalId = $response['data']['proposal_id'];

        app(ActionApprovalService::class)->approve($this->principal, $proposalId, 1);
        $execution = app(ApprovedActionExecutor::class)->execute($this->principal, $proposalId, 1);

        $this->assertSame('completed', $execution['status']);
        $this->assertSame('stock_movement', $execution['receipt']['resource_type']);
    }

    public function test_prepare_stock_adjustment_creates_proposal_and_executes(): void
    {
        // Provide stock to adjust
        app(InventoryMovementService::class)->receive(new StockMovementCommand(
            date: now()->toDateString(),
            lines: [new StockLine($this->product->id, 20, unitCost: 15, uomId: $this->product->unit_id)],
            warehouseId: $this->sourceWarehouse->id,
            context: $this->context,
            userId: $this->principal->actorId,
        ));

        $tool = new PrepareStockAdjustment($this->principal);
        $request = new ToolRequest([
            'warehouse_id' => $this->sourceWarehouse->id,
            'reason' => 'Shrinkage audit write-off',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => -3,
                ],
            ],
        ]);

        $responseJson = $tool->handle($request);
        $response = json_decode($responseJson, true);

        $this->assertSame('ok', $response['status']);
        $proposalId = $response['data']['proposal_id'];

        app(ActionApprovalService::class)->approve($this->principal, $proposalId, 1);
        $execution = app(ApprovedActionExecutor::class)->execute($this->principal, $proposalId, 1);

        $this->assertSame('completed', $execution['status']);
    }

    public function test_prepare_journal_entry_creates_balanced_proposal_and_posts(): void
    {
        $debitAcc = ChartOfAccount::forceCreate([
            'company_id' => $this->principal->companyId,
            'name' => 'Test Debit Expense Account',
            'code' => uniqid('EXP-'),
            'type' => 'expense',
            'sub_type' => 'operating_expense',
            'is_active' => true,
        ]);

        $creditAcc = ChartOfAccount::forceCreate([
            'company_id' => $this->principal->companyId,
            'name' => 'Test Credit Liability Account',
            'code' => uniqid('LIA-'),
            'type' => 'liability',
            'sub_type' => 'current_liability',
            'is_active' => true,
        ]);

        $tool = new PrepareJournalEntry($this->principal);
        $request = new ToolRequest([
            'description' => 'Month-end accrual journal entry',
            'items' => [
                [
                    'chart_of_account_id' => $debitAcc->id,
                    'debit' => '250.0000',
                ],
                [
                    'chart_of_account_id' => $creditAcc->id,
                    'credit' => '250.0000',
                ],
            ],
        ]);

        $responseJson = $tool->handle($request);
        $response = json_decode($responseJson, true);

        $this->assertSame('ok', $response['status']);
        $proposalId = $response['data']['proposal_id'];

        app(ActionApprovalService::class)->approve($this->principal, $proposalId, 1);
        $execution = app(ApprovedActionExecutor::class)->execute($this->principal, $proposalId, 1);

        $this->assertSame('completed', $execution['status']);
        $this->assertSame('journal_entry', $execution['receipt']['resource_type']);
    }
}
