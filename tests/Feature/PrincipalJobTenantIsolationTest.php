<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Industry\IndustryProfileService;
use App\Services\JobWork\PrincipalJobService;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\CreatesOperationsFixtures;
use Tests\Support\ReturnsDocumentTestCase;

final class PrincipalJobTenantIsolationTest extends ReturnsDocumentTestCase
{
    use CreatesOperationsFixtures;

    private PrincipalJobService $service;
    private int $serviceProductId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOperationsFixtures();

        (require database_path('migrations/2026_10_16_000005_job_work_textile_process_route.php'))->up();
        (require database_path('migrations/2026_10_24_000003_textile_trade_compliance_features.php'))->up();
        (require database_path('migrations/2026_11_20_235003_add_principal_job_financial_year_ownership.php'))->up();

        app(IndustryProfileService::class)->apply('textile', 'manufacturing', $this->context(), 1);
        $this->service = app(PrincipalJobService::class);
        $this->serviceProductId = $this->named($this->charge(), 'Dyeing charge SAC 998821')->id;

        DB::table('customers')->where('id', 1)->update(['name' => 'Kaveri Garments', 'company_name' => 'Kaveri Garments Pvt Ltd']);
        DB::table('warehouses')->where('id', 1)->update(['name' => 'Central Godown']);
    }

    private function named(Product $product, string $name): Product
    {
        $product->forceFill(['name' => $name])->save();
        return $product;
    }

    private function charge(): Product
    {
        $service = $this->material(0, 0, ['hsn_code' => '998821', 'type' => 'service']);
        DB::table('product_warehouse')->where('product_id', $service->id)->update(['qty' => 0]);
        return $service;
    }

    public function test_principal_job_order_stamps_company_branch_and_financial_year(): void
    {
        $orderId = $this->service->order([
            'customer_id' => 1,
            'business_date' => '2026-10-03',
            'process' => 'Yarn Dyeing',
            'service_product_id' => $this->serviceProductId,
            'rate' => 15.50,
            'max_loss_percent' => 3.0,
        ], $this->context(), 1);

        $row = DB::table('principal_job_orders')->where('id', $orderId)->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $this->company->id, (int) $row->company_id);
        $this->assertSame((int) $this->branch->id, (int) $row->branch_id);
        $this->assertSame((int) $this->context()->financialYearId, (int) $row->financial_year_id);
    }

    public function test_cross_company_access_is_blocked(): void
    {
        $orderId = $this->service->order([
            'customer_id' => 1,
            'business_date' => '2026-10-03',
            'process' => 'Yarn Dyeing',
            'service_product_id' => $this->serviceProductId,
            'rate' => 15.50,
        ], $this->context(), 1);

        $otherCompanyContext = new CompanyContext(
            companyId: 99999,
            branchId: 1,
            financialYearId: 1
        );

        $this->expectException(AuthorizationException::class);
        $this->service->find($orderId, $otherCompanyContext, 1);
    }

    public function test_cross_branch_access_is_blocked(): void
    {
        $orderId = $this->service->order([
            'customer_id' => 1,
            'business_date' => '2026-10-03',
            'process' => 'Yarn Dyeing',
            'service_product_id' => $this->serviceProductId,
            'rate' => 15.50,
        ], $this->context(), 1);

        $otherBranchContext = new CompanyContext(
            companyId: $this->company->id,
            branchId: 88888,
            financialYearId: (int) $this->context()->financialYearId
        );

        $this->expectException(AuthorizationException::class);
        $this->service->find($orderId, $otherBranchContext, 1);
    }

    public function test_cross_financial_year_access_is_blocked(): void
    {
        $orderId = $this->service->order([
            'customer_id' => 1,
            'business_date' => '2026-10-03',
            'process' => 'Yarn Dyeing',
            'service_product_id' => $this->serviceProductId,
            'rate' => 15.50,
        ], $this->context(), 1);

        $otherFyContext = new CompanyContext(
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: 77777
        );

        $this->expectException(AuthorizationException::class);
        $this->service->find($orderId, $otherFyContext, 1);
    }

    public function test_order_in_another_branch_returns_404_not_found(): void
    {
        $foreignBranchOrderId = DB::table('principal_job_orders')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => 999, // different branch
            'customer_id' => 1,
            'reference_no' => 'PJO-OTHER-BRANCH',
            'business_date' => '2026-10-03',
            'process' => 'Test',
            'service_product_id' => $this->serviceProductId,
            'rate' => 10.0,
            'financial_year_id' => (int) $this->context()->financialYearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->service->find($foreignBranchOrderId, $this->context(), 1);
    }

    public function test_order_in_another_fy_returns_404_not_found(): void
    {
        $foreignFyOrderId = DB::table('principal_job_orders')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => (int) $this->branch->id,
            'customer_id' => 1,
            'reference_no' => 'PJO-OTHER-FY',
            'business_date' => '2026-10-03',
            'process' => 'Test',
            'service_product_id' => $this->serviceProductId,
            'rate' => 10.0,
            'financial_year_id' => 999, // different FY
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->service->find($foreignFyOrderId, $this->context(), 1);
    }

    public function test_migration_backfill_handles_ambiguous_and_unambiguous_fiscal_years(): void
    {
        // Set up fiscal years in the database
        DB::table('fiscal_years')->insert([
            ['id' => 101, 'name' => 'FY 2025-26', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'is_closed' => false, 'company_id' => $this->company->id],
            ['id' => 102, 'name' => 'FY 2026-27 A', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'is_closed' => false, 'company_id' => $this->company->id],
            ['id' => 103, 'name' => 'FY 2026-27 B (overlapping)', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'is_closed' => false, 'company_id' => $this->company->id],
        ]);

        // Unambiguous: date 2025-06-15 only matches FY 101
        $unambiguousId = DB::table('principal_job_orders')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => 1,
            'reference_no' => 'PJO-UNAMBIGUOUS',
            'business_date' => '2025-06-15',
            'process' => 'Test',
            'service_product_id' => $this->serviceProductId,
            'rate' => 10.0,
            'financial_year_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ambiguous: date 2026-08-15 matches both FY 102 and FY 103
        $ambiguousId = DB::table('principal_job_orders')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => 1,
            'reference_no' => 'PJO-AMBIGUOUS',
            'business_date' => '2026-08-15',
            'process' => 'Test',
            'service_product_id' => $this->serviceProductId,
            'rate' => 10.0,
            'financial_year_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-run the migration up() to trigger the backfill chunk
        $migration = require database_path('migrations/2026_11_20_235003_add_principal_job_financial_year_ownership.php');
        $migration->up();

        // Assert unambiguous record is backfilled to 101
        $this->assertSame(101, (int) DB::table('principal_job_orders')->where('id', $unambiguousId)->value('financial_year_id'));

        // Assert ambiguous record remains NULL (blocked from guessing)
        $this->assertNull(DB::table('principal_job_orders')->where('id', $ambiguousId)->value('financial_year_id'));
    }
}
