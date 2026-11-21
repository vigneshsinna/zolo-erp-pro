<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\Adapters\PurchaseBillCommandAdapter;
use App\Ai\Adapters\PurchaseBillPreviewAdapter;
use App\Ai\Adapters\ServicePurchaseBillAdapter;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\AiPrincipal;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\CommercialOrderItem;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class FinancialDocumentContractsParityTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse;
    private Supplier $gstSupplier;
    private Supplier $unregisteredSupplier;
    private Product $goodsProduct;
    private Product $serviceProduct;
    private Unit $baseUnit;
    private Unit $purchaseUnit;
    private CompanyContext $context;
    private AiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('ai_business_knowledge.service_bill_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('compliance.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->actor = User::create([
            'name' => 'Parity Tester',
            'email' => 'parity_'.Str::random(8).'@example.test',
            'phone' => '98888'.rand(10000, 99999),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::create([
            'legal_name' => 'Parity Mills Ltd',
            'code' => 'PML_'.Str::random(8),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->create([
            'code' => 'PB1',
            'name' => 'Parity Branch 1',
            'branch_type' => 'branch',
            'is_active' => true,
        ]);

        $this->fiscalYear = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $this->company->users()->syncWithoutDetaching([
            $this->actor->id => ['is_default' => true],
        ]);

        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->actor->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Parity Central Godown',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'address' => '100 Mill Road',
            'is_active' => true,
        ]);

        $this->gstSupplier = Supplier::create([
            'name' => 'GST Regular Supplier',
            'company_name' => 'GST Regular Traders',
            'vat_number' => '33AABCT1234A1Z5',
            'phone_number' => '9842100001',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->unregisteredSupplier = Supplier::create([
            'name' => 'Unregistered Local Weaver',
            'company_name' => 'Weaver Co',
            'vat_number' => null,
            'phone_number' => '9842100002',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->baseUnit = Unit::create([
            'unit_code' => 'MTR',
            'unit_name' => 'Metre',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->purchaseUnit = Unit::create([
            'unit_code' => 'ROL',
            'unit_name' => 'Roll (50 Metres)',
            'base_unit' => $this->baseUnit->id,
            'operator' => '*',
            'operation_value' => 50,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $category = Category::firstOrCreate(['name' => 'Textiles'], ['is_active' => true]);

        $this->goodsProduct = Product::forceCreate([
            'name' => 'Cotton Greige Fabric',
            'code' => 'SKU-GREIGE-'.Str::random(4),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $category->id,
            'unit_id' => $this->baseUnit->id,
            'purchase_unit_id' => $this->purchaseUnit->id,
            'sale_unit_id' => $this->baseUnit->id,
            'cost' => 100.00,
            'price' => 150.00,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->serviceProduct = Product::forceCreate([
            'name' => 'Bleaching & Scouring Service',
            'code' => 'SRV-BLEACH-'.Str::random(4),
            'type' => 'service',
            'barcode_symbology' => 'C128',
            'category_id' => $category->id,
            'unit_id' => $this->baseUnit->id,
            'purchase_unit_id' => $this->baseUnit->id,
            'sale_unit_id' => $this->baseUnit->id,
            'cost' => 15.00,
            'price' => 25.00,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);
        $this->principal = new AiPrincipal(
            actorId: $this->actor->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            effectiveRole: 'admin',
        );

        $this->configurePostingAccounts();
    }

    private function configurePostingAccounts(): void
    {
        foreach (\App\Services\Accounting\SemanticAccountResolver::ROLES as $role => [$subType, $types]) {
            \App\Models\Accounting\ChartOfAccount::forceCreate([
                'company_id' => $this->company->id,
                'code' => 'COA-'.$role.'-'.Str::random(4),
                'name' => 'COA '.$role,
                'type' => is_array($types) ? $types[0] : $types,
                'sub_type' => $subType,
                'is_active' => true,
                'is_system' => true,
            ]);
        }
        app(\App\Services\Accounting\SemanticAccountResolver::class)->seedCompany($this->company->id);
    }

    public function test_non_gst_purchase_bill_calculates_zero_tax_and_posts_non_gst_document(): void
    {
        $previewAdapter = app(PurchaseBillPreviewAdapter::class);
        $commandAdapter = app(PurchaseBillCommandAdapter::class);
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get('purchase_bill.create.v1');

        $slots = [
            'supplier_id' => $this->unregisteredSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-05-10',
            'is_non_gst' => true,
            'bill_type' => 'non_gst',
            'supplier_invoice_no' => 'INV-NONGST-001',
            'items' => [
                [
                    'product_id' => $this->goodsProduct->id,
                    'qty' => 10,
                    'net_unit_cost' => 100.00,
                    'purchase_unit_id' => $this->baseUnit->id,
                ],
            ],
        ];

        // Preview succeeds even without GST profile
        $previewResult = $previewAdapter->preview($definition, $slots, $this->context);
        $this->assertTrue($previewResult->preview['policy']['is_non_gst']);
        $this->assertSame('0.00', $previewResult->preview['totals']['tax_total']);
        $this->assertSame('1000.00', $previewResult->preview['totals']['grand_total']);
        $this->assertSame('0.00', $previewResult->preview['totals']['tax_breakdown']['cgst']);
        $this->assertSame('0.00', $previewResult->preview['totals']['tax_breakdown']['sgst']);

        // Execute bill
        $idempotencyKey = (string) Str::uuid();
        $receipt = $commandAdapter->execute(
            $definition,
            $previewResult->payload,
            $this->context,
            $this->actor->id,
            $idempotencyKey,
            $previewResult->sourceStateHash,
        );

        $this->assertSame('completed', $receipt->status);
        $purchase = Purchase::findOrFail($receipt->resourceId);
        $this->assertTrue((bool) $purchase->is_non_gst);
        $this->assertEquals(0.00, (float) $purchase->total_tax);
        $this->assertEquals(1000.00, (float) $purchase->grand_total);
    }

    public function test_expense_only_ap_bill_request_fails_closed_with_explicit_guidance(): void
    {
        $previewAdapter = app(PurchaseBillPreviewAdapter::class);
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get('purchase_bill.create.v1');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('General ledger expense-only payable without service catalog remains unsupported by AI');

        $previewAdapter->preview($definition, [
            'supplier_id' => $this->gstSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'is_expense_bill' => true,
            'expense_account_id' => 5001,
            'items' => [
                ['product_id' => $this->goodsProduct->id, 'qty' => 1, 'net_unit_cost' => 500],
            ],
        ], $this->context);
    }

    public function test_service_bill_strictly_rejects_goods_items_and_moves_zero_inventory(): void
    {
        $serviceAdapter = app(ServicePurchaseBillAdapter::class);
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get('purchase_bill.service.create.v1');

        // Attempting to pass goods items into service bill must throw
        try {
            $serviceAdapter->preview($definition, [
                'supplier_id' => $this->gstSupplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->goodsProduct->id, 'qty' => 5, 'net_unit_cost' => 100],
                ],
            ], $this->context);
            $this->fail('Expected ValidationException when using goods items on a service bill.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only active service catalog items can be used', $e->getMessage());
        }

        // With valid service items, preview succeeds and explicitly documents zero inventory impact
        $preview = $serviceAdapter->preview($definition, [
            'supplier_id' => $this->unregisteredSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'is_non_gst' => true,
            'items' => [
                ['product_id' => $this->serviceProduct->id, 'qty' => 10, 'net_unit_cost' => 15.00],
            ],
        ], $this->context);

        $this->assertSame('no_stock_receipt', $preview->preview['inventory_effect']);
        $this->assertStringContainsString('zero inventory movements', $preview->preview['policy']['inventory_impact']);
    }

    public function test_uom_conversion_is_enforced_when_matching_bill_against_open_po(): void
    {
        // Create an open PO for 40 metres of cotton fabric (status 4: Ordered)
        $po = Purchase::forceCreate([
            'reference_no' => 'PO-UOM-001',
            'user_id' => $this->actor->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fiscalYear->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->unregisteredSupplier->id,
            'currency_id' => 1,
            'exchange_rate' => 1,
            'item' => 1,
            'total_qty' => 40,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 4000,
            'order_tax_rate' => 0,
            'order_tax' => 0,
            'order_discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 4000,
            'paid_amount' => 0,
            'status' => 4,
            'payment_status' => 1,
            'is_non_gst' => true,
        ]);

        CommercialOrderItem::forceCreate([
            'id' => 99881,
            'company_id' => $this->company->id,
            'order_kind' => 'purchase',
            'order_id' => $po->id,
            'product_id' => $this->goodsProduct->id,
            'uom_id' => $this->baseUnit->id,
            'qty' => 40.0,
            'qty_base' => 40.0,
            'cancelled_qty_base' => 0.0,
            'unit_rate' => 100.0,
        ]);

        $audit = app(\App\Ai\Knowledge\PurchaseBillMatchAudit::class);

        // Bill specifies 1 Roll (which is 50 Metres base UOM) -> Exceeds PO's 40 Metres open quantity
        $mismatchResult = $audit->inspect($this->context, [
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->unregisteredSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $this->goodsProduct->id,
                    'qty' => 1.0, // 1 roll = 50 metres
                    'purchase_unit_id' => $this->purchaseUnit->id,
                ],
            ],
        ]);

        $this->assertSame('mismatch', $mismatchResult['status']);
        $this->assertTrue(collect($mismatchResult['exceptions'])->contains(
            fn ($msg) => str_contains($msg, 'Billed quantity exceeds PO open quantity')
        ));

        // Bill specifies 30 Metres base UOM -> Fits within 40 Metres open quantity
        $validResult = $audit->inspect($this->context, [
            'purchase_order_id' => $po->id,
            'supplier_id' => $this->unregisteredSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $this->goodsProduct->id,
                    'qty' => 30.0,
                    'purchase_unit_id' => $this->baseUnit->id,
                ],
            ],
        ]);

        $this->assertSame('identity_verified_amounts_pending', $validResult['status']);
        $this->assertEmpty($validResult['exceptions']);
    }

    public function test_duplicate_idempotency_returns_original_receipt_without_double_posting(): void
    {
        $previewAdapter = app(PurchaseBillPreviewAdapter::class);
        $commandAdapter = app(PurchaseBillCommandAdapter::class);
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get('purchase_bill.create.v1');

        $slots = [
            'supplier_id' => $this->unregisteredSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'date' => '2026-05-12',
            'is_non_gst' => true,
            'supplier_invoice_no' => 'INV-IDEM-001',
            'items' => [
                [
                    'product_id' => $this->goodsProduct->id,
                    'qty' => 5,
                    'net_unit_cost' => 100.00,
                    'purchase_unit_id' => $this->baseUnit->id,
                ],
            ],
        ];

        $preview = $previewAdapter->preview($definition, $slots, $this->context);
        $idempotencyKey = (string) Str::uuid();

        $firstReceipt = $commandAdapter->execute(
            $definition,
            $preview->payload,
            $this->context,
            $this->actor->id,
            $idempotencyKey,
            $preview->sourceStateHash,
        );

        $countAfterFirst = Purchase::where('company_id', $this->company->id)->count();

        // Second execution with same idempotency key
        $secondReceipt = $commandAdapter->execute(
            $definition,
            $preview->payload,
            $this->context,
            $this->actor->id,
            $idempotencyKey,
            $preview->sourceStateHash,
        );

        $countAfterSecond = Purchase::where('company_id', $this->company->id)->count();

        $this->assertSame($firstReceipt->resourceId, $secondReceipt->resourceId);
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }
}
