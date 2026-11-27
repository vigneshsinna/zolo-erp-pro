<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Ai\Adapters\ServicePurchaseBillAdapter;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionPreparationService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\ApprovedActionExecutor;
use App\Models\Accounting\AccountOpenItem;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Biller;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialApplicationService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Verifies R08 commercial & financial reconciliation invariants:
 * - Manual ERP vs Approved AI sales invoice parity (stock movement count, balanced journal, AR open item, exact optical line identity)
 * - Service-only invoice creates zero physical inventory movements
 * - Expense-only payable without service item stays unsupported
 * - Partial payment receipt allocates against open invoice (credit terms never mark invoice paid)
 */
final class OpticalCommercialReconciliationAndParityTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private CompanyBranch $branch;
    private int $fyId;
    private Warehouse $warehouse;
    private Customer $customer;
    private Supplier $supplier;
    private Unit $unit;
    private Product $lensProduct;
    private Product $serviceProduct;
    private Biller $biller;
    private User $actor;
    private CompanyContext $context;
    private AiPrincipal $principal;

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
            'ai_business_knowledge.opticals_enabled' => true,
            'ai_business_knowledge.service_bill_enabled' => true,
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
        $fy = $this->company->fiscalYears()->where('status', 'open')->firstOrFail();
        $this->fyId = $fy->id;

        $this->actor = User::forceCreate([
            'name' => 'R08 Parity Actor',
            'email' => 'r08_'.Str::random(6).'@example.test',
            'phone' => '9833333331',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->actor->id);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->actor->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($this->actor);

        $this->context = new CompanyContext($this->company->id, $this->branch->id, $this->fyId);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->warehouse = Warehouse::forCompany($this->context)->where('is_active', true)->firstOrFail();
        $this->biller = Biller::forCompany($this->context)->where('is_active', true)->firstOrFail();

        $this->unit = Unit::forceCreate([
            'company_id' => $this->company->id,
            'unit_name' => 'Piece',
            'unit_code' => 'P08_'.Str::random(4),
            'is_active' => true,
        ]);
        $catId = Category::firstOrCreate(['company_id' => $this->company->id, 'name' => 'R08 Optics'], ['is_active' => true])->id;

        $this->lensProduct = Product::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'L08-'.Str::random(5),
            'name' => 'R08 Coated Lens',
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $catId,
            'unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'cost' => 100,
            'price' => 250,
            'qty' => 0,
            'is_active' => true,
        ]);

        $this->serviceProduct = Product::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'S08-'.Str::random(5),
            'name' => 'Edging & Fitting Service',
            'type' => 'service',
            'barcode_symbology' => 'C128',
            'category_id' => $catId,
            'unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'cost' => 0,
            'price' => 150,
            'qty' => 0,
            'is_active' => true,
        ]);

        $this->customer = Customer::forceCreate([
            'company_id' => $this->company->id,
            'customer_group_id' => 1,
            'name' => 'R08 Dealer Optics',
            'phone_number' => '9833333332',
            'is_active' => true,
        ]);

        $this->supplier = Supplier::forceCreate([
            'company_id' => $this->company->id,
            'name' => 'R08 Lens Vendor',
            'company_name' => 'R08 Lens Vendor Co',
            'phone_number' => '9833333333',
            'is_active' => true,
        ]);

        $keys = array_keys(app(ActionCapabilityRegistry::class)->all());
        $this->principal = new AiPrincipal(
            actorId: $this->actor->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fyId,
            permittedToolNames: $keys,
        );
    }

    public function test_manual_vs_approved_ai_sales_invoice_and_partial_receipt_reconcile_identically(): void
    {
        $comm = app(CommercialApplicationService::class);
        $prep = app(ActionPreparationService::class);
        $approve = app(ActionApprovalService::class);
        $exec = app(ApprovedActionExecutor::class);
        $date = now()->toDateString();

        // First replenish stock via physical purchase so ledger cost layers exist.
        $comm->create('purchase', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => $date,
            'status' => 1,
            'payment_status' => 1,
            'items' => [[
                'product_id' => $this->lensProduct->id,
                'qty' => 10,
                'purchase_unit_id' => $this->unit->id,
                'net_unit_cost' => 100,
                'discount' => 0,
                'tax_rate' => 0,
                'sph' => '-1.50',
                'cyl' => '-0.50',
                'axis' => 90,
                'lr' => 'RE',
            ]],
        ], 'manual-seed-'.Str::random(6), $this->actor->id, $this->context);

        // 1. Manual ERP Sales Invoice
        $manualSale = $comm->create('sale', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->biller->id,
            'business_date' => $date,
            'sale_status' => 1,
            'payment_status' => 1,
            'items' => [[
                'product_id' => $this->lensProduct->id,
                'qty' => 2,
                'sale_unit_id' => $this->unit->id,
                'net_unit_price' => 250,
                'discount' => 0,
                'tax_rate' => 0,
                'sph' => '-1.50',
                'cyl' => '-0.50',
                'axis' => 90,
                'lr' => 'RE',
            ]],
        ], 'manual-sale-'.Str::random(6), $this->actor->id, $this->context);
        $manualSaleId = (int) $manualSale->id;

        // 2. Approved AI Sales Invoice (identical optical spec and quantity)
        $aiProposal = $prep->prepare($this->principal, 'sales_invoice.create.v1', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->biller->id,
            'date' => $date,
            'items' => [[
                'product_id' => $this->lensProduct->id,
                'qty' => 2,
                'net_unit_price' => 250,
                'sph' => '-1.50',
                'cyl' => '-0.50',
                'axis' => 90,
                'lr' => 'RE',
            ]],
        ]);
        $this->assertSame('ok', $aiProposal['status'], json_encode($aiProposal));
        $proposalId = (string) $aiProposal['data']['proposal_id'];
        $approve->approve($this->principal, $proposalId, 1);
        $aiExec = $exec->execute($this->principal, $proposalId, 1);
        $aiSaleId = (int) $aiExec['receipt']['resource_id'];

        $manualMoves = DB::table('stock_movements')->where('company_id', $this->company->id)->where('source_type', 'sale')->where('source_id', $manualSaleId)->count();
        $aiMoves = DB::table('stock_movements')->where('company_id', $this->company->id)->where('source_type', 'sale')->where('source_id', $aiSaleId)->count();
        $this->assertSame(1, $manualMoves);
        $this->assertSame($manualMoves, $aiMoves);

        $manualJournal = JournalEntry::where('company_id', $this->company->id)->where('reference_type', 'sale')->where('reference_id', $manualSaleId)->firstOrFail();
        $aiJournal = JournalEntry::where('company_id', $this->company->id)->where('reference_type', 'sale')->where('reference_id', $aiSaleId)->firstOrFail();
        $this->assertEqualsWithDelta((float) $manualJournal->total_debit, (float) $aiJournal->total_debit, 0.01);
        $this->assertEqualsWithDelta((float) $aiJournal->total_debit, (float) $aiJournal->total_credit, 0.01);

        $aiLine = DB::table('product_sales')->where('sale_id', $aiSaleId)->first();
        $this->assertSame('-1.50', $aiLine->sph);
        $this->assertSame('90', (string) $aiLine->axis);
        $this->assertSame('R', $aiLine->lr);

        // Credit terms do NOT mark invoice paid; open item equals full invoice total.
        $openItem = AccountOpenItem::where('company_id', $this->company->id)
            ->where('source_type', 'sale')
            ->where('source_id', $aiSaleId)
            ->firstOrFail();
        $this->assertSame(500.0, (float) $openItem->open_amount);

        // 3. Partial payment receipt allocates against the open item cleanly.
        $cashAccount = ChartOfAccount::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->whereIn('control_type', ['cash', 'bank'])
            ->firstOrFail();
        $rcptProp = $prep->prepare($this->principal, 'payment_receipt.create.v1', [
            'customer_id' => $this->customer->id,
            'open_item_id' => $openItem->id,
            'settlement_account_id' => $cashAccount->id,
            'date' => $date,
            'amount' => '200.00',
            'note' => 'Partial NEFT receipt',
        ]);
        $this->assertSame('ok', $rcptProp['status']);
        $rcptId = (string) $rcptProp['data']['proposal_id'];
        $approve->approve($this->principal, $rcptId, 1);
        $exec->execute($this->principal, $rcptId, 1);

        $this->assertEqualsWithDelta(300.0, (float) $openItem->fresh()->open_amount, 0.01);

        // 4. Service-only sales invoice produces zero physical stock movements.
        $srvProp = $prep->prepare($this->principal, 'sales_invoice.create.v1', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $this->biller->id,
            'date' => $date,
            'items' => [[
                'product_id' => $this->serviceProduct->id,
                'qty' => 1,
                'net_unit_price' => 150,
            ]],
        ]);
        $this->assertSame('ok', $srvProp['status'], json_encode($srvProp));
        $srvId = (string) $srvProp['data']['proposal_id'];
        $approve->approve($this->principal, $srvId, 1);
        $srvExec = $exec->execute($this->principal, $srvId, 1);
        $srvSaleId = (int) $srvExec['receipt']['resource_id'];
        $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->where('source_type', 'sale')->where('source_id', $srvSaleId)->count());

        // 5. Expense-only AP without service item remains blocked (no dummy product).
        $this->expectException(ValidationException::class);
        $adapter = app(ServicePurchaseBillAdapter::class);
        $adapter->preview(
            app(ActionCapabilityRegistry::class)->get('purchase_bill.create.v1'),
            ['is_expense_bill' => true, 'supplier_id' => $this->supplier->id, 'items' => [['product_id' => $this->serviceProduct->id, 'qty' => 1, 'net_unit_cost' => 100]]],
            $this->context,
        );
    }
}
