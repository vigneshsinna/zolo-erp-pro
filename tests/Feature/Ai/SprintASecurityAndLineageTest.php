<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Tools\CustomerInvoices;
use App\Ai\Tools\InventoryStockSummary;
use App\Ai\Tools\InvoiceAllocations;
use App\Models\Accounting\AccountAllocation;
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
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

class SprintASecurityAndLineageTest extends TestCase
{
    use UsesAiMysql;

    protected User $user;
    protected Company $companyA;
    protected Company $companyB;
    protected int $branchA1Id;
    protected int $branchA2Id;
    protected int $branchBId;
    protected int $fyAId;
    protected int $fyBId;
    protected Customer $customerJohnDoe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'Sprint A Test Admin',
            'email' => 'sprint_a_admin_' . uniqid() . '@example.com',
            'phone' => '9999900001',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->actingAs($this->user);

        // 1. Company A with 2 Branches
        $this->companyA = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Sprint A Company A',
            'code' => 'SP-A-' . uniqid(),
            'status' => 'active',
        ]);

        if (!$this->companyA->users()->where('users.id', $this->user->id)->exists()) {
            $this->companyA->users()->attach($this->user->id, ['is_default' => true]);
        }

        $branch1 = $this->companyA->branches()->first() ?? $this->companyA->branches()->create([
            'name' => 'Branch A-1',
            'code' => 'BR-A1-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branchA1Id = $branch1->id;

        $branch2 = $this->companyA->branches()->where('id', '!=', $branch1->id)->first() ?? $this->companyA->branches()->create([
            'name' => 'Branch A-2',
            'code' => 'BR-A2-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branchA2Id = $branch2->id;

        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->companyA->id, 'user_id' => $this->user->id, 'branch_id' => $this->branchA1Id],
            ['company_id' => $this->companyA->id, 'user_id' => $this->user->id, 'branch_id' => $this->branchA1Id]
        );

        $fyA = FiscalYear::where('company_id', $this->companyA->id)->first() ?? FiscalYear::create([
            'company_id' => $this->companyA->id,
            'name' => 'FY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $this->fyAId = $fyA->id;

        // 2. Company B (Cross-Tenant)
        $this->companyB = Company::create([
            'legal_name' => 'Sprint A Company B',
            'trade_name' => 'Sprint A Company B',
            'code' => 'SP-B-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);
        $branchB = $this->companyB->branches()->create([
            'name' => 'Branch B-1',
            'code' => 'BR-B1-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branchBId = $branchB->id;
        $fyB = FiscalYear::create([
            'company_id' => $this->companyB->id,
            'name' => 'FY 2026 B',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $this->fyBId = $fyB->id;

        $this->withSession([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'financial_year_id' => $this->fyAId,
        ]);

        config(['erp_ai.enabled' => true]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, true);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->companyB->id, true, true);
    }

    /**
     * AI-001 & SEC-01 & SEC-02: Warehouse Branch Isolation & Fail-Closed Behavior
     */
    public function test_inventory_warehouse_branch_isolation_and_fail_closed(): void
    {
        $unit = Unit::create(['unit_code' => 'BOX', 'unit_name' => 'Boxes', 'is_active' => true]);
        $category = Category::where('is_active', true)->first() ?? Category::create(['name' => 'Cat ' . uniqid(), 'is_active' => true]);

        // Warehouse 1 in Branch A-1
        $whBranch1 = Warehouse::create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'name' => 'Warehouse Branch 1 ' . uniqid(),
            'address' => 'Test Address A1',
            'is_active' => true,
        ]);

        // Warehouse 2 in Branch A-2 (Foreign Branch in same company)
        $whBranch2 = Warehouse::create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA2Id,
            'name' => 'Warehouse Branch 2 ' . uniqid(),
            'address' => 'Test Address A2',
            'is_active' => true,
        ]);

        // Product with stock in both warehouses
        $product = Product::create([
            'company_id' => $this->companyA->id,
            'name' => 'Branch Isolated Product',
            'code' => 'BIP-' . uniqid(),
            'barcode_symbology' => 'code128',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id,
            'type' => 'standard',
            'cost' => '100.00',
            'price' => '150.00',
            'is_active' => true,
        ]);
        $product->company_id = $this->companyA->id;
        $product->save();

        // Put 50 units in Warehouse 1 (Branch 1) and 30 units in Warehouse 2 (Branch 2)
        DB::table('product_warehouse')->insert([
            ['product_id' => $product->id, 'warehouse_id' => $whBranch1->id, 'qty' => 50],
            ['product_id' => $product->id, 'warehouse_id' => $whBranch2->id, 'qty' => 30],
        ]);

        $principalBranch1 = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->companyA->id,
            branchId: $this->branchA1Id,
            financialYearId: $this->fyAId,
            permittedToolNames: ['inventory.stock_summary.v1'],
            requestId: 'sec-wh-01'
        );

        $tool = new InventoryStockSummary($principalBranch1);

        // 1. Unscoped warehouse query (warehouse_id = null): MUST only see Branch 1's 50 units!
        $resAll = json_decode((string) $tool->handle(new Request(['product_query' => $product->code])), true);
        $this->assertSame('ok', $resAll['status']);
        $this->assertSame(50.0, (float) $resAll['data']['top_products'][0]['on_hand'], 'Branch 1 actor must not see Branch 2 stock.');

        // 2. Querying foreign branch warehouse (whBranch2): MUST FAIL CLOSED with AI_TOOL_FORBIDDEN (SEC-02)
        $resForeign = json_decode((string) $tool->handle(new Request(['warehouse_id' => $whBranch2->id])), true);
        $this->assertSame('error', $resForeign['status']);
        $this->assertSame('AI_TOOL_FORBIDDEN', $resForeign['error_code']);
        $this->assertStringContainsString('belongs to a different branch', $resForeign['message']);

        // 3. Querying non-existent warehouse: MUST FAIL CLOSED with AI_TOOL_FORBIDDEN (SEC-02)
        $resNonExistent = json_decode((string) $tool->handle(new Request(['warehouse_id' => 999999])), true);
        $this->assertSame('error', $resNonExistent['status']);
        $this->assertSame('AI_TOOL_FORBIDDEN', $resNonExistent['error_code']);
    }

    /**
     * AI-003 & AI-007: Invoice-Level Outstanding Query & Screenshot Scenario ("What are the two invoices?")
     */
    public function test_customer_invoices_tool_returns_exact_invoices_and_matches_totals(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->companyA->id,
            'name' => 'John Doe',
            'phone_number' => '9999900010',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $account = ChartOfAccount::create([
            'company_id' => $this->companyA->id,
            'code' => '1100-' . uniqid(),
            'name' => 'Accounts Receivable Test',
            'type' => 'asset',
            'sub_type' => 'current_asset',
            'control_type' => 'ar',
        ]);

        $entry = JournalEntry::create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'financial_year_id' => $this->fyAId,
            'entry_number' => 'JE-' . uniqid(),
            'entry_date' => '2026-10-01',
            'posting_key' => 'PK-' . uniqid(),
            'status' => 'draft',
        ]);

        $jItem1 = JournalItem::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $account->id,
            'debit' => 15000.0000,
            'credit' => 0.0000,
            'entry_type' => 'debit',
        ]);

        $jItem2 = JournalItem::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $account->id,
            'debit' => 7656.0000,
            'credit' => 0.0000,
            'entry_type' => 'debit',
        ]);

        $entry->update(['status' => 'posted']);

        // Invoice 1: 15,000.0000 (overdue)
        $inv1 = AccountOpenItem::forceCreate([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'financial_year_id' => $this->fyAId,
            'journal_item_id' => $jItem1->id,
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'source_type' => 'sale',
            'source_id' => 101,
            'document_no' => 'INV-2026-001',
            'document_date' => '2026-09-01',
            'due_date' => '2026-09-20',
            'original_amount' => '15000.0000',
            'open_amount' => '15000.0000',
            'status' => 'open',
        ]);

        // Invoice 2: 7,656.0000 (current, not yet due)
        $inv2 = AccountOpenItem::forceCreate([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'financial_year_id' => $this->fyAId,
            'journal_item_id' => $jItem2->id,
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'source_type' => 'sale',
            'source_id' => 102,
            'document_no' => 'INV-2026-002',
            'document_date' => '2026-10-01',
            'due_date' => '2026-11-20',
            'original_amount' => '7656.0000',
            'open_amount' => '7656.0000',
            'status' => 'open',
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->companyA->id,
            branchId: $this->branchA1Id,
            financialYearId: $this->fyAId,
            permittedToolNames: ['receivables.invoices.v1'],
            requestId: 'inv-drill-01'
        );

        $tool = new CustomerInvoices($principal);
        $json = (string) $tool->handle(new Request(['customer_id' => $customer->id, 'as_of' => '2026-10-15']));
        $res = json_decode($json, true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('receivables.invoices.v1', $res['tool']);
        $this->assertSame('22656.0000', $res['data']['totals']['outstanding_amount'], 'Combined outstanding must equal 22,656.0000.');
        $this->assertSame('15000.0000', $res['data']['totals']['overdue_amount'], 'Only Invoice 1 is overdue.');
        $this->assertSame('7656.0000', $res['data']['totals']['not_yet_due_amount'], 'Invoice 2 is not yet due.');
        $this->assertSame(2, $res['data']['invoice_count'], 'Must accurately report exactly 2 invoices.');

        $invoices = $res['data']['invoices'];
        $this->assertCount(2, $invoices);
        $this->assertSame('INV-2026-001', $invoices[0]['invoice_number']);
        $this->assertSame('INV-2026-002', $invoices[1]['invoice_number']);
        $this->assertTrue($invoices[0]['is_overdue']);
        $this->assertFalse($invoices[1]['is_overdue']);
    }

    /**
     * AI-004: Payment Allocations Breakdown & Reversals Reconstructed Correctly
     */
    public function test_customer_invoice_allocations_tool_reconstructs_payment_history_and_reversals(): void
    {
        $group = CustomerGroup::first() ?? CustomerGroup::create(['name' => 'General', 'percentage' => '0', 'is_active' => true]);
        $customer = Customer::create([
            'company_id' => $this->companyA->id,
            'name' => 'Allocations Test Customer',
            'phone_number' => '9999900011',
            'customer_group_id' => $group->id,
            'is_active' => true,
        ]);

        $account = ChartOfAccount::create([
            'company_id' => $this->companyA->id,
            'code' => '1100-' . uniqid(),
            'name' => 'AR Test',
            'type' => 'asset',
            'sub_type' => 'current_asset',
            'control_type' => 'ar',
        ]);

        $entry = JournalEntry::create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'financial_year_id' => $this->fyAId,
            'entry_number' => 'JE-' . uniqid(),
            'entry_date' => '2026-10-01',
            'posting_key' => 'PK-' . uniqid(),
            'status' => 'draft',
        ]);

        $jItem = JournalItem::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $account->id,
            'debit' => 10000.0000,
            'credit' => 0.0000,
            'entry_type' => 'debit',
        ]);

        $jItemReceipt = JournalItem::create([
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $account->id,
            'debit' => 0.0000,
            'credit' => 3000.0000,
            'entry_type' => 'credit',
        ]);

        $entry->update(['status' => 'posted']);

        $invoiceItem = AccountOpenItem::forceCreate([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'financial_year_id' => $this->fyAId,
            'journal_item_id' => $jItem->id,
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'source_type' => 'sale',
            'source_id' => 201,
            'document_no' => 'INV-ALLOC-001',
            'document_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'original_amount' => '10000.0000',
            'open_amount' => '7000.0000',
            'status' => 'open',
        ]);

        $receiptItem = AccountOpenItem::forceCreate([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1Id,
            'financial_year_id' => $this->fyAId,
            'journal_item_id' => $jItemReceipt->id,
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'source_type' => 'payment',
            'source_id' => 301,
            'document_no' => 'RCPT-001',
            'document_date' => '2026-09-15',
            'due_date' => '2026-09-15',
            'original_amount' => '3000.0000',
            'open_amount' => '0.0000',
            'status' => 'closed',
        ]);

        // Allocation of 3,000.0000
        $alloc = AccountAllocation::forceCreate([
            'company_id' => $this->companyA->id,
            'allocation_no' => 'ALC-001',
            'idempotency_key' => 'IDEM-' . uniqid(),
            'debit_open_item_id' => $invoiceItem->id,
            'credit_open_item_id' => $receiptItem->id,
            'allocated_amount' => '3000.0000',
            'allocation_date' => '2026-09-15',
            'created_by' => $this->user->id,
        ]);

        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->companyA->id,
            branchId: $this->branchA1Id,
            financialYearId: $this->fyAId,
            permittedToolNames: ['receivables.invoice_allocations.v1'],
            requestId: 'alloc-drill-01'
        );

        $tool = new InvoiceAllocations($principal);
        $json = (string) $tool->handle(new Request(['open_item_id' => $invoiceItem->id, 'as_of' => '2026-10-01']));
        $res = json_decode($json, true);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('receivables.invoice_allocations.v1', $res['tool']);
        $this->assertSame('10000.0000', $res['data']['invoice']['original_amount']);
        $this->assertSame('3000.0000', $res['data']['invoice']['total_allocated']);
        $this->assertSame('7000.0000', $res['data']['invoice']['remaining_balance']);
        $this->assertSame('partially_paid', $res['data']['invoice']['status']);
        $this->assertCount(1, $res['data']['allocations']);
        $this->assertSame('RCPT-001', $res['data']['allocations'][0]['contra_document_no']);
    }

    /**
     * AI-008: Negative Cross-Tenant Isolation (Company A cannot read Company B records)
     */
    public function test_negative_cross_tenant_isolation_rejects_foreign_open_item(): void
    {
        $groupB = CustomerGroup::create(['name' => 'Group B', 'percentage' => '0', 'is_active' => true]);
        $customerB = Customer::create([
            'company_id' => $this->companyB->id,
            'name' => 'Foreign Company B Customer',
            'phone_number' => '9999900012',
            'customer_group_id' => $groupB->id,
            'is_active' => true,
        ]);

        $accountB = ChartOfAccount::create([
            'company_id' => $this->companyB->id,
            'code' => '1100-' . uniqid(),
            'name' => 'AR B',
            'type' => 'asset',
            'sub_type' => 'current_asset',
            'control_type' => 'ar',
        ]);

        $entryB = JournalEntry::create([
            'company_id' => $this->companyB->id,
            'branch_id' => $this->branchBId,
            'financial_year_id' => $this->fyBId,
            'entry_number' => 'JE-B-' . uniqid(),
            'entry_date' => '2026-10-01',
            'posting_key' => 'PK-B-' . uniqid(),
            'status' => 'draft',
        ]);

        $jItemB = JournalItem::create([
            'journal_entry_id' => $entryB->id,
            'chart_of_account_id' => $accountB->id,
            'debit' => 50000.0000,
            'credit' => 0.0000,
            'entry_type' => 'debit',
        ]);

        $entryB->update(['status' => 'posted']);

        $foreignOpenItem = AccountOpenItem::forceCreate([
            'company_id' => $this->companyB->id,
            'branch_id' => $this->branchBId,
            'financial_year_id' => $this->fyBId,
            'journal_item_id' => $jItemB->id,
            'journal_entry_id' => $entryB->id,
            'account_id' => $accountB->id,
            'party_type' => 'customer',
            'party_id' => $customerB->id,
            'source_type' => 'sale',
            'source_id' => 999,
            'document_no' => 'INV-FOREIGN-001',
            'document_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'original_amount' => '50000.0000',
            'open_amount' => '50000.0000',
            'status' => 'open',
        ]);

        // Principal for Company A attempting to inspect foreign open item
        $principalA = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->companyA->id,
            branchId: $this->branchA1Id,
            financialYearId: $this->fyAId,
            permittedToolNames: ['receivables.invoice_allocations.v1', 'receivables.invoices.v1'],
            requestId: 'sec-leak-01'
        );

        // Invoices query for customer B
        $invoicesTool = new CustomerInvoices($principalA);
        $resInvoices = json_decode((string) $invoicesTool->handle(new Request(['customer_id' => $customerB->id])), true);
        $this->assertSame('error', $resInvoices['status']);
        $this->assertSame('ENTITY_NOT_FOUND', $resInvoices['error_code']);

        // Allocations query for open item in Company B
        $allocTool = new InvoiceAllocations($principalA);
        $resAlloc = json_decode((string) $allocTool->handle(new Request(['open_item_id' => $foreignOpenItem->id])), true);
        $this->assertSame('error', $resAlloc['status']);
        $this->assertSame('ENTITY_NOT_FOUND', $resAlloc['error_code']);
    }
}
