<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Commercial;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ReadOpenOrders;
use App\Models\Accounting\FiscalYear;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class PurchaseOrderActionTest extends TestCase
{
    use UsesAiMysql;

    private User $adminUser;
    private User $restrictedUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private Warehouse $warehouse;
    private Supplier $supplier;
    private Product $productA;
    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_actions.execution_enabled', true);
        config()->set('commercial.enabled', true);
        // Opt in only for these disposable GST calculation/execution fixtures.
        config()->set('compliance.enabled', true);
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.read_only', false);
        $this->app->forgetInstance(\App\Ai\Services\AiFeatureGate::class);

        // Ensure roles
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'is_active' => true, 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 4], ['name' => 'Viewer', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'PO Admin User',
            'email' => 'po_admin_' . uniqid() . '@example.com',
            'phone' => '9777777771',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->restrictedUser = User::where('role_id', 4)->first() ?? User::create([
            'name' => 'PO Restricted Viewer',
            'email' => 'po_viewer_' . uniqid() . '@example.com',
            'phone' => '9777777772',
            'password' => bcrypt('secret'),
            'role_id' => 4,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        // Base company context
        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Kovai Mills Ltd',
            'code' => 'KOVAI_MILLS_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->branch = $this->company->branches()->where('code', 'MAIN')->first()
            ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'branch_type' => 'branch']);

        $this->fiscalYear = $this->company->fiscalYears()->first()
            ?? $this->company->fiscalYears()->create([
                'name' => 'FY 2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open',
                'is_closed' => false,
            ]);

        // Memberships
        $this->company->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
            $this->restrictedUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branch->id]
        );
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->restrictedUser->id, 'branch_id' => $this->branch->id]
        );

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('branch_id', $this->branch->id)->first()
            ?? Warehouse::create([
                'name' => 'Main Yarn Store',
                'address' => 'Plot 10, Weaving Sector',
                'is_active' => true,
                'company_id' => $this->company->id,
                'branch_id' => $this->branch->id,
            ]);

        $this->supplier = Supplier::where('company_id', $this->company->id)->first()
            ?? Supplier::create([
                'name' => 'Sri Lakshmi Cotton Traders',
                'company_name' => 'Sri Lakshmi Cotton Ltd',
                'vat_number' => '33AAACC1206D1ZN',
                'phone_number' => '9842100001',
                'email' => 'cotton@lakshmi.test',
                'address' => '12 Mill Road, Coimbatore',
                'city' => 'Coimbatore',
                'state' => 'Tamil Nadu',
                'is_active' => true,
                'company_id' => $this->company->id,
            ]);

        if (empty($this->supplier->vat_number) || $this->supplier->vat_number !== '33AAACC1206D1ZN') {
            $this->supplier->update(['vat_number' => '33AAACC1206D1ZN', 'state' => 'Tamil Nadu']);
        }

        DB::table('party_tax_profiles')->updateOrInsert(
            [
                'company_id' => $this->company->id,
                'party_type' => 'supplier',
                'party_id' => $this->supplier->id,
            ],
            [
                'gstin' => '33AAACC1206D1ZN',
                'state_code' => '33',
                'registration_type' => 'regular',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $unit = Unit::where('company_id', $this->company->id)->first()
            ?? Unit::forceCreate(['unit_code' => 'KG', 'unit_name' => 'Kilogram', 'company_id' => $this->company->id, 'is_active' => true]);
        $category = Category::where('company_id', $this->company->id)->first()
            ?? Category::forceCreate(['name' => 'Raw Cotton', 'company_id' => $this->company->id, 'is_active' => true]);

        $taxCategoryId = DB::table('tax_categories')->where('company_id', $this->company->id)->where('name', 'GST 5%')->value('id')
            ?? DB::table('tax_categories')->insertGetId([
                'company_id' => $this->company->id,
                'name' => 'GST 5%',
                'supply_type' => 'taxable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('tax_rates')->updateOrInsert(
            ['company_id' => $this->company->id, 'tax_category_id' => $taxCategoryId],
            [
                'rate' => 5.0,
                'effective_from' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->productA = Product::where('company_id', $this->company->id)->where('code', 'YARN-40S')->first()
            ?? Product::forceCreate([
                'name' => 'Combed Cotton Yarn 40s',
                'code' => 'YARN-40S',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => '250.00',
                'price' => '290.00',
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'category_id' => $category->id,
                'tax_category_id' => $taxCategoryId,
                'hsn_code' => '5205',
                'company_id' => $this->company->id,
                'is_active' => true,
            ]);

        $this->productB = Product::where('company_id', $this->company->id)->where('code', 'YARN-60S')->first()
            ?? Product::forceCreate([
                'name' => 'Carded Cotton Yarn 60s',
                'code' => 'YARN-60S',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'cost' => '320.00',
                'price' => '360.00',
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'category_id' => $category->id,
                'tax_category_id' => $taxCategoryId,
                'hsn_code' => '5205',
                'company_id' => $this->company->id,
                'is_active' => true,
            ]);
    }

    private function contextHeaders(): array
    {
        return [
            'X-Company-Id' => (string) $this->company->id,
            'X-Branch-Id' => (string) $this->branch->id,
            'X-Financial-Year-Id' => (string) $this->fiscalYear->id,
        ];
    }

    public function test_po_terms_round_trip_match_normal_domain_without_payments(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');
        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);
        $payments = DB::table('payments')->count();
        $journals = DB::table('journal_entries')->where('company_id', $this->company->id)->count();
        foreach ([['cash', 0], ['credit', 30], ['credit', 45]] as [$type, $days]) {
            $slots = ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'date' => '2026-11-17', 'payment_terms_type' => $type, 'credit_days' => $days,
                'items' => [['product_id' => $this->productA->id, 'qty' => '2.00', 'net_unit_cost' => '250.00']]];
            $proposal = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
                'action_key' => 'purchase_order.create.v1', 'slots' => $slots,
            ])->assertCreated()->json('proposal');
            $this->assertSame($type, $proposal['preview']['payment_terms']['payment_terms_type']);
            $this->assertSame($days, $proposal['preview']['payment_terms']['credit_days']);
            $created = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals/'.$proposal['id'].'/approve', [
                'expected_version' => 1, 'execute' => true,
            ])->assertOk()->json('execution');
            $purchase = Purchase::where('company_id', $this->company->id)->findOrFail($created['receipt']['resource_id']);
            $this->assertSame($type, $purchase->attributes_json['payment_terms_type']);
            $this->assertSame($days, $purchase->attributes_json['credit_days']);
            $this->assertEquals(4, $purchase->status);
            $this->assertEquals(0, $purchase->paid_amount);
            $this->assertEquals(2, $purchase->payment_status);
            $this->assertSame('2026-11-17', $purchase->created_at->toDateString());
            unset($slots['payment_terms_type']);
            $normal = app(\App\Services\Commercial\CommercialApplicationService::class)->create('purchase', $slots + [
                'status' => 4, 'payment_status' => 1, 'paid_amount' => 0, 'paying_method' => ucfirst($type), 'business_date' => $slots['date'],
            ], 'terms-normal-'.\Illuminate\Support\Str::uuid(), $this->adminUser->id, $context);
            $this->assertSame($purchase->attributes_json['payment_terms_type'], $normal->attributes_json['payment_terms_type']);
            $this->assertSame($purchase->attributes_json['due_date'], $normal->attributes_json['due_date']);
        }
        $this->assertSame($payments, DB::table('payments')->count());
        $this->assertSame($journals, DB::table('journal_entries')->where('company_id', $this->company->id)->count());
    }

    public function test_terms_edit_requires_new_signed_version_and_supplier_default_is_fresh(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');
        config(['ai_actions.execution_enabled' => false]);
        $proposal = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1', 'slots' => ['supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id, 'date' => '2026-11-17', 'payment_terms_type' => 'credit', 'credit_days' => 30,
                'items' => [['product_id' => $this->productA->id, 'qty' => '2.00', 'net_unit_cost' => '250.00']]],
        ])->assertCreated()->json('proposal');
        $url = '/api/v1/ai/proposals/'.$proposal['id'];
        $this->withHeaders($this->contextHeaders())->postJson($url.'/approve', ['expected_version' => 1])->assertOk();
        $changed = $this->withHeaders($this->contextHeaders())->postJson($url.'/change-request', [
            'expected_version' => 1, 'changes' => ['payment_terms_type' => 'credit', 'credit_days' => 45],
        ])->assertOk()->assertJsonPath('proposal.version', 2)->json('proposal');
        $this->assertSame('2027-01-01', $changed['preview']['payment_terms']['due_date']);
        $this->withHeaders($this->contextHeaders())->postJson($url.'/approve', ['expected_version' => 1])->assertUnprocessable();
        $this->supplier->update(['credit_days' => 60]);
        config(['ai_actions.execution_enabled' => true]);
        $this->withHeaders($this->contextHeaders())->postJson($url.'/approve', ['expected_version' => 2])->assertUnprocessable();
    }

    public function test_cash_with_credit_days_is_rejected_before_proposal_creation(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');
        $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1', 'slots' => ['supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id, 'payment_terms_type' => 'cash', 'credit_days' => 30,
                'items' => [['product_id' => $this->productA->id, 'qty' => '2.00', 'net_unit_cost' => '250.00']]],
        ])->assertUnprocessable();
    }

    public function test_complete_conversation_with_fixture_voice_approval_and_authoritative_readback(): void
    {
        config(['erp_ai.conversation_enabled' => true, 'ai_multimodal.enabled' => true]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanyMultimodal($this->company->id, true);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->actingAs($this->adminUser, 'sanctum')->withHeaders($this->contextHeaders());
        foreach (['D08 Supplier Chennai', 'D08 Supplier Erode'] as $name) {
            $supplier = Supplier::create(['company_id' => $this->company->id, 'name' => $name, 'phone_number' => '7000000011',
                'vat_number' => '33AAACC1206D1ZN', 'state' => 'Tamil Nadu', 'is_active' => true]);
            DB::table('party_tax_profiles')->updateOrInsert(['company_id' => $this->company->id, 'party_type' => 'supplier', 'party_id' => $supplier->id],
                ['gstin' => '33AAACC1206D1ZN', 'state_code' => '33', 'registration_type' => 'regular', 'created_at' => now(), 'updated_at' => now()]);
        }
        $unit = Unit::forceCreate(['company_id' => $this->company->id, 'unit_code' => 'PAIR', 'unit_name' => 'Pairs', 'is_active' => true]);
        $this->productA->update(['name' => 'Cotton Shirt Blue', 'unit_id' => $unit->id, 'purchase_unit_id' => $unit->id, 'sale_unit_id' => $unit->id]);
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $task = $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'message' => 'Create purchase order',
            'client_turn_id' => (string) \Illuminate\Support\Str::uuid()])->assertOk()->json('workflow');
        $send = function (string $message, array $extra = []) use ($thread, &$task): array {
            $task = $this->postJson(route('ai.chat'), $extra + ['thread_id' => $thread, 'active_task_id' => $task['task_id'],
                'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) \Illuminate\Support\Str::uuid(), 'message' => $message])
                ->assertOk()->json('workflow');
            return $task;
        };
        $send('D08 Supplier');
        $this->assertCount(2, $task['choices']);
        $label = $task['choices'][1]['label'];
        $media = \App\Models\AiMediaAsset::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'financial_year_id' => $this->fiscalYear->id, 'actor_id' => $this->adminUser->id, 'thread_id' => $thread, 'workflow_task_id' => $task['task_id'],
            'source_kind' => 'audio', 'original_mime' => 'audio/wav', 'canonical_mime' => 'audio/wav', 'size_bytes' => 100,
            'sha256' => hash('sha256', 'D08 fixture'), 'private_disk' => 'local', 'private_key' => 'fixture.wav', 'state' => 'extracted', 'expires_at' => now()->addHour()]);
        $extraction = \App\Models\AiMediaExtraction::create(['company_id' => $this->company->id, 'media_id' => $media->id,
            'revision' => 1, 'model_provider' => 'fixture', 'model_key' => 'fixture', 'prompt_version' => 'fixture',
            'extraction_schema_version' => 'media.transcript.v1', 'extracted_json' => ['transcript_text' => 'Use the second supplier', 'warnings' => []],
            'confirmation_status' => 'confirmed', 'confirmed_by' => $this->adminUser->id, 'confirmed_at' => now()]);
        $send('Use the second supplier', ['choice_set_id' => $task['choice_set_id'], 'media_ids' => [$media->id], 'voice_extraction_revision' => 1]);
        $this->assertStringStartsWith('D08 Supplier', $label);
        $transcript = 'add 100 pairs of Cotton Shirt Blue at 480';
        $reply = $extraction->replicate();
        $reply->revision = 2;
        $reply->extracted_json = ['transcript_text' => $transcript, 'warnings' => []];
        $reply->save();
        $send($transcript, ['media_ids' => [$media->id], 'voice_extraction_revision' => 2]);
        $this->assertSame('needs_input', $task['status']);
        // Conversational PO now requires the explicit destination warehouse.
        $send('warehouse '.$this->warehouse->name);
        $send('Credit 30 days');
        $this->assertSame('proposal_created', $task['status']);
        $this->assertSame('50400.00', $task['preview']['totals']['grand_total']);
        $this->assertSame($label, $task['preview']['supplier']['name'].' (GSTIN: '.$task['preview']['supplier']['gst_number'].')');
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        $this->assertSame(1, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
        $this->assertSame(2, DB::table('ai_media_extractions')->where('media_id', $media->id)->count());
        $history = $this->getJson(route('ai.threads.show', $thread))->assertOk()->json('messages');
        $this->assertCount(12, $history);
        foreach (array_filter($history, fn ($message) => $message['role'] === 'assistant') as $message) {
            $this->assertSame($task['task_id'], $message['facts'][0]['data']['task_id']);
        }
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
        $payments = DB::table('payments')->count();
        $journals = DB::table('journal_entries')->where('company_id', $this->company->id)->count();
        $url = '/api/v1/ai/proposals/'.$task['proposal_id'].'/approve';
        $approval = ['expected_version' => $task['version'], 'execute' => true];
        $execution = $this->postJson($url, $approval)->assertOk()->json('execution');
        $this->postJson($url, $approval)->assertOk();
        $purchase = Purchase::where('company_id', $this->company->id)->findOrFail($execution['receipt']['resource_id']);
        $this->assertEquals(4, $purchase->status);
        $this->assertEquals(0, $purchase->paid_amount);
        $this->assertSame('credit', $purchase->attributes_json['payment_terms_type']);
        $this->assertSame(30, $purchase->attributes_json['credit_days']);
        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame($payments, DB::table('payments')->count());
        $this->assertSame($journals, DB::table('journal_entries')->where('company_id', $this->company->id)->count());
        $this->getJson('/api/v1/ai/workflow-tasks/'.$task['task_id'])->assertOk()->assertJsonPath('proposal.status', 'completed');
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'message' => 'quantity 50',
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_selected_purchase_unit_is_preserved_and_unknown_named_unit_blocks(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');
        $unit = Unit::forceCreate(['company_id' => $this->company->id, 'unit_code' => 'BALE', 'unit_name' => 'Bale',
            'base_unit' => $this->productA->unit_id, 'operator' => '*', 'operation_value' => 100, 'is_active' => true]);
        $slots = ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'date' => '2026-11-17', 'payment_terms_type' => 'cash', 'credit_days' => 0,
            'items' => [['client_line_id' => 'keep', 'product_id' => $this->productA->id, 'qty' => '0.125',
                'net_unit_cost' => '250.125', 'purchase_unit_id' => $unit->id]]];
        $task = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1', 'slots' => $slots,
        ])->assertCreated()->json('task');
        $proposal = $this->postJson('/api/v1/ai/workflow-tasks/'.$task['id'].'/prepare-proposal')->assertCreated()->json('proposal');
        $line = json_decode(DB::table('ai_action_proposals')->where('id', $proposal['id'])->value('payload_json'), true)['items'][0];
        $this->assertSame($unit->id, $line['purchase_unit_id']);
        $this->assertSame('0.125', $proposal['preview']['items'][0]['qty']);
        $this->assertSame('250.125', $proposal['preview']['items'][0]['net_unit_cost']);
        unset($slots['items'][0]['purchase_unit_id']);
        $slots['items'][0]['uom'] = 'Unknown unit';
        $invalid = $this->postJson('/api/v1/ai/workflow-tasks', ['action_key' => 'purchase_order.create.v1', 'slots' => $slots])
            ->assertCreated()->json('task');
        $this->assertNotSame('ready', $invalid['status']);
        $this->patchJson('/api/v1/ai/workflow-tasks/'.$invalid['id'].'/inputs', ['expected_revision' => $invalid['revision'],
            'corrections' => [['field' => 'items[keep].purchase_unit_id', 'value' => $unit->id, 'confirmation' => 'user_selected']]])
            ->assertOk()->assertJsonPath('evaluation.is_ready', true);
        $unit->update(['operation_value' => 200]);
        $this->postJson('/api/v1/ai/proposals/'.$proposal['id'].'/approve', ['expected_version' => 1, 'execute' => true])->assertUnprocessable();
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
    }

    public function test_cancelled_conversation_revokes_linked_preview_approval(): void
    {
        config(['erp_ai.conversation_enabled' => true]);
        $this->actingAs($this->adminUser, 'sanctum')->withHeaders($this->contextHeaders());
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $task = $this->postJson('/api/v1/ai/workflow-tasks', ['thread_id' => $thread, 'action_key' => 'purchase_order.create.v1',
            'slots' => ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'payment_terms_type' => 'cash', 'credit_days' => 0,
                'items' => [['product_id' => $this->productA->id, 'qty' => '2.00', 'net_unit_cost' => '250.00']]],
        ])->assertCreated()->json('task');
        $proposal = $this->postJson('/api/v1/ai/workflow-tasks/'.$task['id'].'/prepare-proposal')->assertCreated()->json('proposal');
        $revision = DB::table('ai_workflow_tasks')->where('id', $task['id'])->value('revision');
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'active_task_id' => $task['id'], 'expected_task_revision' => $revision,
            'client_turn_id' => (string) \Illuminate\Support\Str::uuid(), 'message' => 'Cancel this purchase order'])->assertOk();
        $this->assertSame('cancelled', DB::table('ai_action_proposals')->where('id', $proposal['id'])->value('status'));
        $this->postJson('/api/v1/ai/proposals/'.$proposal['id'].'/approve', ['expected_version' => 1, 'execute' => true])->assertUnprocessable();
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
    }

    public function test_reloading_ready_task_reuses_unchanged_proposal_version(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');
        $task = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/workflow-tasks', [
            'action_key' => 'purchase_order.create.v1', 'slots' => ['supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id, 'date' => '2026-11-17', 'payment_terms_type' => 'cash', 'credit_days' => 0,
                'items' => [['product_id' => $this->productA->id, 'qty' => '2.00', 'net_unit_cost' => '250.00']]],
        ])->assertCreated()->json('task');
        $url = '/api/v1/ai/workflow-tasks/'.$task['id'];
        $first = $this->withHeaders($this->contextHeaders())->postJson($url.'/prepare-proposal')->assertCreated()->json('proposal');
        $this->withHeaders($this->contextHeaders())->postJson($url.'/prepare-proposal')->assertOk()
            ->assertJsonPath('proposal.id', $first['id'])->assertJsonPath('proposal.version', 1);
        $this->withHeaders($this->contextHeaders())->getJson($url)->assertOk()->assertJsonPath('proposal.status', 'pending');
        $this->assertSame(1, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->where('id', $first['id'])->count());
    }

    public function test_unauthorized_user_cannot_create_purchase_order_proposal(): void
    {
        $this->actingAs($this->restrictedUser, 'sanctum');

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_purchase_order_preview_computes_deterministic_pricing_with_zero_database_writes(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $purchasesCount = Purchase::count();
        $linesCount = DB::table('product_purchases')->count();
        $orderItemsCount = DB::table('commercial_order_items')->count();

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '50.00', 'net_unit_cost' => '240.00', 'discount' => '0.00', 'tax_rate' => '5.00'],
                    ['product_id' => $this->productB->id, 'qty' => '25.00', 'net_unit_cost' => '300.00', 'discount' => '0.00', 'tax_rate' => '5.00'],
                ],
                'note' => 'Initial spinning sample PO',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'pending',
            'proposal' => [
                'action_key' => 'purchase_order.create.v1',
                'version' => 1,
            ],
        ]);

        $proposal = $response->json('proposal');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $proposal['payload_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $proposal['source_state_hash']);
        $expectedSourceHash = \App\Ai\Adapters\PurchaseOrderPreviewAdapter::computeSourceStateHash(
            new \App\Services\Platform\CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id),
            $this->supplier->id,
            $this->warehouse->id,
            [$this->productA->id, $this->productB->id],
        );
        $this->assertSame($expectedSourceHash, $proposal['source_state_hash']);

        // Check preview calculation:
        // Line A: 50 * 240 = 12000, 5% tax = 600, total = 12600
        // Line B: 25 * 300 = 7500, 5% tax = 375, total = 7875
        // Grand total: 12600 + 7875 = 20475
        $preview = $proposal['preview'];
        $this->assertSame('19500.00', $preview['totals']['subtotal']);
        $this->assertSame('975.00', $preview['totals']['tax_total']);
        $this->assertSame('20475.00', $preview['totals']['grand_total']);
        $this->assertSame('75.00', $preview['totals']['total_qty']);

        // Zero writes to core ERP tables
        $this->assertSame($purchasesCount, Purchase::count());
        $this->assertSame($linesCount, DB::table('product_purchases')->count());
        $this->assertSame($orderItemsCount, DB::table('commercial_order_items')->count());
    }

    public function test_disabled_compliance_rejects_taxable_proposal_without_writes(): void
    {
        config()->set('compliance.enabled', false);
        $this->actingAs($this->adminUser, 'sanctum');
        $tables = ['ai_action_proposals', 'purchases', 'stock_movements', 'journal_entries'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->where('company_id', $this->company->id)->count();
        }

        $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
                'items' => [['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('gst')
            ->assertJsonPath('message', 'GST posting remains disabled pending compliance acceptance.');

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->where('company_id', $this->company->id)->count(), $table);
        }
    }

    public function test_change_request_updates_line_items_and_increments_version(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $createResponse = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);

        $createResponse->assertCreated();
        $proposalId = $createResponse->json('proposal.id');
        $v1Hash = $createResponse->json('proposal.payload_hash');

        // Apply change request: increase quantity to 20
        $changeResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/change-request", [
                'expected_version' => 1,
                'changes' => [
                    'items' => [
                        ['product_id' => $this->productA->id, 'qty' => '20.00', 'net_unit_cost' => '250.00'],
                    ],
                ],
            ]);

        $changeResponse->assertStatus(200);
        $changeResponse->assertJson([
            'status' => 'pending',
            'proposal' => [
                'id' => $proposalId,
                'version' => 2,
            ],
        ]);

        $v2Hash = $changeResponse->json('proposal.payload_hash');
        $this->assertNotSame($v1Hash, $v2Hash);
        $this->assertSame('5250.00', $changeResponse->json('proposal.preview.totals.grand_total'));
        $this->assertSame('5000.00', $changeResponse->json('proposal.preview.totals.subtotal'));
        $this->assertSame('250.00', $changeResponse->json('proposal.preview.totals.tax_total'));

        // Editing stale version 1 fails
        $staleResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/change-request", [
                'expected_version' => 1,
                'changes' => ['items' => []],
            ]);

        $staleResponse->assertStatus(422);
    }

    public function test_execution_creates_status_4_purchase_order_in_order_register_atomically(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $createResponse = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '40.00', 'net_unit_cost' => '250.00', 'tax_rate' => '5.00'],
                ],
                'note' => 'Authoritative AI Purchase Order',
            ],
        ]);

        $createResponse->assertCreated();
        $proposalId = $createResponse->json('proposal.id');

        // Approve and execute
        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(200);
        $approveResponse->assertJson([
            'status' => 'completed',
            'proposal_id' => $proposalId,
            'version' => 1,
        ]);

        $execution = $approveResponse->json('execution');
        $this->assertSame('completed', $execution['status']);
        $purchaseId = $execution['receipt']['resource_id'];
        $this->assertGreaterThan(0, $purchaseId);

        // Verify created Purchase in order register
        $purchase = Purchase::find($purchaseId);
        $this->assertNotNull($purchase);
        $this->assertSame(4, (int) $purchase->status); // Status 4 = Purchase Order (Ordered)
        $this->assertSame(2, (int) $purchase->payment_status); // Payment status 2 = Due
        $this->assertSame($this->company->id, (int) $purchase->company_id);
        $this->assertSame($this->supplier->id, (int) $purchase->supplier_id);
        $this->assertSame($this->warehouse->id, (int) $purchase->warehouse_id);

        // Lines in product_purchases
        $this->assertSame(1, $purchase->productPurchases()->count());
        $line = $purchase->productPurchases()->first();
        $this->assertSame($this->productA->id, (int) $line->product_id);
        $this->assertEquals(40.0, (float) $line->qty);

        // Lines in commercial_order_items (Order Lifecycle)
        $orderItem = DB::table('commercial_order_items')->where('order_id', $purchase->id)->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(40.0, (float) $orderItem->qty);

        // Zero physical stock additions & zero GL entries
        $stockMovementsCount = DB::table('stock_movements')
            ->where('company_id', $this->company->id)
            ->where('source_type', 'purchase')
            ->where('source_id', $purchase->id)
            ->count();
        $this->assertSame(0, $stockMovementsCount);

        $journalEntriesCount = DB::table('journal_entries')
            ->where('company_id', $this->company->id)
            ->where('reference_type', 'purchase')
            ->where('reference_id', $purchase->id)
            ->count();
        $this->assertSame(0, $journalEntriesCount);

        // Idempotency: re-execution returns cached receipt without duplication
        $purchasesCount = Purchase::count();
        $retryResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $retryResponse->assertStatus(200);
        $this->assertSame($purchaseId, $retryResponse->json('execution.receipt.resource_id'));
        $this->assertSame($purchasesCount, Purchase::count());

        // Verify read facade ReadOpenOrders detects this newly created purchase order
        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);
        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['orders.open.v1'],
        );

        $openOrdersReader = app(ReadOpenOrders::class);
        $openOrdersResult = $openOrdersReader->execute($principal, type: 'purchase', limit: 10);
        $this->assertSame('ok', $openOrdersResult->status);

        $orders = $openOrdersResult->data['orders'] ?? [];
        $found = collect($orders)->firstWhere('order_id', $purchaseId);
        $this->assertNotNull($found, 'Open orders read tool must list the newly created purchase order.');
        $this->assertEquals(10000.0, (float) $found['grand_total']);
    }

    public function test_execution_fails_closed_when_underlying_source_state_changes_after_approval(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $initialPurchasesCount = Purchase::count();

        // 1. Create initial proposal
        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '20.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');

        // 2. Underlying ERP state mutates after proposal creation: product cost changes
        $this->productA->update(['cost' => '320.00']);

        // 3. Execution must fail closed with 422 because live source state hash diverges
        $approveResponse = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approveResponse->assertStatus(422);
        $this->assertStringContainsString('Underlying ERP business state changed', (string) $approveResponse->json('errors.source_state.0'));

        // Zero records created
        $this->assertSame($initialPurchasesCount, Purchase::count());
    }

    public function test_proposals_endpoint_enforces_actor_specific_filtering_and_approval_permission(): void
    {
        // Grant ai.use and purchases-index to restrictedUser so they have AI read access, but NOT ai.action.approve
        $aiUsePermId = DB::table('permissions')->where('name', 'ai.use')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'ai.use', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $purchasesIndexPermId = DB::table('permissions')->where('name', 'purchases-index')->value('id')
            ?? DB::table('permissions')->insertGetId(['name' => 'purchases-index', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('role_has_permissions')->updateOrInsert(
            ['permission_id' => $aiUsePermId, 'role_id' => $this->restrictedUser->role_id]
        );
        DB::table('role_has_permissions')->updateOrInsert(
            ['permission_id' => $purchasesIndexPermId, 'role_id' => $this->restrictedUser->role_id]
        );

        $this->actingAs($this->adminUser, 'sanctum');

        $responseAdmin = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);
        $responseAdmin->assertStatus(201);
        $adminProposalId = $responseAdmin->json('proposal.id');

        // Ordinary user (restrictedUser) viewing proposals only sees their own
        $this->actingAs($this->restrictedUser, 'sanctum');
        $listResponse = $this->withHeaders($this->contextHeaders())->getJson('/api/v1/ai/proposals');
        $listResponse->assertStatus(200);
        $proposals = $listResponse->json('proposals');
        $this->assertEmpty(collect($proposals)->where('id', $adminProposalId));

        // Unauthorized user requesting all=1 is forbidden with 403
        $allForbidden = $this->withHeaders($this->contextHeaders())->getJson('/api/v1/ai/proposals?all=1');
        $allForbidden->assertStatus(403);
        $this->assertSame('UNAUTHORIZED', $allForbidden->json('error_code'));

        // Admin with ai.action.approve can inspect all proposals
        $this->actingAs($this->adminUser, 'sanctum');
        $allAllowed = $this->withHeaders($this->contextHeaders())->getJson('/api/v1/ai/proposals?all=1');
        $allAllowed->assertStatus(200);
        $this->assertNotEmpty(collect($allAllowed->json('proposals'))->where('id', $adminProposalId));
    }

    public function test_tax_rate_or_fiscal_year_modification_fails_closed_with_source_state_validation_exception(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);
        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');

        // Mutate underlying state: update fiscal year updated_at
        DB::table('fiscal_years')->where('id', $this->fiscalYear->id)->update([
            'updated_at' => now()->addMinutes(10),
        ]);

        // Attempting to approve proposal must fail closed with 422 source_state
        $approve = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approve->assertStatus(422);
        $approve->assertJsonValidationErrors(['source_state']);
    }

    public function test_order_grand_total_discrepancy_fails_closed_with_totals_validation_error(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $adapter = app(\App\Ai\Adapters\PurchaseOrderCommandAdapter::class);
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get('purchase_order.create.v1');
        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);

        $hash = \App\Ai\Adapters\PurchaseOrderPreviewAdapter::computeSourceStateHash(
            $context,
            $this->supplier->id,
            $this->warehouse->id,
            [$this->productA->id],
        );

        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'qty' => '10.00',
                    'net_unit_cost' => '250.00',
                    'tax_rate' => '5.00',
                    'purchase_unit_id' => $this->productA->unit_id,
                ],
            ],
            // Discrepancy: Expected total does not match actual domain calculation
            'expected_totals' => [
                'grand_total' => '99999.00',
            ],
        ];

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $adapter->execute(
            $definition,
            $payload,
            $context,
            $this->adminUser->id,
            'test-totals-discrepancy-' . uniqid(),
            $hash,
        );
    }

    public function test_supplier_party_tax_profile_mutation_invalidates_source_state_hash_and_fails_closed(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->withHeaders($this->contextHeaders())->postJson('/api/v1/ai/proposals', [
            'action_key' => 'purchase_order.create.v1',
            'slots' => [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'qty' => '10.00', 'net_unit_cost' => '250.00'],
                ],
            ],
        ]);
        $response->assertStatus(201);
        $proposalId = $response->json('proposal.id');

        // Mutate underlying supplier tax profile
        DB::table('party_tax_profiles')
            ->where('company_id', $this->company->id)
            ->where('party_type', 'supplier')
            ->where('party_id', $this->supplier->id)
            ->update([
                'state_code' => '29', // Inter-state change from Tamil Nadu (33) to Karnataka (29)
                'updated_at' => now(),
            ]);

        // Attempting to approve must fail closed with 422 source_state
        $approve = $this->withHeaders($this->contextHeaders())
            ->postJson("/api/v1/ai/proposals/{$proposalId}/approve", [
                'expected_version' => 1,
            ]);

        $approve->assertStatus(422);
        $approve->assertJsonValidationErrors(['source_state']);
    }

    public function test_tax_breakdown_discrepancy_fails_closed_with_totals_validation_error(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $adapter = app(\App\Ai\Adapters\PurchaseOrderCommandAdapter::class);
        $definition = app(\App\Ai\Registries\ActionCapabilityRegistry::class)->get('purchase_order.create.v1');
        $context = new CompanyContext($this->company->id, $this->branch->id, $this->fiscalYear->id);

        $hash = \App\Ai\Adapters\PurchaseOrderPreviewAdapter::computeSourceStateHash(
            $context,
            $this->supplier->id,
            $this->warehouse->id,
            [$this->productA->id],
        );

        $payload = [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'qty' => '10.00',
                    'net_unit_cost' => '250.00',
                    'tax_rate' => '5.00',
                    'purchase_unit_id' => $this->productA->unit_id,
                ],
            ],
            // Discrepancy: CGST tax breakdown does not match domain calculation
            'expected_totals' => [
                'grand_total' => '2625.00',
                'tax_total' => '125.00',
                'tax_breakdown' => [
                    'cgst' => '999.00',
                    'sgst' => '62.50',
                    'igst' => '0.00',
                    'cess' => '0.00',
                ],
            ],
        ];

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $adapter->execute(
            $definition,
            $payload,
            $context,
            $this->adminUser->id,
            'test-tax-breakdown-discrepancy-' . uniqid(),
            $hash,
        );
    }
}
