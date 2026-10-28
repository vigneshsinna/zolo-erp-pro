<?php

namespace Tests\Feature;

use App\Models\{User, ProductReturn};
use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\ReturnsDocumentTestCase;

class ComplianceHttpTest extends ReturnsDocumentTestCase
{
    public function test_document_delivery_form_keeps_posted_document_and_exposes_retry_after_failure(): void
    {
        $sale = $this->gstSale();
        $this->from('/compliance/documents/sale/'.$sale->id)->post('/compliance/documents/sale/'.$sale->id.'/dispatch',
            ['channel' => 'email', 'recipient' => 'fixture@example.invalid', 'idempotency_key' => 'form-dispatch'])
            ->assertSessionHasNoErrors()->assertRedirect('/compliance/documents/sale/'.$sale->id);
        $log = DB::table('document_dispatch_logs')->first(); $this->assertNotNull($log);
        $this->assertSame('failed', $log->status); $this->assertSame(1, $log->attempts);
        $this->get('/compliance/documents/sale/'.$sale->id)->assertOk()->assertSee('Retry delivery');
        $this->postJson('/api/v1/compliance/dispatch/'.$log->id.'/retry')->assertOk();
        $this->assertSame(2, DB::table('document_dispatch_logs')->value('attempts'));
        $this->assertSame(1, DB::table('journal_entries')->count()); $this->assertNotNull($sale->fresh()->posted_at);
    }
    public function test_web_and_api_note_posting_share_retry_and_approval_contracts(): void
    {
        $sale = $this->gstSale(); $data = $this->noteData($sale);
        $web = $this->postJson('/compliance/sale/'.$sale->id.'/notes', $data, ['Idempotency-Key' => 'http-note'])->assertCreated();
        $id = $web->json('data.id');
        $this->postJson('/api/v1/compliance/sale/'.$sale->id.'/notes', $data, ['Idempotency-Key' => 'http-note'])
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/compliance/sale/notes/'.$id.'/approve')->assertOk()->assertJsonPath('data.status', 'posted');
        $this->getJson('/api/v1/compliance/documents/sale_note/'.$id)->assertOk()->assertJsonPath('data.document.grand_total', 11.8);
        $this->assertSame(2, DB::table('journal_entries')->count());
        $this->get('/sales/gen_invoice/'.$sale->id)->assertOk()->assertSee($sale->reference_no);
        $pdf = $this->get('/compliance/documents/sale_note/'.$id.'/print?output=pdf')->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        // The old return form now opens the Returns tab of the Sales workspace with this bill loaded.
        $this->get('/return-sale/create?reference_no='.urlencode($sale->reference_no))
            ->assertRedirect(route('sales.index', ['returns' => 1, 'bill' => $sale->id]));
        $this->deleteJson('/return-sale/'.$id)->assertStatus(409);
        $this->assertSame(1, DB::table('returns')->count());
    }

    public function test_gate_and_foreign_context_cannot_read_or_mutate_compliance_documents(): void
    {
        $sale = $this->gstSale();
        $this->actingAs(User::findOrFail(2));
        $this->getJson('/compliance/documents/sale/'.$sale->id)->assertNotFound();
        $this->postJson('/compliance/sale/'.$sale->id.'/notes', $this->noteData($sale), ['Idempotency-Key' => 'foreign'])->assertNotFound();
        $this->actingAs(User::findOrFail(1)); config(['compliance.enabled' => false]);
        $this->getJson('/compliance/gst/report')->assertStatus(503);
        $this->assertSame(0, DB::table('returns')->count());
    }

    public function test_company_permissions_and_encrypted_credentials_are_not_flashes(): void
    {
        Schema::create('permissions', function ($t) { $t->increments('id'); $t->string('name'); });
        Schema::create('role_has_permissions', function ($t) { $t->unsignedInteger('role_id'); $t->unsignedInteger('permission_id'); });
        DB::table('users')->where('id', 1)->update(['role_id' => 4]);
        $this->actingAs(User::findOrFail(1));
        $this->getJson('/compliance/gst/report')->assertForbidden();
        DB::table('permissions')->insert(['id' => 1, 'name' => 'gst-index']);
        DB::table('role_has_permissions')->insert(['role_id' => 4, 'permission_id' => 1]);
        $this->getJson('/compliance/gst/report')->assertOk();
        $this->postJson('/compliance/setup/return-policy', ['amount' => 10, 'days' => 30])->assertForbidden();
        DB::table('users')->where('id', 1)->update(['role_id' => 1]); $this->actingAs(User::findOrFail(1));
        $this->post('/compliance/setup/channel', ['channel' => 'sms', 'from' => 'bad', 'auth_token' => 'sensitive-test-token'])->assertSessionHasErrors();
        $this->assertNull(session()->getOldInput('auth_token'));
    }

    public function test_final_quantity_return_consumes_saved_discount_and_rounding_residual(): void
    {
        $p = $this->gstProduct();
        $sale = app(\App\Services\Commercial\SaleApplicationService::class)->create(new \App\Services\Commercial\SaleCommand(
            $this->saleData($p, ['order_discount' => .0001, 'items' => [['product_id' => $p->id, 'qty' => 3, 'net_unit_price' => .3333]]]), 'fractional', 1, $this->context()));
        for ($i = 0; $i < 3; $i++) $this->postedReturn($sale, [], 'part-'.$i);
        $this->assertEqualsWithDelta($sale->grand_total, DB::table('returns')->sum('grand_total'), .000001);
        $this->assertEquals(0, DB::table('account_open_items')->sum('open_amount'));
        // The invoice rounded 1.1798 down to 1; the note that returns the last unit reverses that round-off.
        $this->assertEquals(-.1798, $sale->attributes_json['round_off']);
        $rounding = DB::table('journal_items')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_items.chart_of_account_id')->where('sub_type', 'rounding');
        $this->assertEqualsWithDelta(0, (clone $rounding)->sum('debit') - (clone $rounding)->sum('credit'), .000001);
        $this->assertEquals(20, $p->fresh()->qty);
        $this->expectException(\LogicException::class);
        ProductReturn::forceCreate(['return_id' => DB::table('returns')->value('id')]);
    }
}
