<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Optical;

use App\Ai\Services\AiFeatureGate;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Optical\OpticalRxJobService;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class OpticalRxHardeningTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private CompanyBranch $branch;
    private int $fyId;
    private Customer $customer;
    private Customer $otherCustomer;
    private Warehouse $warehouse;
    private Unit $unit;
    private Product $lensProduct;
    private Product $serviceProduct;
    private User $actor;
    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_business_knowledge.opticals_enabled' => true,
            'ai_business_knowledge.opticals_lab_enabled' => true,
        ]);
        $this->app->forgetInstance(AiFeatureGate::class);

        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);

        $this->company = Company::create([
            'legal_name' => 'Hardened Optical Lab Ltd',
            'trade_name' => 'Hardened Optical Lab',
            'code' => 'HOPT_'.Str::random(6),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        DB::table('company_industry_settings')->insert([
            'company_id' => $this->company->id,
            'profile_key' => 'opticals',
            'subtype' => 'lens_manufacturing',
            'settings_json' => json_encode(['rx_lab' => true]),
            'updated_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branch = $this->company->branches()->create(['code' => 'LABH', 'name' => 'Lab Branch', 'branch_type' => 'branch']);
        $fy = $this->company->fiscalYears()->create([
            'name' => 'FY 2026-27',
            'code' => 'FYH_'.Str::random(6),
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_default' => true,
        ]);
        $this->fyId = $fy->id;

        $this->warehouse = Warehouse::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'Optical Lab Vault',
            'address' => 'Street 1',
            'is_active' => true,
        ]);
        $this->customer = Customer::forceCreate([
            'company_id' => $this->company->id,
            'customer_group_id' => 1,
            'name' => 'Vision Care Clinic',
            'phone_number' => '9811111111',
            'is_active' => true,
        ]);
        $this->otherCustomer = Customer::forceCreate([
            'company_id' => $this->company->id,
            'customer_group_id' => 1,
            'name' => 'Other Optics',
            'phone_number' => '9811111112',
            'is_active' => true,
        ]);

        $this->unit = Unit::forceCreate([
            'company_id' => $this->company->id,
            'unit_name' => 'Piece',
            'unit_code' => 'PCS_'.Str::random(4),
            'is_active' => true,
        ]);
        $catId = Category::firstOrCreate(['company_id' => $this->company->id, 'name' => 'Optics'], ['is_active' => true])->id;

        $this->lensProduct = Product::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'LENS-'.Str::random(5),
            'name' => 'BlueCut Rx 1.67',
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => $catId,
            'unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'cost' => 500,
            'price' => 1200,
            'qty' => 10,
            'is_active' => true,
        ]);

        $this->serviceProduct = Product::forceCreate([
            'company_id' => $this->company->id,
            'code' => 'SRV-'.Str::random(5),
            'name' => 'Fitting Charge',
            'type' => 'service',
            'barcode_symbology' => 'C128',
            'category_id' => $catId,
            'unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'cost' => 0,
            'price' => 250,
            'qty' => 0,
            'is_active' => true,
        ]);

        $this->actor = User::forceCreate([
            'name' => 'Rx QC Lead',
            'email' => 'rxqc_'.Str::random(6).'@example.test',
            'phone' => '9811111110',
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
    }

    public function test_lab_disabled_blocks_rx_job_creation(): void
    {
        config()->set('ai_business_knowledge.opticals_lab_enabled', false);

        $this->expectException(AuthorizationException::class);
        app(OpticalRxJobService::class)->createJob($this->context, $this->actor->id, [
            'customer_id' => $this->customer->id,
            'product_id' => $this->lensProduct->id,
            'source_warehouse_id' => $this->warehouse->id,
            'qty_requested' => 1,
            'optical_spec' => ['sph' => '-2.00', 'cyl' => '-0.50', 'axis' => '090', 'lr' => 'RE'],
        ], 'idem-disabled');
    }

    public function test_dispatch_verifies_customer_optical_spec_physical_movement_and_prevents_dc_sale_double_count(): void
    {
        $service = app(OpticalRxJobService::class);
        $job1 = $this->makePackedJob($service, '-2.00', 'RE', 1.0, 'job-1');
        $job2 = $this->makePackedJob($service, '-2.00', 'RE', 1.0, 'job-2');
        $wrongPowerJob = $this->makePackedJob($service, '+2.00', 'RE', 1.0, 'job-wrong-power');

        // 1. Service-only invoice (no physical stock movement) must be rejected.
        $serviceSale = $this->createPostedSale($this->customer->id, $this->serviceProduct->id, 1.0, '+2.00', false);
        try {
            $service->transition($this->context, $this->actor->id, $wrongPowerJob->id, $wrongPowerJob->revision, 'dispatched', [
                'sale_id' => $serviceSale->id,
            ]);
            $this->fail('Service-only invoice must not verify physical dispatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale_id', $e->errors());
        }

        // 2. Physical Delivery Challan for Customer + qty=1 + SPH -2.00 RE
        $dc = $this->createPostedDc($this->customer->id, $this->lensProduct->id, 1.0, '-2.00', 'RE');
        $dcLineId = (int) DB::table('delivery_challan_items')->where('delivery_challan_id', $dc->id)->value('id');

        // Wrong optical power (+2.00 vs -2.00) must be rejected.
        try {
            $service->transition($this->context, $this->actor->id, $wrongPowerJob->id, $wrongPowerJob->revision, 'dispatched', [
                'delivery_challan_id' => $dc->id,
            ]);
            $this->fail('Optical power sign mismatch must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('evidence', $e->errors());
        }

        // Wrong customer must be rejected.
        $otherDc = $this->createPostedDc($this->otherCustomer->id, $this->lensProduct->id, 1.0, '-2.00', 'RE');
        try {
            $service->transition($this->context, $this->actor->id, $job1->id, $job1->revision, 'dispatched', [
                'delivery_challan_id' => $otherDc->id,
            ]);
            $this->fail('Customer mismatch on Delivery Challan must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }

        // Valid dispatch on Job 1 allocates the full 1.0 unit from DC.
        $dispatched1 = $service->transition($this->context, $this->actor->id, $job1->id, $job1->revision, 'dispatched', [
            'delivery_challan_id' => $dc->id,
        ]);
        $this->assertSame('dispatched', $dispatched1->status);
        $this->assertSame(1.0, (float) $dispatched1->qty_dispatched);
        $this->assertDatabaseHas('optical_rx_job_allocations', [
            'company_id' => $this->company->id,
            'job_id' => $job1->id,
            'source_type' => 'delivery_challan',
            'source_id' => $dc->id,
            'source_line_id' => $dcLineId,
        ]);

        // Settle the DC into a Sale (DC -> Sale billing settlement): Job 2 must NOT double-count the same physical unit.
        $settledSale = $this->createPostedSale($this->customer->id, $this->lensProduct->id, 1.0, '-2.00', true, 'RE');
        $saleLineId = (int) DB::table('product_sales')->where('sale_id', $settledSale->id)->value('id');
        DB::table('delivery_challans')->where('id', $dc->id)->update(['sale_id' => $settledSale->id]);
        DB::table('material_settlements')->insert([
            'company_id' => $this->company->id,
            'document_type' => 'dc',
            'document_id' => $dc->id,
            'document_item_id' => $dcLineId,
            'settled_by_type' => 'sale',
            'settled_by_id' => $settledSale->id,
            'settled_by_line_id' => $saleLineId,
            'product_id' => $this->lensProduct->id,
            'qty_base' => 1.0,
            'value' => 1200.0,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        try {
            $service->transition($this->context, $this->actor->id, $job2->id, $job2->revision, 'dispatched', [
                'sale_id' => $settledSale->id,
            ]);
            $this->fail('DC-to-Sale settled quantity already allocated to Job 1 must prevent Job 2 double-counting.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('evidence', $e->errors());
        }
    }

    public function test_cancellation_rejects_fake_reversal_string_until_db_return_exists_and_releases_allocations(): void
    {
        $service = app(OpticalRxJobService::class);
        $job = $this->makePackedJob($service, '-1.50', 'LE', 1.0, 'job-rev');
        $sale = $this->createPostedSale($this->customer->id, $this->lensProduct->id, 1.0, '-1.50', true, 'LE');

        $dispatched = $service->transition($this->context, $this->actor->id, $job->id, $job->revision, 'dispatched', [
            'sale_id' => $sale->id,
        ]);
        $reconcile = $service->transition($this->context, $this->actor->id, $dispatched->id, $dispatched->revision, 'requires_reconciliation');

        // Fake arbitrary reversal_reference without DB return or reversed sale must fail.
        try {
            $service->transition($this->context, $this->actor->id, $reconcile->id, $reconcile->revision, 'cancelled', [
                'reversal_reference' => 'FAKE-STRING-999',
            ]);
            $this->fail('Arbitrary unbacked reversal string must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reversal_reference', $e->errors());
        }

        // Create authoritative customer return row in returns table.
        $billerId = DB::table('billers')->insertGetId([
            'company_id' => $this->company->id,
            'name' => 'Main Biller',
            'company_name' => 'Hardened Optical Lab',
            'phone_number' => '9800000001',
            'email' => 'biller@example.test',
            'address' => 'Road 1',
            'city' => 'Chennai',
            'country' => 'India',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('returns')->insert([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fyId,
            'user_id' => $this->actor->id,
            'account_id' => 1,
            'reference_no' => 'SR-RET-101',
            'sale_id' => $sale->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $billerId,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 1200,
            'grand_total' => 1200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cancelled = $service->transition($this->context, $this->actor->id, $reconcile->id, $reconcile->revision, 'cancelled', [
            'reversal_reference' => 'SR-RET-101',
        ]);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull(
            DB::table('optical_rx_job_allocations')
                ->where('job_id', $job->id)
                ->whereNull('released_at')
                ->first()
        );
    }

    private function makePackedJob(OpticalRxJobService $service, string $sph, string $lr, float $qty, string $suffix)
    {
        $job = $service->createJob($this->context, $this->actor->id, [
            'customer_id' => $this->customer->id,
            'product_id' => $this->lensProduct->id,
            'source_warehouse_id' => $this->warehouse->id,
            'qty_requested' => $qty,
            'optical_spec' => [
                'lens_family' => 'BlueCut Rx 1.67',
                'sph' => $sph,
                'cyl' => '-0.50',
                'axis' => '090',
                'lr' => $lr,
            ],
        ], 'idem-'.$suffix);

        $job = $service->transition($this->context, $this->actor->id, $job->id, $job->revision, 'ready_for_work');
        $job = $service->transition($this->context, $this->actor->id, $job->id, $job->revision, 'in_process');
        $job = $service->recordQcResult($this->context, $this->actor->id, $job->id, $job->revision, $qty, 0.0);
        $this->assertSame($this->actor->id, (int) $job->qc_inspector_id);

        return $service->transition($this->context, $this->actor->id, $job->id, $job->revision, 'packed');
    }

    private function createPostedDc(int $customerId, int $productId, float $qty, string $sph, string $lr): DeliveryChallan
    {
        $movementId = DB::table('stock_movements')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fyId,
            'movement_no' => 'MV-'.Str::random(6),
            'movement_date' => '2026-11-27',
            'movement_type' => 'delivery_challan_out',
            'source_type' => 'delivery_challan',
            'status' => 'posted',
            'posted_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $dc = DeliveryChallan::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fyId,
            'challan_no' => 'DC-'.Str::random(6),
            'challan_date' => '2026-11-27',
            'customer_id' => $customerId,
            'warehouse_id' => $this->warehouse->id,
            'total_qty' => $qty,
            'total_amount' => 1200,
            'total_tax' => 0,
            'grand_total' => 1200,
            'status' => 'pending',
            'stock_movement_id' => $movementId,
            'posted_at' => now('UTC'),
            'created_by' => $this->actor->id,
        ]);

        DB::table('stock_movements')->where('id', $movementId)->update(['source_id' => $dc->id]);

        DB::table('delivery_challan_items')->insert([
            'company_id' => $this->company->id,
            'delivery_challan_id' => $dc->id,
            'product_id' => $productId,
            'unit_id' => $this->unit->id,
            'qty' => $qty,
            'qty_base' => $qty,
            'rate' => 1200,
            'amount' => 1200,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 1200,
            'optical_spec_json' => json_encode(['sph' => $sph, 'cyl' => '-0.50', 'axis' => '090', 'lr' => $lr]),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $dc;
    }

    private function createPostedSale(
        int $customerId,
        int $productId,
        float $qty,
        string $sph,
        bool $physicalMovement,
        string $lr = 'RE',
    ): Sale {
        $billerId = DB::table('billers')->where('company_id', $this->company->id)->value('id')
            ?: DB::table('billers')->insertGetId([
                'company_id' => $this->company->id,
                'name' => 'Optical Biller',
                'company_name' => 'Hardened Optical Lab',
                'phone_number' => '9800000002',
                'email' => 'ob@example.test',
                'address' => 'Main Rd',
                'city' => 'Chennai',
                'country' => 'India',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $sale = Sale::forceCreate([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fyId,
            'reference_no' => 'SI-'.Str::random(6),
            'user_id' => $this->actor->id,
            'customer_id' => $customerId,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => $billerId,
            'item' => 1,
            'total_qty' => $qty,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 1200,
            'grand_total' => 1200,
            'sale_status' => 1,
            'payment_status' => 1,
            'paid_amount' => 0,
            'posted_at' => now('UTC'),
        ]);

        if ($physicalMovement) {
            DB::table('stock_movements')->insert([
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
                'financial_year_id' => $this->fyId,
                'movement_no' => 'SM-'.Str::random(6),
                'movement_date' => '2026-11-27',
                'movement_type' => 'sale_out',
                'source_type' => 'sale',
                'source_id' => $sale->id,
                'status' => 'posted',
                'posted_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }

        DB::table('product_sales')->insert([
            'company_id' => $this->company->id,
            'sale_id' => $sale->id,
            'product_id' => $productId,
            'qty' => $qty,
            'sale_unit_id' => $this->unit->id,
            'net_unit_price' => 1200,
            'discount' => 0,
            'tax_rate' => 0,
            'tax' => 0,
            'total' => 1200,
            'sph' => $sph,
            'cyl' => '-0.50',
            'axis' => '090',
            'lr' => $lr,
            'prescription_json' => json_encode(['sph' => $sph, 'cyl' => '-0.50', 'axis' => '090', 'lr' => $lr]),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $sale;
    }
}
