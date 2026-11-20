<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\JobWork\JobWorkService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperationsTestCase;

class JobWorkServiceProductCreationTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_16_000005_job_work_textile_process_route.php'))->up();
    }
    public function test_configure_process_auto_creates_service_product_when_toggled(): void
    {
        $context = $this->context();
        $service = app(JobWorkService::class);

        $processId = $service->configureProcess([
            'code' => 'DYE-01',
            'name' => 'Fabric Dyeing',
            'max_loss_percent' => '2.5',
            'conversion' => true,
            'quantity_basis' => 'base_qty',
            'service_rate' => '18.50',
            'create_service_product' => 1,
        ], $context, 1);

        $this->assertGreaterThan(0, $processId);

        $process = DB::table('process_types')->where('company_id', $context->companyId)->where('id', $processId)->first();
        $this->assertNotNull($process);
        $this->assertNotNull($process->service_product_id);
        $this->assertEquals(18.50, (float) $process->service_rate);

        $product = Product::forCompany($context)->find($process->service_product_id);
        $this->assertNotNull($product);
        $this->assertSame('service', $product->type);
        $this->assertSame('SRV-DYE-01', $product->code);
        $this->assertSame('Fabric Dyeing Processing Service', $product->name);
        $this->assertEquals(18.50, (float) $product->cost);
        $this->assertEquals(18.50, (float) $product->price);
        $this->assertSame('9988', $product->hsn_code);
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'product_usage')) {
            $this->assertSame('composite', $product->product_usage);
        }
        $this->assertTrue((bool) $product->is_active);
    }

    public function test_configure_process_with_custom_service_product_name_and_code(): void
    {
        $context = $this->context();
        $service = app(JobWorkService::class);

        $processId = $service->configureProcess([
            'code' => 'PRINT-02',
            'name' => 'Screen Printing',
            'max_loss_percent' => '1.0',
            'conversion' => false,
            'service_rate' => '25.00',
            'create_service_product' => 1,
            'service_product_name' => 'Custom Screen Printing Job Item',
            'service_product_code' => 'CUST-PRINT-02',
        ], $context, 1);

        $process = DB::table('process_types')->where('company_id', $context->companyId)->where('id', $processId)->first();
        $this->assertNotNull($process->service_product_id);

        $product = Product::forCompany($context)->find($process->service_product_id);
        $this->assertNotNull($product);
        $this->assertSame('Custom Screen Printing Job Item', $product->name);
        $this->assertSame('CUST-PRINT-02', $product->code);
        $this->assertSame('service', $product->type);
        $this->assertEquals(25.00, (float) $product->price);
    }

    public function test_configure_process_without_toggle_does_not_create_service_product(): void
    {
        $context = $this->context();
        $service = app(JobWorkService::class);

        $processId = $service->configureProcess([
            'code' => 'IRON-03',
            'name' => 'Steam Ironing',
            'max_loss_percent' => '0',
            'conversion' => false,
            'service_rate' => '5.00',
            'create_service_product' => 0,
        ], $context, 1);

        $process = DB::table('process_types')->where('company_id', $context->companyId)->where('id', $processId)->first();
        $this->assertNull($process->service_product_id);
        $this->assertNull(Product::forCompany($context)->where('code', 'SRV-IRON-03')->first());
    }
}
