<?php

namespace Tests\Feature;

use App\Services\Commercial\CommercialReversalService;
use App\Services\Commercial\PurchaseApplicationService;
use App\Services\Commercial\PurchaseCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommercialTestCase;

class CommercialBoundariesTest extends CommercialTestCase
{
    public function test_empty_optional_fields_and_decimal_quantities_post_with_server_tax_and_charges(): void
    {
        $product = $this->stock();
        DB::table('taxes')->insert(['id' => 1, 'company_id' => $this->company->id, 'name' => 'Fixture tax', 'rate' => 10, 'is_active' => true]);
        $product->update(['tax_id' => 1]);
        $data = $this->saleData($product, ['items' => [['product_id' => $product->id, 'qty' => 1.125, 'net_unit_price' => 8]],
            'order_discount' => 1, 'shipping_cost' => 2, 'transport_name' => '', 'lr_number' => '']);
        $id = $this->postJson('/commercial/sale', $data, ['Idempotency-Key' => 'decimal-tax'])->assertCreated()->json('data.id');
        $this->assertEquals(10.9, DB::table('sales')->where('id', $id)->value('grand_total'));
        $this->assertEquals(10.9, DB::table('account_open_items')->sum('open_amount'));
        $this->assertEquals(DB::table('journal_items')->sum('debit'), DB::table('journal_items')->sum('credit'));
        $this->assertEquals(18.875, $product->fresh()->qty);
    }

    public function test_invalid_shapes_dates_and_absent_key_leave_no_document(): void
    {
        $data = $this->saleData($this->stock());
        $this->postJson('/commercial/sale', $data)->assertUnprocessable();
        $this->postJson('/commercial/sale', $data + ['due_date' => '2026-11-31'], ['Idempotency-Key' => 'bad-date'])->assertUnprocessable();
        $this->postJson('/commercial/sale/preview', ['items' => ['invalid']])->assertUnprocessable();
        $this->postJson('/commercial/sale', array_replace($data, ['items' => ['invalid']]), ['Idempotency-Key' => 'bad-shape'])->assertUnprocessable();
        $this->postJson('/commercial/sale', $data + ['idempotency_key' => ['invalid']])->assertUnprocessable();
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_batch_product_checks_reject_foreign_references_and_accept_repeated_owned_lines(): void
    {
        $owned = $this->stock();
        $foreign = $this->stock();
        $foreign->forceFill(['company_id' => $this->other->id])->save();
        DB::table('units')->insert(['id' => 2, 'company_id' => $this->other->id, 'name' => 'Foreign', 'unit_name' => 'PCS']);
        $first = ['product_id' => $owned->id, 'qty' => 1, 'net_unit_price' => 10, 'sale_unit_id' => 1];
        foreach ([['product_id' => $foreign->id], ['sale_unit_id' => 2], ['product_id' => '1 OR 1=1']] as $i => $invalid) {
            $data = $this->saleData($owned, ['items' => [$first, array_replace($first, $invalid)]]);
            $this->postJson('/commercial/sale', $data, ['Idempotency-Key' => 'foreign-batch-'.$i])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertEquals(20, $owned->fresh()->qty);
        $this->postJson('/commercial/sale', $this->saleData($owned, ['items' => [$first, array_replace($first, ['qty' => 2, 'sale_unit_id' => '1'])]]),
            ['Idempotency-Key' => 'repeated-owned'])->assertCreated();
        $this->assertSame(2, DB::table('product_sales')->count());
        $this->assertEquals(17, $owned->fresh()->qty);
        $this->assertEquals(20, $foreign->fresh()->qty);
    }

    public function test_staff_cannot_override_credit_without_the_company_role_permission(): void
    {
        Schema::create('permissions', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        Schema::create('role_has_permissions', function (Blueprint $t) { $t->unsignedInteger('role_id'); $t->unsignedInteger('permission_id'); });
        DB::table('permissions')->insert(['id' => 1, 'name' => 'sales-add']);
        DB::table('role_has_permissions')->insert(['role_id' => 4, 'permission_id' => 1]);
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', 1)->update(['role_id_override' => 4]);
        DB::table('customers')->where('id', 1)->update(['credit_limit' => 1]);
        $this->postJson('/commercial/sale', $this->saleData($this->stock(), ['credit_override_reason' => 'I approve myself']),
            ['Idempotency-Key' => 'staff-override'])->assertForbidden();
        $this->assertSame(0, DB::table('commercial_audit_events')->count());
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_free_physical_goods_receive_freight_and_service_bill_reversal_reconciles(): void
    {
        $stock = $this->stock(0);
        $service = $this->stock(0); $service->update(['type' => 'service']);
        $data = $this->purchaseData($stock, ['shipping_cost' => 3, 'items' => [
            ['product_id' => $stock->id, 'qty' => 1, 'net_unit_cost' => 0],
            ['product_id' => $service->id, 'qty' => 1, 'net_unit_cost' => 0],
        ]]);
        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($data, 'free-goods', 1, $this->context()));
        $this->assertEquals(3, DB::table('stock_movement_lines')->sum('value'));
        $this->assertEquals(3, $purchase->productPurchases->first()->valuation_amount);
        app(CommercialReversalService::class)->reverse($purchase, '2026-10-04', 'Incorrect supplier bill', $this->context());
        $this->assertEquals(0, $stock->fresh()->qty);
        $this->assertEquals(0, DB::table('account_open_items')->sum('open_amount'));
        $this->assertEquals(0, DB::table('stock_movement_lines')->sum('value'));
    }

    public function test_serial_batch_expiry_and_dimension_purchase_and_sale_preserve_identities(): void
    {
        require_once database_path('migrations/2021_03_07_093606_create_product_batches_table.php');
        (new \CreateProductBatchesTable)->up();
        Schema::table('product_batches', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id')->nullable(); $t->date('mfg_date')->nullable(); $t->decimal('mrp', 18, 4)->nullable();
        });
        $serial = $this->stock(0); $serial->update(['is_imei' => true]);
        $batch = $this->stock(0); $batch->update(['is_batch' => true]);
        $piece = $this->stock(0);
        $purchase = app(PurchaseApplicationService::class)->create(new PurchaseCommand($this->purchaseData($serial, ['items' => [
            ['product_id' => $serial->id, 'qty' => 1, 'net_unit_cost' => 10, 'serials' => ['SOLAR-1']],
            ['product_id' => $batch->id, 'qty' => 2.125, 'net_unit_cost' => 8, 'batch' => ['batch_no' => 'FMCG-1', 'expired_date' => '2027-10-03']],
            ['product_id' => $piece->id, 'qty' => 1, 'net_unit_cost' => 20, 'dimensions' => ['identity_no' => 'TIMBER-1', 'length' => 10, 'width' => 2, 'thickness' => 1]],
        ]]), 'identities-in', 1, $this->context()));
        $this->assertEquals(20, DB::table('stock_dimensions')->value('computed_volume'));
        $this->assertSame('2027-10-03', substr(DB::table('product_batches')->value('expired_date'), 0, 10));
        $this->postJson('/commercial/sale', $this->saleData($serial, ['items' => [
            ['product_id' => $serial->id, 'qty' => 1, 'net_unit_price' => 15, 'serials' => ['SOLAR-1']],
            ['product_id' => $batch->id, 'qty' => 1.125, 'net_unit_price' => 10, 'product_batch_id' => DB::table('product_batches')->value('id')],
            ['product_id' => $piece->id, 'qty' => 1, 'net_unit_price' => 30, 'stock_identity_id' => DB::table('stock_identities')->where('identity_no', 'TIMBER-1')->value('id')],
        ]]), ['Idempotency-Key' => 'identities-out'])->assertCreated();
        $this->assertEquals(0, $serial->fresh()->qty);
        $this->assertEquals(1, $batch->fresh()->qty);
        $this->assertEquals(0, $piece->fresh()->qty);
        $this->assertNotNull($purchase->posted_at);
    }

    public function test_inline_party_retry_and_alias_remain_company_owned(): void
    {
        $data = ['name' => 'Inline customer', 'customer_group_id' => 1, 'search_alias' => 'Walkin', 'credit_days' => 30, 'credit_limit' => 200];
        $first = $this->postJson('/commercial/sale/masters/parties', $data, ['Idempotency-Key' => 'inline-party'])->assertCreated()->json('data.id');
        $second = $this->postJson('/commercial/sale/masters/parties', $data, ['Idempotency-Key' => 'inline-party'])->assertCreated()->json('data.id');
        $this->assertSame($first, $second);
        $this->getJson('/commercial/sale/search/parties?q=Walk')->assertOk()->assertJsonPath('data.0.id', $first);
        $this->postJson('/commercial/sale/masters/parties', array_replace($data, ['name' => 'Changed']), ['Idempotency-Key' => 'inline-party'])->assertConflict();
        $this->assertSame(1, DB::table('customers')->where('name', 'Inline customer')->count());
    }

    public function test_later_payment_retry_and_reversal_keep_history_and_clear_open_items(): void
    {
        $product = $this->stock();
        $id = $this->postJson('/commercial/sale', $this->saleData($product), ['Idempotency-Key' => 'pay-source'])->assertCreated()->json('data.id');
        $data = ['amount' => 3, 'paying_method' => 'Cash', 'account_id' => 1, 'business_date' => '2026-10-03'];
        $url = '/commercial/sale/'.$id.'/payments';
        $first = $this->postJson($url, $data, ['Idempotency-Key' => 'later-payment'])->assertCreated()->json('data.id');
        $second = $this->postJson($url, $data, ['Idempotency-Key' => 'later-payment'])->assertCreated()->json('data.id');
        $this->assertSame($first, $second);
        $this->postJson($url, array_replace($data, ['amount' => 4]), ['Idempotency-Key' => 'later-payment'])->assertUnprocessable();
        $this->postJson($url, $data, ['Idempotency-Key' => ''])->assertUnprocessable();
        $this->assertEquals(17, DB::table('account_open_items')->sum('open_amount'));
        $this->get('/commercial/sale/'.$id.'/reverse')->assertOk()->assertSee('Reverse document');
        $this->postJson('/commercial/sale/'.$id.'/reverse', ['business_date' => '2026-10-04', 'reason' => 'Cancelled fixture invoice'])->assertOk();
        $this->assertEquals(0, DB::table('account_open_items')->sum('open_amount'));
        $this->assertEquals(20, $product->fresh()->qty);
        $this->assertSame(1, DB::table('payments')->count());
        $this->postJson($url, $data, ['Idempotency-Key' => 'after-reversal'])->assertConflict();
    }

    public function test_inline_product_creates_owned_master_and_retries_without_duplicates(): void
    {
        $data = ['name' => 'Inline product', 'code' => 'INLINE', 'category_id' => 1, 'unit_id' => 1, 'price' => 12, 'cost' => 6];
        $first = $this->postJson('/commercial/purchase/masters/products', $data, ['Idempotency-Key' => 'inline-product'])->assertCreated()->json('data.id');
        $second = $this->postJson('/commercial/purchase/masters/products', $data, ['Idempotency-Key' => 'inline-product'])->assertCreated()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertSame($this->company->id, (int) DB::table('products')->where('id', $first)->value('company_id'));
        $this->assertSame(1, DB::table('products')->where('code', 'INLINE')->count());
    }

    public function test_statement_pages_and_pending_filter_do_not_hide_older_unpaid_bills(): void
    {
        $this->postJson('/commercial/sale', $this->saleData($this->stock()), ['Idempotency-Key' => 'statement-source'])->assertCreated();
        $template = (array) DB::table('account_open_items')->first();
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $row = $template; unset($row['id']);
            $row['journal_item_id'] = DB::table('journal_items')->insertGetId([
                'journal_entry_id' => $template['journal_entry_id'], 'chart_of_account_id' => $template['account_id'],
                'debit' => 0, 'credit' => 0, 'company_id' => $this->company->id]);
            $row['document_date'] = '2026-10-04'; $row['open_amount'] = 0; $row['status'] = 'closed'; $rows[] = $row;
        }
        DB::table('account_open_items')->insert($rows);
        $this->getJson('/commercial/sale/party/1')->assertOk()->assertJsonCount(100, 'data.items')->assertJsonPath('data.next_page', 2);
        $this->getJson('/commercial/sale/party/1?page=2')->assertOk()->assertJsonCount(6, 'data.items')->assertJsonPath('data.next_page', null);
        $this->getJson('/commercial/sale/party/1?pending=1')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.outstanding', 20);
    }

    public function test_purchase_cost_and_hsn_update_require_explicit_selection(): void
    {
        $product = $this->stock(0);
        $this->postJson('/commercial/purchase', $this->purchaseData($product, ['update_item_cost' => true, 'update_item_hsn' => true,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'net_unit_cost' => 8, 'hsn_code' => '4407']]]),
            ['Idempotency-Key' => 'cost-hsn'])->assertCreated();
        $this->assertEquals(8, $product->fresh()->cost);
        $this->assertSame('4407', $product->fresh()->hsn_code);
    }

    public function test_additive_migration_resumes_without_removing_document_or_draft_history(): void
    {
        $this->postJson('/commercial/sale', $this->saleData($this->stock()), ['Idempotency-Key' => 'migration-source'])->assertCreated();
        $this->postJson('/commercial/sale/drafts', ['version' => 0, 'payload' => $this->snapshot('sale', ['note' => 'Retain me'])])->assertOk();
        $migration = require database_path('migrations/2026_10_05_000001_create_shared_commercial_contracts.php');
        $migration->up();
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('sale_drafts')->count());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
