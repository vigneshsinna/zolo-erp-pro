<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiToolManifest;
use App\Models\Accounting\AccountOpenItem;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalItem;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

class SprintBAndP2CapabilitiesTest extends TestCase
{
    use UsesAiMysql;

    protected User $user;
    protected Company $company;
    protected int $branch1Id;
    protected int $branch2Id;
    protected int $fyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'P1 Test Admin',
            'email' => 'p1_admin_' . uniqid() . '@example.com',
            'phone' => '9999900050',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->actingAs($this->user);

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'P1 Test Company',
            'code' => 'P1-CO-' . uniqid(),
            'status' => 'active',
        ]);

        if (!$this->company->users()->where('users.id', $this->user->id)->exists()) {
            $this->company->users()->attach($this->user->id, ['is_default' => true]);
        }

        $b1 = $this->company->branches()->first() ?? $this->company->branches()->create([
            'name' => 'Branch P1-1',
            'code' => 'BR-P1-1-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branch1Id = $b1->id;

        $b2 = $this->company->branches()->where('id', '!=', $b1->id)->first() ?? $this->company->branches()->create([
            'name' => 'Branch P1-2',
            'code' => 'BR-P1-2-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branch2Id = $b2->id;

        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $this->branch1Id],
            ['created_at' => now(), 'updated_at' => now()]
        );
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $this->branch2Id],
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

        config(['erp_ai.enabled' => true]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);
    }

    /**
     * AI-013: Sales Analytics & Top Products Tool
     */
    public function test_sales_summary_tool_returns_aggregates_and_top_products(): void
    {
        $wh1 = Warehouse::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'name' => 'WH Sales 1 ' . uniqid(),
            'address' => 'Addr 1',
            'is_active' => true,
        ]);

        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'Sales Test Customer',
            'phone_number' => '9999900051',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $biller = \App\Models\Biller::where('company_id', $this->company->id)->first() ?? \App\Models\Biller::create([
            'company_id' => $this->company->id,
            'name' => 'Main Biller',
            'code' => 'BIL-' . uniqid(),
            'is_active' => true,
        ]);

        $unit = Unit::first() ?? Unit::create(['unit_code' => 'PCS', 'unit_name' => 'Pieces', 'is_active' => true]);
        $category = Category::where('is_active', true)->first() ?? Category::create(['name' => 'Cat ' . uniqid(), 'is_active' => true]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Widget Pro',
            'code' => 'WPRO-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'cost' => '100.00',
            'price' => '200.00',
            'is_active' => true,
        ]);
        $product->company_id = $this->company->id;
        $product->save();

        // Create Sale
        $sale = Sale::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'warehouse_id' => $wh1->id,
            'customer_id' => $customer->id,
            'biller_id' => $biller->id,
            'user_id' => $this->user->id,
            'reference_no' => 'SO-' . uniqid(),
            'item' => 1,
            'total_qty' => 10,
            'total_discount' => '50.00',
            'total_tax' => '150.00',
            'total_price' => '1950.00',
            'grand_total' => '2100.00',
            'paid_amount' => '2100.00',
            'sale_status' => 1,
            'payment_status' => 4,
            'created_at' => '2026-10-15 10:00:00',
        ]);

        DB::table('product_sales')->insert([
            'company_id' => $this->company->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'qty' => 10,
            'sale_unit_id' => $unit->id,
            'net_unit_price' => 200,
            'discount' => 50,
            'tax' => 150,
            'tax_rate' => 0,
            'total' => 2100,
            'created_at' => '2026-10-15 10:00:00',
        ]);

        // A posted credit reduces invoiced sales. A draft credit contributes nothing.
        $credit = [
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'warehouse_id' => $wh1->id,
            'customer_id' => $customer->id,
            'biller_id' => $biller->id,
            'user_id' => $this->user->id,
            'sale_id' => $sale->id,
            'account_id' => 0,
            'item' => 0,
            'total_qty' => 0,
            'total_discount' => 0,
            'total_tax' => 10,
            'total_price' => 100,
            'order_tax_rate' => 0,
            'order_tax' => 0,
            'grand_total' => 110,
            'note_type' => 'credit',
            'created_at' => '2026-10-20 00:00:00',
        ];
        DB::table('returns')->insert([
            $credit + ['status' => 'posted', 'reference_no' => 'POSTED-' . uniqid()],
            $credit + ['status' => 'draft', 'reference_no' => 'DRAFT-' . uniqid()],
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['sales.summary.v1'],
            requestId: 'sales-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('sales.summary.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request(['from' => '2026-10-01', 'to' => '2026-10-31'])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('sales.summary.v1', $res['tool']);
        $this->assertSame(1, $res['data']['totals']['sales_count']);
        $this->assertSame('2100.00', $res['data']['totals']['gross_sales']);
        $this->assertSame('140.00', $res['data']['totals']['total_tax']);
        $this->assertSame('1990.00', $res['data']['totals']['net_sales']);
        $this->assertSame('-110.00', $res['data']['totals']['due_amount']);
        $this->assertCount(1, $res['data']['top_products']);
        $this->assertSame($product->code, $res['data']['top_products'][0]['code']);
        $this->assertSame(10.0, (float) $res['data']['top_products'][0]['total_qty']);
    }

    /**
     * AI-012: Supplier AP & Payables Summary Tool
     */
    public function test_payables_summary_tool_reconciles_supplier_dues(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->company->id,
            'name' => 'Acme Supplies ' . uniqid(),
            'phone_number' => '9999900052',
            'is_active' => true,
        ]);

        $apAccount = ChartOfAccount::create([
            'company_id' => $this->company->id,
            'code' => '2100-' . uniqid(),
            'name' => 'AP Control Test',
            'type' => 'liability',
            'sub_type' => 'current_liability',
            'control_type' => 'ap',
        ]);

        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'entry_number' => 'JE-AP-' . uniqid(),
            'entry_date' => '2026-09-01',
            'posting_key' => 'PK-AP-' . uniqid(),
            'status' => 'draft',
        ]);

        $jItem = JournalItem::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $apAccount->id,
            'debit' => 0.0000,
            'credit' => 35000.0000,
            'entry_type' => 'credit',
        ]);

        $entry->update(['status' => 'posted']);

        AccountOpenItem::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1Id,
            'financial_year_id' => $this->fyId,
            'journal_item_id' => $jItem->id,
            'journal_entry_id' => $entry->id,
            'account_id' => $apAccount->id,
            'party_type' => 'supplier',
            'party_id' => $supplier->id,
            'source_type' => 'purchase',
            'source_id' => 888,
            'document_no' => 'BILL-SUP-001',
            'document_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'original_amount' => '35000.0000',
            'open_amount' => '35000.0000',
            'status' => 'open',
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['payables.summary.v1'],
            requestId: 'ap-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('payables.summary.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request(['as_of' => '2026-10-15'])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('payables.summary.v1', $res['tool']);
        $this->assertSame('35000.0000', $res['data']['total_outstanding']);
        $this->assertSame(1, $res['data']['supplier_count_with_dues']);
        $this->assertSame($supplier->name, $res['data']['top_suppliers'][0]['name']);
    }

    /**
     * AI-015: Accounting Statements (Profit & Loss / Balance Sheet / Trial Balance)
     */
    public function test_financial_statements_tool_generates_pnl_and_balance_sheet(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['accounting.statements.v1'],
            requestId: 'stmt-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('accounting.statements.v1', $principal);

        // 1. P&L Statement
        $resPnl = json_decode((string) $tool->handle(new Request(['statement_type' => 'profit_and_loss'])), true);
        $this->assertSame('ok', $resPnl['status']);
        $this->assertSame('accounting.statements.v1', $resPnl['tool']);
        $this->assertSame('profit_and_loss', $resPnl['data']['statement_type']);
        $this->assertArrayHasKey('total_revenue', $resPnl['data']['summary']);
        $this->assertArrayHasKey('net_income', $resPnl['data']['summary']);

        // 2. Balance Sheet Statement
        $resBs = json_decode((string) $tool->handle(new Request(['statement_type' => 'balance_sheet'])), true);
        $this->assertSame('ok', $resBs['status']);
        $this->assertSame('balance_sheet', $resBs['data']['statement_type']);
        $this->assertArrayHasKey('total_assets', $resBs['data']['summary']);
        $this->assertArrayHasKey('total_liabilities', $resBs['data']['summary']);
        $this->assertArrayHasKey('total_equity', $resBs['data']['summary']);
    }

    /**
     * AI-024 (P2): GST Tax Summary Breakdown
     */
    public function test_gst_summary_tool_returns_tax_projections(): void
    {
        $line = [
            'supply_type' => 'domestic', 'reverse_charge' => false, 'input_credit_allowed' => true,
            'taxable_value' => '100.0000', 'cgst' => '9.0000', 'sgst' => '9.0000', 'igst' => '0.0000', 'cess' => '0.0000',
        ];
        foreach (['sale', 'purchase'] as $index => $type) {
            DB::table('gst_transaction_projections')->insert([
                'company_id' => $this->company->id, 'branch_id' => $this->branch1Id, 'financial_year_id' => $this->fyId,
                'source_type' => $type, 'source_id' => 900000 + $index, 'document_no' => 'GST-AI-' . $type,
                'document_date' => '2026-10-24', 'registration_gstin' => '', 'version' => 'fixture',
                'snapshot_json' => json_encode(['lines' => [$line], 'buyer_gstin' => null], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['tax.gst_summary.v1'],
            requestId: 'gst-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('tax.gst_summary.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request(['from' => '2026-10-24', 'to' => '2026-10-24'])), true);
        $this->assertSame('ok', $res['status']);
        $this->assertSame('tax.gst_summary.v1', $res['tool']);
        $this->assertArrayHasKey('total_taxable_value', $res['data']);
        $this->assertArrayHasKey('total_tax_liability', $res['data']);
        $this->assertArrayHasKey('sections', $res['data']);
        $this->assertSame('18.00', $res['data']['total_tax_liability']);
        $this->assertSame('9.00', $res['data']['sections']['eligible_input_credit']['cgst']);
    }

    /**
     * AI-030 (P2): Copilot Help & Tool Catalog
     */
    public function test_copilot_help_tool_returns_authorized_catalog(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branch1Id,
            financialYearId: $this->fyId,
            permittedToolNames: ['sales.summary.v1', 'payables.summary.v1', 'copilot.help.v1'],
            requestId: 'help-test-01'
        );

        $tool = app(AiToolManifest::class)->sdkTool('copilot.help.v1', $principal);
        $res = json_decode((string) $tool->handle(new Request([])), true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('copilot.help.v1', $res['tool']);
        $this->assertGreaterThanOrEqual(3, $res['data']['total_authorized_tools']);
        $this->assertNotEmpty($res['data']['suggested_questions']);
    }
}
