<?php

namespace Tests\Support;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Product;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\StockLine;
use App\Services\Inventory\StockMovementCommand;
use App\Services\Platform\CapabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesOperationsFixtures
{
    protected function setUpOperationsFixtures(): void
    {
        config(['operations.enabled' => true]);
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps(); });
            Schema::create('role_has_permissions', function (Blueprint $t) { $t->unsignedBigInteger('permission_id'); $t->unsignedInteger('role_id'); });
        }
        Schema::table('warehouses', function (Blueprint $t) { $t->text('address')->nullable(); $t->boolean('is_active')->default(true); });
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_recipe')->default(false);
            foreach (['product_list', 'qty_list', 'combo_unit_id', 'variant_list', 'wastage_percent'] as $column) $t->text($column)->nullable();
        });
        if (!Schema::hasTable('product_batches')) {
            require_once database_path('migrations/2021_03_07_093606_create_product_batches_table.php');
            (new \CreateProductBatchesTable)->up();
            Schema::table('product_batches', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable(); $t->date('mfg_date')->nullable();
                $t->decimal('mrp', 18, 4)->nullable(); $t->string('status')->default('active');
            });
        }
        (require database_path('migrations/2026_09_19_000007_create_repair_project_and_booking_tables.php'))->up();
        (require database_path('migrations/2026_10_07_000001_create_manufacturing_and_job_work.php'))->up();
        (require database_path('migrations/2026_10_17_000006_job_work_returns_and_rework.php'))->up();
        (require database_path('migrations/2026_10_08_000001_create_industry_profile_extensions.php'))->up();
        if (Schema::hasTable('principal_job_orders') && !Schema::hasColumn('principal_job_orders', 'financial_year_id')) {
            (require database_path('migrations/2026_11_20_235003_add_principal_job_financial_year_ownership.php'))->up();
        }
        foreach (['production_costs', 'inventory_loss'] as $subtype) {
            ChartOfAccount::forceCreate(['company_id' => $this->company->id, 'code' => strtoupper($subtype),
                'name' => $subtype, 'type' => 'expense', 'sub_type' => $subtype]);
        }
        // An earlier seed creates unresolved mappings; setup here explicitly maps fixture accounts.
        foreach (['production_costs' => 'production_costs', 'damage' => 'inventory_loss'] as $role => $subtype) {
            DB::table('semantic_account_mappings')->where('company_id', $this->company->id)->where('semantic_role', $role)
                ->update(['account_id' => ChartOfAccount::where('company_id', $this->company->id)->where('sub_type', $subtype)->value('id'), 'is_active' => true]);
        }
        Cache::flush();
        $capabilities = \Mockery::mock(CapabilityService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $capabilities->shouldReceive('optionalActivationReady')->andReturn(true);
        $this->app->instance(CapabilityService::class, $capabilities);
        foreach (['manufacturing.bom', 'manufacturing.production', 'operations.job_work'] as $capability) $capabilities->enable($capability, [], $this->context(), 1);
    }

    protected function material(float $qty = 100, float $cost = 5, array $attributes = []): Product
    {
        $product = $this->stock($qty, $cost);
        $product->forceFill(['unit_id' => 1, 'is_active' => true] + $attributes)->save();
        if ($qty) app(InventoryMovementService::class)->opening(new StockMovementCommand(
            date: '2026-10-03', lines: [new StockLine(productId: $product->id, qty: $qty, unitCost: $cost)],
            warehouseId: 1, context: $this->context(), userId: 1));
        return $product;
    }

    protected function bom(Product $input, Product $output, array $extra = [])
    {
        return app(\App\Services\Manufacturing\BomService::class)->create($extra + ['product_id' => $output->id, 'code' => 'BOM-'.$output->id,
            'effective_from' => '2026-10-03', 'output_qty' => 1, 'output_uom_id' => 1,
            'lines' => [['component_product_id' => $input->id, 'qty' => 2, 'uom_id' => 1, 'scrap_percent' => 0]]],
            'bom-'.$output->id, $this->context(), 1);
    }

    protected function job(Product $material, float $qty = 500, bool $conversion = false)
    {
        $service = app(\App\Services\JobWork\JobWorkService::class);
        $process = $service->configureProcess(['code' => 'PROCESS', 'name' => 'Processing', 'max_loss_percent' => 4, 'conversion' => $conversion], $this->context(), 1);
        $order = $service->order(['job_worker_party_id' => 1, 'process_type_id' => $process, 'business_date' => '2026-10-03'], 'job-order', $this->context(), 1);
        $dispatch = $service->dispatch($order->id, ['warehouse_id' => 1, 'business_date' => '2026-10-03',
            'lines' => [['product_id' => $material->id, 'qty' => $qty]]], 'dispatch', $this->context(), 1);
        return [$order, $dispatch, DB::table('job_work_dispatch_lines')->where('dispatch_id', $dispatch->id)->first()];
    }

    protected function rejected(callable $action, string $message): void
    {
        try { $action(); $this->fail('Expected rejection: '.$message); }
        catch (\Illuminate\Validation\ValidationException|\InvalidArgumentException|\Illuminate\Auth\Access\AuthorizationException|\LogicException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }
}
