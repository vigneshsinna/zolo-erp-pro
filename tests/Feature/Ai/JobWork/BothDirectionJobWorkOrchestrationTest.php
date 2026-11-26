<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\JobWork;

use App\Ai\Adapters\ExtendedActionAdapter;
use App\Ai\Adapters\JobWorkOrderActionAdapter;
use App\Ai\Adapters\JobWorkServiceBillActionAdapter;
use App\Ai\Adapters\PrincipalJobInvoiceActionAdapter;
use App\Ai\Adapters\PrincipalJobOrderActionAdapter;
use App\Ai\Adapters\PrincipalJobReceiveActionAdapter;
use App\Ai\Adapters\PrincipalJobReturnActionAdapter;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Registries\ActionCapabilityRegistry;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Operations\JobWorkOrder;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\JobWork\PrincipalJobService;
use App\Services\Platform\CapabilityService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class BothDirectionJobWorkOrchestrationTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse;
    private Supplier $jobWorker;
    private Customer $customer;
    private Product $rawFabric;
    private Product $dyedFabric;
    private Product $dyeingService;
    private Unit $unit;
    private int $processTypeId;
    private CompanyContext $context;
    private AiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_business_knowledge.enabled', true);
        config()->set('ai_business_knowledge.job_work_enabled', true);
        config()->set('ai_business_knowledge.service_bill_enabled', true);
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        config()->set('compliance.enabled', true);
        config()->set('operations.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);
        config()->set('app.erp_optional_activation_ready', true);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->actor = User::create([
            'name' => 'JobWork Manager',
            'email' => 'jw_'.Str::random(8).'@example.test',
            'phone' => '98888'.rand(10000, 99999),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::create([
            'legal_name' => 'Textile JobWork Corporation Ltd',
            'code' => 'TJC_'.Str::random(8),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->create([
            'code' => 'JWB1',
            'name' => 'JobWork Unit 1',
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
            'name' => 'Factory Godown',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'address' => 'Weaving Mill Road',
            'is_active' => true,
        ]);

        $this->jobWorker = Supplier::create([
            'name' => 'Sri Krishna Dyeing Works',
            'company_name' => 'Sri Krishna Dyeing',
            'vat_number' => '33AABCS8888A1Z9',
            'phone_number' => '9842188888',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->customer = Customer::create([
            'name' => 'Apex Apparel Sourcing Ltd',
            'company_name' => 'Apex Apparel',
            'vat_number' => '33AABCA7777A1Z3',
            'phone_number' => '9842177777',
            'company_id' => $this->company->id,
            'customer_group_id' => 1,
            'is_active' => true,
        ]);

        \App\Models\Biller::forceCreate([
            'name' => 'Main Biller',
            'company_name' => 'Textile JobWork Corporation Ltd',
            'vat_number' => '33AABCT0000A1Z5',
            'email' => 'biller@example.test',
            'phone_number' => '9842100000',
            'address' => '100 Mill Road',
            'city' => 'Tirupur',
            'state' => 'Tamil Nadu',
            'postal_code' => '641601',
            'country' => 'India',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->company->id,
                'party_type' => 'supplier',
                'party_id' => $this->jobWorker->id,
            ],
            [
                'gstin' => '33AABCS8888A1Z9',
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->company->id,
                'party_type' => 'customer',
                'party_id' => $this->customer->id,
            ],
            [
                'gstin' => '33AABCA7777A1Z3',
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->unit = Unit::create([
            'unit_code' => 'MTR',
            'unit_name' => 'Metre',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->rawFabric = Product::forceCreate([
            'name' => 'Grey Cotton Fabric 60s',
            'code' => 'YRN-GREY-'.Str::random(4),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => 1,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'cost' => 80.00,
            'price' => 110.00,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->dyedFabric = Product::forceCreate([
            'name' => 'Dyed Navy Blue Fabric 60s',
            'code' => 'YRN-NAVY-'.Str::random(4),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => 1,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'cost' => 95.00,
            'price' => 140.00,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->dyeingService = Product::forceCreate([
            'name' => 'Dyeing & Bleaching Charge (SAC 998821)',
            'code' => 'SRV-DYE-'.Str::random(4),
            'type' => 'service',
            'barcode_symbology' => 'C128',
            'category_id' => 1,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'cost' => 12.00,
            'price' => 15.00,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->processTypeId = (int) DB::table('process_types')->insertGetId([
            'company_id' => $this->company->id,
            'code' => 'PRC-DYE-'.Str::random(4),
            'name' => 'Reactive Dyeing Process',
            'max_loss_percent' => 5.0,
            'conversion' => true,
            'is_active' => true,
            'output_product_id' => $this->dyedFabric->id,
            'service_product_id' => $this->dyeingService->id,
            'service_rate' => 12.0,
            'created_at' => now(),
            'updated_at' => now(),
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

        // Enable operations.job_work capability for this company
        app(CapabilityService::class)->enable('operations.job_work', [], $this->context, $this->actor->id);
    }

    public function test_outward_company_owned_job_work_runs_end_to_end_with_exact_stock_and_cost_capitalization(): void
    {
        $registry = app(ActionCapabilityRegistry::class);
        $orderDefinition = $registry->get('job_work.order.create.v1');
        $billDefinition = $registry->get('job_work.service_bill.create.v1');

        $orderAdapter = app(JobWorkOrderActionAdapter::class);
        $billAdapter = app(JobWorkServiceBillActionAdapter::class);

        // 1. Order: Create company-owned outward job-work order
        $orderSlots = [
            'job_worker_party_id' => $this->jobWorker->id,
            'process_type_id' => $this->processTypeId,
            'business_date' => '2026-05-10',
            'expected_return_date' => '2026-05-20',
        ];

        $orderPreview = $orderAdapter->preview($orderDefinition, $orderSlots, $this->context);
        $this->assertTrue($orderPreview->preview['approval_required']);
        $this->assertStringContainsString('No stock movement until a separately approved material dispatch', $orderPreview->preview['inventory_impact']);

        $orderReceipt = $orderAdapter->execute(
            $orderDefinition,
            $orderPreview->payload,
            $this->context,
            $this->actor->id,
            (string) Str::uuid(),
            $orderPreview->sourceStateHash,
        );
        $this->assertSame('completed', $orderReceipt->status);
        $orderId = $orderReceipt->resourceId;

        // 2. Opening stock for outward dispatch: 100 Metres raw fabric in Factory Godown
        app(\App\Services\Inventory\InventoryMovementService::class)->receive(new \App\Services\Inventory\StockMovementCommand(
            date: '2026-05-09',
            lines: [new \App\Services\Inventory\StockLine(productId: $this->rawFabric->id, qty: 100.0, unitCost: 80.0, uomId: $this->unit->id)],
            warehouseId: $this->warehouse->id,
            context: $this->context,
            userId: $this->actor->id,
        ));

        // 3. Dispatch: Move 100 Metres of owned raw stock to external job-worker location
        $dispatchReceipt = app(\App\Services\JobWork\JobWorkService::class)->dispatch(
            $orderId,
            [
                'business_date' => '2026-05-11',
                'warehouse_id' => $this->warehouse->id,
                'lines' => [
                    ['product_id' => $this->rawFabric->id, 'qty' => 100.0, 'uom_id' => $this->unit->id],
                ],
            ],
            (string) Str::uuid(),
            $this->context,
            $this->actor->id,
        );
        $dispatchId = (int) $dispatchReceipt->id;
        $this->assertGreaterThan(0, $dispatchId);

        // 4. Inward: Receive processed 95 Metres dyed fabric + 5 Metres loss back into Factory Godown
        $dispatchLine = DB::table('job_work_dispatch_lines')->where('dispatch_id', $dispatchId)->first();
        $receipt = app(\App\Services\JobWork\JobWorkService::class)->receive(
            $dispatchId,
            [
                'business_date' => '2026-05-15',
                'warehouse_id' => $this->warehouse->id,
                'lines' => [
                    ['dispatch_line_id' => $dispatchLine->id, 'accepted_qty' => 95.0, 'loss_qty' => 5.0, 'rejected_qty' => 0.0],
                ],
                'outputs' => [
                    ['product_id' => $this->dyedFabric->id, 'qty' => 95.0, 'uom_id' => $this->unit->id, 'cost_weight' => 1.0],
                ],
            ],
            (string) Str::uuid(),
            $this->context,
            $this->actor->id,
        );
        $receiptId = (int) $receipt->id;
        $this->assertGreaterThan(0, $receiptId);

        // 5. Service Bill: Post job-worker processing bill with cost capitalization
        $billSlots = [
            'receipt_id' => $receiptId,
            'business_date' => '2026-05-16',
            'supplier_invoice_no' => 'SKD-INV-001',
            'supplier_invoice_date' => '2026-05-16',
            'charge_mode' => 'capitalise',
            'items' => [
                ['product_id' => $this->dyeingService->id, 'qty' => 95.0, 'net_unit_cost' => 12.0],
            ],
        ];

        $billPreview = $billAdapter->preview($billDefinition, $billSlots, $this->context);
        $this->assertSame('capitalise', $billPreview->preview['charge_mode']);
        $this->assertStringContainsString('allocates eligible charges to inventory/COGS', $billPreview->preview['inventory_costing']);

        $billReceipt = $billAdapter->execute(
            $billDefinition,
            $billPreview->payload,
            $this->context,
            $this->actor->id,
            (string) Str::uuid(),
            $billPreview->sourceStateHash,
        );
        $this->assertSame('completed', $billReceipt->status);
        $this->assertSame('purchase_bill', $billReceipt->resourceType);

        // 6. Traceability and Ledger Assertions
        $updatedReceipt = DB::table('job_work_receipts')->where('id', $receiptId)->first();
        $this->assertNotNull($updatedReceipt->service_purchase_id);
        $purchase = DB::table('purchases')->where('id', $updatedReceipt->service_purchase_id)->first();
        $this->assertNotNull($purchase);
        $this->assertSame('SKD-INV-001', $purchase->supplier_invoice_no);
        $this->assertEquals(95.0 * 12.0, (float) $purchase->total_cost);
    }

    public function test_inward_customer_owned_job_work_runs_end_to_end_off_owned_stock_and_bills_only_charge(): void
    {
        $initialMovements = DB::table('stock_movements')->where('company_id', $this->company->id)->count();

        $registry = app(ActionCapabilityRegistry::class);
        $orderDefinition = $registry->get('principal_job.order.create.v1');
        $receiveDefinition = $registry->get('principal_job.receive.create.v1');
        $returnDefinition = $registry->get('principal_job.return.create.v1');
        $invoiceDefinition = $registry->get('principal_job.service_invoice.create.v1');

        $orderAdapter = app(PrincipalJobOrderActionAdapter::class);
        $receiveAdapter = app(PrincipalJobReceiveActionAdapter::class);
        $returnAdapter = app(PrincipalJobReturnActionAdapter::class);
        $invoiceAdapter = app(PrincipalJobInvoiceActionAdapter::class);

        // 1. Order: Create customer job order
        $orderSlots = [
            'customer_id' => $this->customer->id,
            'business_date' => '2026-05-02',
            'process' => 'Reactive dyeing - Navy 60s',
            'service_product_id' => $this->dyeingService->id,
            'rate' => 15.00,
            'max_loss_percent' => 5.0,
        ];

        $orderPreview = $orderAdapter->preview($orderDefinition, $orderSlots, $this->context);
        $this->assertTrue($orderPreview->preview['approval_required']);
        $this->assertStringContainsString('NOT owned company stock', $orderPreview->preview['inventory_impact']);

        $orderReceipt = $orderAdapter->execute(
            $orderDefinition,
            $orderPreview->payload,
            $this->context,
            $this->actor->id,
            (string) Str::uuid(),
            $orderPreview->sourceStateHash,
        );
        $this->assertSame('completed', $orderReceipt->status);
        $orderId = $orderReceipt->resourceId;

        // 2. Receive: Receive customer material (1,000 Metres)
        $receiveSlots = [
            'order_id' => $orderId,
            'business_date' => '2026-05-03',
            'challan_no' => 'APEX-DC-5512',
            'challan_date' => '2026-05-02',
            'lines' => [
                [
                    'description' => 'Grey cotton cloth 60s',
                    'hsn_code' => '5208',
                    'uqc' => 'MTR',
                    'qty' => 1000.0,
                    'value' => 80000.0,
                ],
            ],
        ];

        $receivePreview = $receiveAdapter->preview($receiveDefinition, $receiveSlots, $this->context);
        $this->assertSame(1000.0, $receivePreview->preview['total_received_qty']);
        $this->assertStringContainsString('Off-balance sheet', $receivePreview->preview['inventory_impact']);

        $receiveReceipt = $receiveAdapter->execute(
            $receiveDefinition,
            $receivePreview->payload,
            $this->context,
            $this->actor->id,
            (string) Str::uuid(),
            $receivePreview->sourceStateHash,
        );
        $this->assertSame('completed', $receiveReceipt->status);

        // Crucial safety check: Customer material NEVER enters company stock ledger
        $this->assertSame(
            $initialMovements,
            DB::table('stock_movements')->where('company_id', $this->company->id)->count(),
            'Customer material must never create rows in stock_movements.'
        );

        $balanceAfterReceive = app(PrincipalJobService::class)->balance($orderId);
        $this->assertSame(1000.0, (float) $balanceAfterReceive['received']);
        $this->assertSame(1000.0, (float) $balanceAfterReceive['with_us']);
        $this->assertSame(0.0, (float) $balanceAfterReceive['returned']);
        $this->assertSame(0.0, (float) $balanceAfterReceive['unbilled']);

        // 3. Rejection of excess loss: Attempt 10% loss when agreement allows max 5%
        $excessLossSlots = [
            'order_id' => $orderId,
            'business_date' => '2026-05-06',
            'lines' => [
                [
                    'description' => 'Dyed Navy Cloth 60s',
                    'hsn_code' => '5208',
                    'uqc' => 'MTR',
                    'qty' => 900.0,
                    'loss_qty' => 100.0, // 10% loss > 5% allowed
                ],
            ],
        ];
        try {
            $returnAdapter->preview($returnDefinition, $excessLossSlots, $this->context);
            $this->fail('Expected ValidationException when loss exceeds agreed max_loss_percent.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('exceeds permitted maximum', $e->getMessage());
        }

        // 4. Return: Return 960 Metres processed material with 40 Metres loss (4% loss <= 5% max)
        $returnSlots = [
            'order_id' => $orderId,
            'business_date' => '2026-05-06',
            'lines' => [
                [
                    'description' => 'Dyed Navy Cloth 60s',
                    'hsn_code' => '5208',
                    'uqc' => 'MTR',
                    'qty' => 960.0,
                    'loss_qty' => 40.0,
                ],
            ],
        ];
        $returnPreview = $returnAdapter->preview($returnDefinition, $returnSlots, $this->context);
        $this->assertSame(960.0, $returnPreview->preview['total_returned_qty']);
        $this->assertSame(40.0, $returnPreview->preview['total_loss_qty']);
        $this->assertSame(0.0, $returnPreview->preview['remaining_with_us']);

        $returnReceipt = $returnAdapter->execute(
            $returnDefinition,
            $returnPreview->payload,
            $this->context,
            $this->actor->id,
            (string) Str::uuid(),
            $returnPreview->sourceStateHash,
        );
        $this->assertSame('completed', $returnReceipt->status);

        // Verify balance after return
        $balanceAfterReturn = app(PrincipalJobService::class)->balance($orderId);
        $this->assertSame(1000.0, (float) $balanceAfterReturn['received']);
        $this->assertSame(960.0, (float) $balanceAfterReturn['returned']);
        $this->assertSame(40.0, (float) $balanceAfterReturn['loss']);
        $this->assertSame(0.0, (float) $balanceAfterReturn['with_us']);
        $this->assertSame(960.0, (float) $balanceAfterReturn['unbilled']);

        // Still zero movements in company stock ledger
        $this->assertSame(
            $initialMovements,
            DB::table('stock_movements')->where('company_id', $this->company->id)->count()
        );

        // 5. Over-billing rejection: Attempt to bill 1000 Metres when unbilled is only 960
        $overbillSlots = [
            'order_id' => $orderId,
            'business_date' => '2026-05-07',
            'qty' => 1000.0,
            'rate' => 15.00,
        ];
        try {
            $invoiceAdapter->preview($invoiceDefinition, $overbillSlots, $this->context);
            $this->fail('Expected ValidationException when attempting to bill more than returned unbilled qty.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Can bill only actually returned and not-yet-invoiced material', $e->getMessage());
        }

        // 6. Invoice: Bill exactly the 960 Metres returned
        $invoiceSlots = [
            'order_id' => $orderId,
            'business_date' => '2026-05-07',
            'qty' => 960.0,
            'rate' => 15.00,
        ];
        $invoicePreview = $invoiceAdapter->preview($invoiceDefinition, $invoiceSlots, $this->context);
        $this->assertSame(960.0, $invoicePreview->preview['invoiced_qty']);
        $this->assertStringContainsString('Customer-owned material is not company stock', $invoicePreview->preview['inventory_impact']);

        $invoiceReceipt = $invoiceAdapter->execute(
            $invoiceDefinition,
            $invoicePreview->payload,
            $this->context,
            $this->actor->id,
            (string) Str::uuid(),
            $invoicePreview->sourceStateHash,
        );
        $this->assertSame('completed', $invoiceReceipt->status);
        $this->assertSame('sales_invoice', $invoiceReceipt->resourceType);

        // 7. Final balance check: 100% reconciled
        $finalBalance = app(PrincipalJobService::class)->balance($orderId);
        $this->assertSame(1000.0, (float) $finalBalance['received']);
        $this->assertSame(960.0, (float) $finalBalance['returned']);
        $this->assertSame(40.0, (float) $finalBalance['loss']);
        $this->assertSame(0.0, (float) $finalBalance['with_us']);
        $this->assertSame(960.0, (float) $finalBalance['billed']);
        $this->assertSame(0.0, (float) $finalBalance['unbilled']);

        // Strictly verify company stock movements never recorded any transactions
        $this->assertSame(
            $initialMovements,
            DB::table('stock_movements')->where('company_id', $this->company->id)->count(),
            'Company stock movements count must remain identical through entire customer job-work lifecycle.'
        );
    }

    public function test_customer_job_work_cross_tenant_isolation_and_idempotency(): void
    {
        $registry = app(ActionCapabilityRegistry::class);
        $orderDefinition = $registry->get('principal_job.order.create.v1');
        $orderAdapter = app(PrincipalJobOrderActionAdapter::class);

        $idempotencyKey = (string) Str::uuid();
        $orderSlots = [
            'customer_id' => $this->customer->id,
            'business_date' => '2026-05-02',
            'process' => 'Dyeing Navy',
            'service_product_id' => $this->dyeingService->id,
            'rate' => 12.00,
        ];

        $preview = $orderAdapter->preview($orderDefinition, $orderSlots, $this->context);
        $firstReceipt = $orderAdapter->execute(
            $orderDefinition,
            $preview->payload,
            $this->context,
            $this->actor->id,
            $idempotencyKey,
            $preview->sourceStateHash,
        );

        $initialCount = DB::table('principal_job_orders')->where('company_id', $this->company->id)->count();

        // Second execution with same idempotency key
        $secondReceipt = $orderAdapter->execute(
            $orderDefinition,
            $preview->payload,
            $this->context,
            $this->actor->id,
            $idempotencyKey,
            $preview->sourceStateHash,
        );

        $this->assertSame($firstReceipt->resourceId, $secondReceipt->resourceId);
        $this->assertSame($initialCount, DB::table('principal_job_orders')->where('company_id', $this->company->id)->count());

        // Foreign tenant cannot receive against this order
        $foreignCompany = Company::create([
            'legal_name' => 'Foreign Textiles Corp',
            'code' => 'FTC_'.Str::random(8),
            'status' => 'active',
        ]);
        $foreignBranch = $foreignCompany->branches()->create([
            'code' => 'FB1', 'name' => 'Foreign Branch', 'branch_type' => 'branch',
        ]);
        $foreignFY = $foreignCompany->fiscalYears()->create([
            'name' => 'FY 2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false,
        ]);
        $foreignContext = new CompanyContext($foreignCompany->id, $foreignBranch->id, $foreignFY->id);

        $receiveDefinition = $registry->get('principal_job.receive.create.v1');
        $receiveAdapter = app(PrincipalJobReceiveActionAdapter::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Principal job order not found in current company/branch/FY');
        $receiveAdapter->preview($receiveDefinition, [
            'order_id' => $firstReceipt->resourceId,
            'business_date' => '2026-05-03',
            'challan_no' => 'CH-999',
            'challan_date' => '2026-05-02',
            'lines' => [['description' => 'Grey cloth', 'hsn_code' => '5208', 'uqc' => 'MTR', 'qty' => 500, 'value' => 40000]],
        ], $foreignContext);
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
}
