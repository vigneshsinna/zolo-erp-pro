<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Industry\IndustryProfileService;
use App\Services\Manufacturing\ProductionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OperationsTestCase;

class OperationsWebTest extends OperationsTestCase
{
    public function test_web_forms_and_http_production_use_same_transaction_engine(): void
    {
        $this->postJson('/operations/production-plan', ['idempotency_key' => []])->assertUnprocessable();
        $this->withoutExceptionHandling();
        $input = $this->material(100); $output = $this->material(0); $bom = $this->bom($input, $output);
        $this->get('/operations/manufacturing')->assertOk()->assertSee('Create BOM version')->assertSee('Publish BOM');
        $posted = $this->postJson('/operations/production-plan', ['bom_id' => $bom->id, 'warehouse_id' => 1,
            'planned_qty' => 10, 'business_date' => '2026-10-03', 'idempotency_key' => 'web-plan'])->assertCreated()->json('data');
        $this->get('/operations/production/'.$posted['id'])->assertOk()->assertSee('Actual consumption');
        $this->postJson('/operations/production-complete/'.$posted['id'], ['completed_qty' => 10, 'business_date' => '2026-10-03',
            'idempotency_key' => 'web-complete'])->assertCreated()->assertJsonPath('data.status', 'completed');
        $this->get('/operations/production/'.$posted['id'])->assertOk()->assertSee('Posted outputs');
        $this->assertEquals(10, $output->fresh()->qty);
        Sanctum::actingAs(User::findOrFail(1));
        $this->getJson('/api/v1/operations/production/'.$posted['id'])->assertOk()->assertJsonPath('data.record.status', 'completed');
    }

    public function test_job_work_forms_printing_and_profiles_render_without_foreign_records(): void
    {
        app(IndustryProfileService::class)->apply('textile', 'wholesale', $this->context(), 1);
        $material = $this->material(500); [$order, $dispatch] = $this->job($material);
        $this->get('/operations/job-work')->assertOk()->assertSee('Material outside');
        $this->get('/operations/job-work/'.$order->id)->assertOk()->assertSee('Send material')->assertSee('Receive and reconcile');
        $this->get('/operations/dispatch/'.$dispatch->id.'/print')->assertOk()->assertSee($dispatch->reference_no);
        $printed = $this->get('/operations/dispatch/'.$dispatch->id.'/print?format=dot_matrix')->assertOk()->getContent();
        $this->assertCount(68, explode("\r\n", $printed));
        $this->get('/operations/profiles')->assertOk()->assertSee('Business subtype');
        DB::table('job_work_orders')->where('id', $order->id)->update(['company_id' => $this->other->id]);
        $this->getJson('/operations/job-work/'.$order->id)->assertNotFound();
        $this->getJson('/operations/dispatch/'.$dispatch->id.'/print')->assertNotFound();
    }

    public function test_industry_profiles_configure_the_entry_aids_of_the_shared_sales_and_purchase_pages(): void
    {
        // The normal pages need the full legacy schema; their rendered config is covered by DocumentEntryWebTest.
        $aids = app(\App\Services\Industry\DocumentEntryAids::class);
        foreach (['fmcg' => 'distribution', 'textile' => 'wholesale', 'timber' => 'trading', 'solar' => 'epc'] as $profile => $subtype) {
            app(IndustryProfileService::class)->apply($profile, $subtype, $this->context(), 1);
            $purchase = $aids->forContext($this->context(), 'purchase');
            $this->assertSame($profile, $purchase['tracking']['profile']);
            $this->assertSame(\App\Services\Industry\DocumentEntryAids::KEYS, array_keys($purchase['aids']));
            $this->assertNotContains(false, $purchase['aids'], 'the page offers every aid unless the profile switches one off');
            $this->assertSame($profile === 'timber', $purchase['tracking']['dimensions'], 'dimension tracking follows its capability');
        }
        // An explicit false in the profile's entry_aids switches a single aid off; nothing else changes.
        \Illuminate\Support\Facades\DB::table('company_industry_settings')->where('company_id', $this->company->id)->update([
            'settings_json' => json_encode(['entry_aids' => ['previous_rates' => false, 'unknown' => false]])]);
        $off = $aids->forContext($this->context(), 'sale')['aids'];
        $this->assertFalse($off['previous_rates']);
        $this->assertTrue($off['clone_invoice']);
        $this->assertArrayNotHasKey('unknown', $off);
    }

    public function test_gate_permission_and_foreign_context_reject_before_operations(): void
    {
        config(['operations.enabled' => false]);
        $this->getJson('/operations/manufacturing')->assertForbidden();
        $this->postJson('/operations/production-plan', [])->assertForbidden();
        config(['operations.enabled' => true]);
        $this->withHeader('X-Company-ID', $this->other->id)->getJson('/operations/profiles')->assertForbidden();
        $this->flushHeaders();
        DB::table('company_user')->where('user_id', 1)->update(['role_id_override' => 4]);
        $this->getJson('/operations/profiles')->assertForbidden();
    }
}
