<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductPurchase;
use App\Models\Product_Sale;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\CommercialApplicationService;
use App\Services\Platform\CompanyContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LotNumberCommercialTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected CompanyContext $context;
    protected Warehouse $warehouse;
    protected Supplier $supplier;
    protected Customer $customer;
    protected Product $product;

    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mysql.database' => 'salepro_db']);
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');
        config(['commercial.enabled' => true]);
        config(['compliance.enabled' => false]);
        putenv('ERP_OPTIONAL_ACTIVATION_READY=true');
        $_ENV['ERP_OPTIONAL_ACTIVATION_READY'] = 'true';

        $this->adminUser = User::first();
        $companyId = 4;
        $this->warehouse = Warehouse::where('company_id', $companyId)->first();
        $this->supplier = Supplier::where('company_id', $companyId)->first();
        $this->customer = Customer::where('company_id', $companyId)->first();
        $this->unit = \App\Models\Unit::firstOrCreate(
            ['company_id' => $companyId, 'unit_code' => 'LOT-PC'],
            ['unit_name' => 'Lot Piece', 'operator' => '*', 'operation_value' => 1, 'is_active' => 1]
        );
        $tax = \App\Models\Tax::firstOrCreate(
            ['company_id' => $companyId, 'rate' => 5],
            ['name' => 'GST 5%', 'is_active' => 1]
        );
        $this->product = Product::forceCreate([
            'company_id' => $companyId,
            'name' => 'Lot Automation Test Fabric',
            'code' => 'LOT-TEST-' . mt_rand(10000, 99999),
            'type' => 'standard',
            'barcode_symbology' => 'C128',
            'category_id' => \App\Models\Category::where('company_id', $companyId)->value('id') ?? 1,
            'unit_id' => $this->unit->id,
            'purchase_unit_id' => $this->unit->id,
            'sale_unit_id' => $this->unit->id,
            'cost' => 100,
            'price' => 150,
            'tax_id' => $tax->id,
            'tax_category_id' => null,
            'product_usage' => 'composite',
            'negative_stock_policy' => 'allow',
            'is_active' => 1,
        ]);
        \Illuminate\Support\Facades\DB::table('tax_registrations')
            ->where('company_id', $companyId)
            ->where('branch_id', 5)
            ->update(['status' => 'inactive']);
        $this->context = new CompanyContext(4, 5, 4);
    }

    public function test_purchase_and_sale_views_render_lot_number_field(): void
    {
        $resPurchase = $this->actingAs($this->adminUser)->get('/purchases');
        $resPurchase->assertStatus(200);
        $resPurchase->assertSee('id="lot_number"', false);
        $resPurchase->assertSee('id="purchase-lot-mode"', false);

        $resSale = $this->actingAs($this->adminUser)->get('/sales');
        $resSale->assertStatus(200);
        $resSale->assertSee('id="lot_number"', false);
        $resSale->assertSee('id="sale-lot-mode"', false);
    }

    public function test_commercial_purchase_stores_lot_number_and_links_product_batch(): void
    {
        $lotNo = 'LOT-PUR-' . mt_rand(1000, 9999);
        $commercial = app(CommercialApplicationService::class);

        $unit = $this->unit;


        $purchase = $commercial->create('purchase', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => date('Y-m-d'),
            'lot_number' => $lotNo,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 10,
                    'net_unit_cost' => 100,
                    'purchase_unit_id' => $unit->id,
                ],
            ],
        ], 'idemp-pur-' . mt_rand(1000, 9999), $this->adminUser->id, $this->context);

        $this->assertNotNull($purchase);
        $this->assertEquals($lotNo, $purchase->lot_number);

        $productPurchase = ProductPurchase::where('purchase_id', $purchase->id)->first();
        $this->assertNotNull($productPurchase);
        $this->assertNotNull($productPurchase->product_batch_id);

        $batch = ProductBatch::find($productPurchase->product_batch_id);
        $this->assertNotNull($batch);
        $this->assertEquals($lotNo, $batch->batch_no);
    }

    public function test_commercial_sale_stores_lot_number_and_links_product_batch(): void
    {
        $lotNo = 'LOT-SALE-' . mt_rand(1000, 9999);
        $commercial = app(CommercialApplicationService::class);

        $unit = $this->unit;

        $sale = $commercial->create('sale', [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => date('Y-m-d'),
            'lot_number' => $lotNo,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'qty' => 5,
                    'net_unit_price' => 150,
                    'sale_unit_id' => $unit->id,
                ],
            ],
        ], 'idemp-sale-' . mt_rand(1000, 9999), $this->adminUser->id, $this->context);

        $this->assertNotNull($sale);
        $this->assertEquals($lotNo, $sale->lot_number);

        $productSale = Product_Sale::where('sale_id', $sale->id)->first();
        $this->assertNotNull($productSale);
        $this->assertNotNull($productSale->product_batch_id);

        $batch = ProductBatch::find($productSale->product_batch_id);
        $this->assertNotNull($batch);
        $this->assertEquals($lotNo, $batch->batch_no);
    }
}
