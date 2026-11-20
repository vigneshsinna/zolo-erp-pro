<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Conversation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\Company;
use App\Models\User;
use App\Ai\Services\AiFeatureGate;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

class ActiveTaskRoutingTest extends TestCase
{
    use UsesAiMysql;

    protected Company $company;
    protected User $adminUser;
    protected int $branchId;
    protected int $fyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['legal_name' => 'Conversation Fixture', 'code' => 'CW-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN']);
        $this->branchId = $this->company->branches()->create(['name' => 'Fixture Branch', 'code' => 'CW', 'is_active' => true])->id;
        $this->fyId = $this->company->fiscalYears()->create(['name' => 'FY 2026', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open'])->id;
        $this->adminUser = User::create(['name' => 'Conversation Fixture', 'email' => Str::uuid().'@example.test', 'phone' => '7000000000',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false]);
        $this->company->users()->attach($this->adminUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branchId]);
        config(['erp_ai.enabled' => true, 'erp_ai.conversation_enabled' => true, 'ai_actions.foundation_enabled' => true]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);
        Http::preventStrayRequests();
        $this->actingAs($this->adminUser)->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
        ]);
    }

    public function test_purchase_bill_chat_starts_a_typed_draft_and_gst_question_does_not_call_a_model(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $initial = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'message' => 'Create a purchase bill',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('workflow.action_key', 'purchase_bill.create.v1')
            ->assertJsonPath('interaction_type', 'workflow_continuation')->json('workflow');
        $taskId = $initial['task_id'];
        $this->assertSame('purchase_bill.create.v1', DB::table('ai_workflow_tasks')
            ->where('id', $taskId)->value('action_key'));

        $explanation = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $taskId,
            'expected_task_revision' => $initial['task_revision'],
            'client_turn_id' => (string) Str::uuid(),
            'message' => 'This is a non-GST bill',
        ])->assertOk()->json('workflow');
        $this->assertSame($taskId, $explanation['task_id']);
        $this->assertStringContainsString('ERP will determine GST', $explanation['message']);
        $this->assertSame(0, DB::table('ai_action_proposals')
            ->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_stream_reports_missing_workflow_turn_id_as_validation_not_generic_stream_failure(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'message' => 'Create a purchase order',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('workflow.status', 'needs_input');

        $content = $this->postJson(route('ai.chat.stream'), [
            'thread_id' => $thread, 'message' => 'Create a purchase bill',
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: error', $content);
        $this->assertStringContainsString('"error_code":"AI_TURN_VALIDATION_FAILED"', $content);
        $this->assertStringContainsString('"http_status":422', $content);
        $this->assertStringContainsString('"incident_id":"', $content);
        $this->assertStringNotContainsString('AI_STREAM_FAILED', $content);
        Http::assertNothingSent();
    }

    public function test_bare_po_and_retry_bind_one_collecting_task_to_owned_thread(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $input = ['thread_id' => $thread, 'message' => 'Create a purchase order', 'client_turn_id' => (string) Str::uuid()];
        $first = $this->postJson(route('ai.chat'), $input)->assertOk()
            ->assertJsonPath('interaction_type', 'workflow_continuation')
            ->assertJsonPath('workflow.status', 'needs_input');
        $taskId = $first->json('workflow.task_id');
        $this->assertNotEmpty($taskId);
        $this->assertSame($thread, DB::table('ai_workflow_tasks')->where('id', $taskId)->value('thread_id'));
        $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.task_id', $taskId);
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_unrecognized_followup_keeps_task_and_does_not_prompt_write_tools(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $task = $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'message' => 'Create a purchase order', 'client_turn_id' => (string) Str::uuid()])
            ->assertOk()->json('workflow');
        $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'message' => 'Yes', 'client_turn_id' => (string) Str::uuid(),
            'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
        ])->assertOk()->assertJsonPath('workflow.task_id', $task['task_id']);
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_cancel_requires_current_revision_and_replays_without_second_event(): void
    {
        [$thread, $task] = $this->draft();
        $input = ['thread_id' => $thread, 'message' => 'Cancel this purchase order',
            'client_turn_id' => (string) Str::uuid(), 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'] - 1];
        $this->postJson(route('ai.chat'), $input)->assertConflict();
        $input['expected_task_revision'] = $task['task_revision'];
        $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.status', 'cancelled');
        $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.status', 'cancelled');
        $this->assertSame($task['task_revision'] + 1, DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('revision'));
    }

    public function test_wrong_thread_expiry_and_reused_turn_fail_closed(): void
    {
        [$thread, $task] = $this->draft();
        $other = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $input = ['thread_id' => $other, 'message' => 'Continue', 'active_task_id' => $task['task_id'], 'client_turn_id' => (string) Str::uuid()];
        $this->postJson(route('ai.chat'), $input)->assertForbidden();
        DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->update(['expires_at' => now()->subMinute()]);
        $input['thread_id'] = $thread;
        $this->postJson(route('ai.chat'), $input)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_switch_keeps_po_and_multiple_drafts_require_selection(): void
    {
        [$thread, $task] = $this->draft();
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'message' => 'Create a sales order instead', 'client_turn_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('workflow.task_id', $task['task_id']);
        $copy = (array) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->first();
        $copy['id'] = (string) Str::uuid();
        DB::table('ai_workflow_tasks')->insert($copy);
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'message' => 'Continue', 'client_turn_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonCount(2, 'workflow.tasks');
        Http::assertNothingSent();
    }

    public function test_multi_slot_reply_and_replay_patch_same_task_and_preserve_lines(): void
    {
        [$thread, $task] = $this->draft();
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'ABC Traders; add 100 pairs of Cotton Shirt Blue at 480'];
        $response = $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.task_id', $task['task_id']);
        $revision = $response->json('workflow.task_revision');
        $this->assertSame($task['task_revision'] + 1, $revision);
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame('ABC Traders', $slots['supplier_query']);
        $this->assertSame('100.00', $slots['items'][0]['qty']);
        $this->assertSame('480.00', $slots['items'][0]['net_unit_cost']);
        $this->assertSame('pairs', $slots['items'][0]['uom']);
        $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.task_revision', $revision);
        $input['message'] = 'make it 120 instead';
        $this->postJson(route('ai.chat'), $input)->assertConflict();
        $input['client_turn_id'] = (string) Str::uuid();
        $this->postJson(route('ai.chat'), $input)->assertConflict();
        $input['expected_task_revision'] = $revision;
        $this->postJson(route('ai.chat'), $input)->assertOk();
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame('120.00', $slots['items'][0]['qty']);
        $this->assertSame('480.00', $slots['items'][0]['net_unit_cost']);
        $this->assertSame('ABC Traders', $slots['supplier_query']);
        Http::assertNothingSent();
    }

    public function test_natural_language_supplier_and_product_directory_keeps_the_draft_unchanged(): void
    {
        [$thread, $task] = $this->draft();
        \App\Models\Supplier::create([
            'company_id' => $this->company->id, 'name' => 'ABC Test Traders',
            'phone_number' => '7000000011', 'is_active' => true,
        ]);
        $unit = \App\Models\Unit::forceCreate([
            'company_id' => $this->company->id, 'unit_code' => 'PR'.strtoupper(Str::random(7)),
            'unit_name' => 'Pairs', 'is_active' => true,
        ]);
        $category = \App\Models\Category::forceCreate([
            'company_id' => $this->company->id, 'name' => 'Conversation Garments',
            'is_active' => true,
        ]);
        \App\Models\Product::forceCreate([
            'company_id' => $this->company->id, 'name' => 'Cotton Shirt Blue',
            'code' => 'CSB-'.strtoupper(Str::random(6)),
            'type' => 'standard', 'barcode_symbology' => 'code128',
            'category_id' => $category->id, 'cost' => 500, 'price' => 590,
            'unit_id' => $unit->id, 'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id, 'is_active' => true,
        ]);

        $response = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(),
            'message' => 'can you tell me what are the suppliers and products available?',
        ])->assertOk()
            ->assertJsonPath('interaction_type', 'workflow_continuation')
            ->assertJsonPath('workflow.task_id', $task['task_id'])
            ->assertJsonPath('workflow.task_revision', $task['task_revision'])
            ->assertJsonPath('workflow.directories.0.entity_type', 'supplier')
            ->assertJsonPath('workflow.directories.1.entity_type', 'product');

        $supplierNames = array_column($response->json('workflow.directories.0.items'), 'name');
        $productNames = array_column($response->json('workflow.directories.1.items'), 'name');
        $this->assertContains('ABC Test Traders', $supplierNames);
        $this->assertContains('Cotton Shirt Blue', $productNames);
        $this->assertSame($task['task_revision'], (int) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('revision'));
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_product_directory_can_be_browsed_without_a_draft_item_or_side_effect(): void
    {
        [$thread, $task] = $this->draft();
        $response = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(),
            'message' => 'show products',
        ])->assertOk()
            ->assertJsonPath('workflow.task_revision', $task['task_revision'])
            ->assertJsonPath('workflow.directories.0.entity_type', 'product');

        $this->assertIsArray($response->json('workflow.directories.0.items'));
        $this->assertSame([], $response->json('workflow.choices') ?? []);
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertEmpty($slots['items'] ?? []);
        Http::assertNothingSent();
    }

    public function test_initial_po_shows_versioned_supplier_and_product_choices_without_adding_items(): void
    {
        $product = $this->createCatalogueFixtureProduct();
        $supplier = \App\Models\Supplier::create([
            'company_id' => $this->company->id, 'name' => 'Selectable Supplier',
            'phone_number' => '7000000011', 'is_active' => true,
        ]);
        [$thread, $task] = $this->draft();
        $choices = collect($task['choices'] ?? []);
        $productChoices = $choices->where('entity_type', 'product')->values();
        $supplierChoices = $choices->where('entity_type', 'supplier')->values();
        $this->assertNotEmpty($task['choice_set_id']);
        $this->assertCount(1, $productChoices);
        $this->assertCount(1, $supplierChoices);
        $this->assertContains('product', array_column($task['directories'], 'entity_type'));
        $this->assertContains('supplier', array_column($task['directories'], 'entity_type'));
        $this->assertSame([], json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true)['items'] ?? []);

        $selected = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'Select option', 'choice_set_id' => $task['choice_set_id'],
            'choice_id' => $productChoices[0]['choice_id'],
        ])->assertOk()->json('workflow');
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertCount(1, $slots['items']);
        $this->assertSame($product->id, $slots['items'][0]['product_id']);
        $this->assertArrayNotHasKey('qty', $slots['items'][0]);
        $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());

        $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $selected['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => '100 pairs at 500',
        ])->assertOk()->assertJsonPath('workflow.task_id', $task['task_id']);
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame('100.00', $slots['items'][0]['qty']);
        $this->assertSame('500.00', $slots['items'][0]['net_unit_cost']);
        $this->assertSame($product->id, $slots['items'][0]['product_id']);
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_spoken_product_name_and_quantity_select_one_visible_catalogue_record(): void
    {
        $product = $this->createCatalogueFixtureProduct();
        [$thread, $task] = $this->draft();
        $listing = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'can you tell me what products are available?',
        ])->assertOk()->assertJsonCount(1, 'workflow.choices')->json('workflow');

        $selected = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $listing['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'Select Cotton Shirt Blue; 100 pairs at 480',
            'choice_set_id' => $listing['choice_set_id'],
        ])->assertOk()->json('workflow');
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertCount(1, $slots['items']);
        $this->assertSame($product->id, $slots['items'][0]['product_id']);
        $this->assertSame('100.00', $slots['items'][0]['qty']);
        $this->assertSame('480.00', $slots['items'][0]['net_unit_cost']);

        $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $selected['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'Select option', 'choice_set_id' => $listing['choice_set_id'],
            'choice_id' => $listing['choices'][0]['choice_id'],
        ])->assertConflict();
        $this->assertSame(1, count(json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true)['items']));
        Http::assertNothingSent();
    }

    private function createCatalogueFixtureProduct(): \App\Models\Product
    {
        $unit = \App\Models\Unit::forceCreate([
            'company_id' => $this->company->id, 'unit_code' => 'PR'.strtoupper(Str::random(7)),
            'unit_name' => 'Pairs', 'is_active' => true,
        ]);
        $category = \App\Models\Category::forceCreate([
            'company_id' => $this->company->id, 'name' => 'Conversational Catalogue',
            'is_active' => true,
        ]);
        return \App\Models\Product::forceCreate([
            'company_id' => $this->company->id, 'name' => 'Cotton Shirt Blue',
            'code' => 'CSB-'.strtoupper(Str::random(6)),
            'type' => 'standard', 'barcode_symbology' => 'code128',
            'category_id' => $category->id, 'cost' => 500, 'price' => 590,
            'unit_id' => $unit->id, 'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id, 'is_active' => true,
        ]);
    }

    public function test_directory_intent_distinguishes_listing_from_other_read_only_questions(): void
    {
        $intent = app(\App\Ai\Conversation\ConversationDirectoryIntent::class);
        $this->assertSame(['supplier', 'product'], $intent->types('Can you tell me what suppliers and products are available?'));
        $this->assertSame(['warehouse'], $intent->types('Which warehouses can I use?'));
        $this->assertSame([], $intent->types('Can you tell me the current stock of Cotton Shirt Blue?'));
        $this->assertTrue($intent->isReadOnlyQuestion('Can you tell me the current stock of Cotton Shirt Blue?'));
        $this->assertFalse($intent->isReadOnlyQuestion('Create a new supplier ABC Traders'));
    }

    public function test_model_parser_accepts_a_read_only_no_change_result(): void
    {
        [$thread, $task] = $this->draft();
        $row = (array) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->first();
        $parsed = app(\App\Ai\Conversation\ConversationalSlotInterpreter::class)->validateModelResult(
            $row, ['turn_intent' => 'unknown', 'corrections' => [], 'warnings' => []],
        );
        $this->assertSame('unknown', $parsed['turn_intent']);
        $this->assertSame([], $parsed['corrections']);
        $this->assertSame($task['task_revision'], (int) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('revision'));
        Http::assertNothingSent();
    }

    protected function draft(): array
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $task = $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'message' => 'Create a purchase order', 'client_turn_id' => (string) Str::uuid()])
            ->assertOk()->json('workflow');
        return [$thread, $task];
    }

    public function test_parse_only_model_cannot_supply_erp_ids(): void
    {
        [$thread, $task] = $this->draft();
        DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->update(['slots_json' => json_encode(['supplier_id' => 99, 'items' => [['client_line_id' => 'alpha', 'qty' => '5']]])]);
        config(['erp_ai.provider' => 'openai', 'erp_ai.model' => 'gpt-4o-mini', 'ai.providers.openai.key' => 'fixture-key']);
        \App\Ai\Conversation\ConversationParseAgent::fake([[
            'turn_intent' => 'continue_task', 'corrections' => [['operation' => 'set', 'field' => 'supplier_id', 'value' => '99']], 'warnings' => [],
        ]]);
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'Please replace the vendor with whichever you like'])
            ->assertUnprocessable();
        $this->assertSame($task['task_revision'], DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('revision'));
    }

    public function test_confirmed_voice_reply_updates_same_task_and_reuses_extraction(): void
    {
        [$thread, $task] = $this->draft();
        [$media, $extraction] = $this->voice($thread, $task['task_id'], 'ABC Traders; add 100 pairs of Cotton Shirt Blue at 480');
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => $extraction->extracted_json['transcript_text'],
            'media_ids' => [$media->id], 'voice_extraction_revision' => 1];
        $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.task_id', $task['task_id']);
        $this->postJson(route('ai.chat'), $input)->assertOk();
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        $this->assertSame(1, DB::table('ai_media_extractions')->where('media_id', $media->id)->count());
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame('100.00', $slots['items'][0]['qty']);
        Http::assertNothingSent();
    }

    protected function voice(string $thread, string $taskId, string $transcript): array
    {
        config(['ai_multimodal.enabled' => true]);
        app(AiFeatureGate::class)->setCompanyMultimodal($this->company->id, true);
        $media = \App\Models\AiMediaAsset::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'actor_id' => $this->adminUser->id, 'thread_id' => $thread, 'workflow_task_id' => $taskId,
            'source_kind' => 'audio', 'original_mime' => 'audio/wav', 'canonical_mime' => 'audio/wav',
            'size_bytes' => 100, 'sha256' => hash('sha256', (string) Str::uuid()), 'private_disk' => 'local', 'private_key' => 'fixture.wav',
            'state' => 'extracted', 'expires_at' => now()->addHour(),
        ]);
        $extraction = \App\Models\AiMediaExtraction::create([
            'company_id' => $this->company->id, 'media_id' => $media->id, 'revision' => 1, 'model_provider' => 'fixture', 'model_key' => 'fixture',
            'prompt_version' => 'fixture', 'extraction_schema_version' => 'media.transcript.v1',
            'extracted_json' => ['transcript_text' => $transcript, 'warnings' => [], 'items' => [], 'intent_guess' => 'unknown'],
            'confirmation_status' => 'confirmed', 'confirmed_by' => $this->adminUser->id, 'confirmed_at' => now(),
        ]);
        return [$media, $extraction];
    }

    public function test_voice_requires_current_confirmed_transcript_and_exact_workspace(): void
    {
        [$thread, $task] = $this->draft();
        [$media, $extraction] = $this->voice($thread, $task['task_id'], 'quantity 50');
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'quantity 50', 'media_ids' => [$media->id], 'voice_extraction_revision' => 1];
        $extraction->update(['confirmation_status' => 'prepared', 'extracted_json' => ['transcript_text' => 'quantity 50', 'warnings' => ['15/50 ambiguity']]]);
        $this->postJson(route('ai.chat'), $input)->assertUnprocessable();
        $extraction->update(['confirmation_status' => 'confirmed']);
        $input['voice_extraction_revision'] = 2;
        $this->postJson(route('ai.chat'), $input)->assertConflict();
        $input['voice_extraction_revision'] = 1;
        $media->update(['branch_id' => $this->branchId + 100]);
        $this->postJson(route('ai.chat'), $input)->assertForbidden();
        $this->assertSame($task['task_revision'], DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('revision'));
        Http::assertNothingSent();
    }

    public function test_preparing_existing_voice_for_draft_never_hands_off_new_task(): void
    {
        [$thread, $task] = $this->draft();
        [$media] = $this->voice($thread, $task['task_id'], 'Credit 30 days');
        $this->postJson(route('ai.media.prepare-existing', $media->id), ['thread_id' => $thread,
            'workflow_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision']])
            ->assertOk()->assertJsonPath('status', 'voice_reply_ready')->assertJsonPath('task_id', $task['task_id']);
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        $this->assertSame(1, DB::table('ai_media_extractions')->where('media_id', $media->id)->count());
        Http::assertNothingSent();
    }

    public function test_spoken_terms_override_supplier_default_without_losing_other_slots(): void
    {
        [$thread, $task] = $this->draft();
        $supplier = \App\Models\Supplier::create(['company_id' => $this->company->id, 'name' => 'Terms Supplier', 'phone_number' => '7000000011',
            'is_active' => true, 'credit_days' => 30, 'purchase_defaults' => ['payment_mode' => 'Credit']]);
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'Terms Supplier'];
        $result = $this->postJson(route('ai.chat'), $input)->assertOk()->json('workflow');
        $this->assertNull(collect($result['confirmed_fields'])->firstWhere('field', 'credit_days'),
            'Supplier defaults are suggestions, not confirmed purchase terms.');
        $this->assertContains('payment_terms_type', array_column($result['required_inputs'], 'field'));
        $this->assertArrayNotHasKey('credit_days',
            json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true));
        $input['expected_task_revision'] = $result['task_revision'];
        $input['client_turn_id'] = (string) Str::uuid();
        $input['message'] = 'Credit 45 days';
        $result = $this->postJson(route('ai.chat'), $input)->assertOk()->json('workflow');
        $this->assertSame('45', collect($result['confirmed_fields'])->firstWhere('field', 'credit_days')['display']);
        $input['expected_task_revision'] = $result['task_revision'];
        $input['client_turn_id'] = (string) Str::uuid();
        $input['message'] = 'Cash';
        $this->postJson(route('ai.chat'), $input)->assertOk();
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame('cash', $slots['payment_terms_type']);
        $this->assertSame(0, $slots['credit_days']);
        $this->assertSame('Terms Supplier', $slots['supplier_query']);
        Http::assertNothingSent();
    }

    public function test_ordinal_supplier_selection_uses_versioned_visible_choices(): void
    {
        [$thread, $task] = $this->draft();
        foreach (['ABC Chennai', 'ABC Erode'] as $name) {
            \App\Models\Supplier::create(['company_id' => $this->company->id, 'name' => $name, 'phone_number' => '7000000011', 'is_active' => true]);
        }
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'ABC'];
        $result = $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonCount(2, 'workflow.choices')->json('workflow');
        $this->assertNotEmpty($result['choice_set_id']);
        $expected = \App\Models\Supplier::where('company_id', $this->company->id)->where('name', $result['choices'][1]['label'])->value('id');
        $input['expected_task_revision'] = $result['task_revision'];
        $input['choice_set_id'] = $result['choice_set_id'];
        $input['client_turn_id'] = (string) Str::uuid();
        $input['message'] = 'Use the second supplier';
        $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.task_id', $task['task_id']);
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame((int) $expected, $slots['supplier_id']);
        $input['client_turn_id'] = (string) Str::uuid();
        $this->postJson(route('ai.chat'), $input)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_directory_refresh_and_inactive_choice_cannot_select_stale_option(): void
    {
        [$thread, $task] = $this->draft();
        $supplier = \App\Models\Supplier::create(['company_id' => $this->company->id, 'name' => 'Directory Supplier', 'phone_number' => '7000000011', 'is_active' => true]);
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'show suppliers'];
        $old = $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonCount(1, 'workflow.choices')->json('workflow');
        $input['client_turn_id'] = (string) Str::uuid();
        $current = $this->postJson(route('ai.chat'), $input)->assertOk()->json('workflow');
        $input['message'] = 'Select option';
        $input['client_turn_id'] = (string) Str::uuid();
        $input['choice_set_id'] = $old['choice_set_id'];
        $input['choice_id'] = $old['choices'][0]['choice_id'];
        $this->postJson(route('ai.chat'), $input)->assertConflict();
        $input['choice_set_id'] = $current['choice_set_id'];
        $input['choice_id'] = $current['choices'][0]['choice_id'];
        $supplier->update(['is_active' => false]);
        $this->postJson(route('ai.chat'), $input)->assertConflict();
        $this->assertSame($task['task_revision'], DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('revision'));
        Http::assertNothingSent();
    }

    public function test_conversational_warehouse_master_approval_resumes_parent_and_retry_is_safe(): void
    {
        [$thread, $task] = $this->draft();
        config(['ai_actions.execution_enabled' => true]);
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'create new warehouse Erode'];
        $master = $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.status', 'master_needs_input')->json('workflow');
        $this->assertSame('warehouse', $master['master_schema']['entity_type']);
        $input['message'] = 'address Erode industrial estate';
        $input['client_turn_id'] = (string) Str::uuid();
        $input['expected_task_revision'] = $master['task_revision'];
        $ready = $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.status', 'master_proposal_created')->json('workflow');
        $this->assertSame(0, \App\Models\Warehouse::where('company_id', $this->company->id)->where('name', 'Erode')->count());
        $approval = ['field' => 'warehouse_id', 'master_proposal_id' => $ready['master_proposal']['id'], 'expected_version' => 1];
        $url = '/ai/workflow-tasks/'.$task['task_id'].'/dependent-masters/approve-and-execute';
        $result = $this->postJson($url, $approval)->assertOk()->assertJsonPath('parent_task_id', $task['task_id'])->json();
        $this->assertGreaterThan(0, $result['newly_created']['resource_id']);
        $this->postJson($url, $approval)->assertOk();
        $this->assertSame(1, \App\Models\Warehouse::where('company_id', $this->company->id)->where('name', 'Erode')->count());
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_conversation_product_requires_explicit_unit_and_tax_classification(): void
    {
        [$thread, $task] = $this->draft();
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'create new product Cotton Shirt Blue'];
        $result = $this->postJson(route('ai.chat'), $input)->assertOk()->assertJsonPath('workflow.status', 'master_needs_input')->json('workflow');
        foreach (['code', 'unit_id', 'tax_category_id', 'hsn_code'] as $field) {
            $this->assertArrayHasKey($field, $result['master_required_inputs']);
            $this->assertArrayNotHasKey($field, $result['master_schema']['prefilled']);
        }
        $this->assertSame(0, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, \App\Models\Product::where('company_id', $this->company->id)->count());
    }

    public function test_created_master_survives_parent_failure_and_retry_recovers_receipt(): void
    {
        [$thread, $task] = $this->draft();
        config(['ai_actions.execution_enabled' => true]);
        $input = ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
            'client_turn_id' => (string) Str::uuid(), 'message' => 'new warehouse Recovery'];
        $result = $this->postJson(route('ai.chat'), $input)->assertOk()->json('workflow');
        $input['expected_task_revision'] = $result['task_revision'];
        $input['client_turn_id'] = (string) Str::uuid();
        $input['message'] = 'address Recovery estate';
        $ready = $this->postJson(route('ai.chat'), $input)->assertOk()->json('workflow');
        DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->update(['action_key' => 'unsupported.parent.v1']);
        $approval = ['field' => 'warehouse_id', 'master_proposal_id' => $ready['master_proposal']['id'], 'expected_version' => 1];
        $url = '/ai/workflow-tasks/'.$task['task_id'].'/dependent-masters/approve-and-execute';
        $result = $this->postJson($url, $approval)->assertOk()->assertJsonPath('status', 'master_created_parent_pending')->json();
        $this->assertSame(1, \App\Models\Warehouse::where('company_id', $this->company->id)->where('name', 'Recovery')->count());
        DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->update(['action_key' => 'purchase_order.create.v1']);
        $this->postJson($url, $approval)->assertOk()->assertJsonPath('newly_created.resource_id', $result['newly_created']['resource_id']);
        $this->assertSame(1, \App\Models\Warehouse::where('company_id', $this->company->id)->where('name', 'Recovery')->count());
    }

    public function test_resume_does_not_treat_command_as_supplier_name(): void
    {
        [$thread, $task] = $this->draft();
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(), 'message' => 'resume'])->assertOk();
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertArrayNotHasKey('supplier_query', $slots);
    }

    public function test_stream_emits_one_workflow_state_without_duplicate_task(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $response = $this->postJson(route('ai.chat.stream'), ['thread_id' => $thread, 'message' => 'Create purchase order',
            'client_turn_id' => (string) Str::uuid()])->assertOk();
        $body = $response->streamedContent();
        $this->assertSame(1, substr_count($body, 'event: workflow_state'));
        $this->assertSame(1, substr_count($body, 'event: complete'));
        $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_read_question_preserves_draft_and_exposes_only_manifest_read_tools(): void
    {
        [$thread, $task] = $this->draft();
        $principal = new \App\Ai\DTOs\AiPrincipal($this->adminUser->id, $this->company->id, $this->branchId, $this->fyId,
            ['purchase_order.create.v1', 'customer.outstanding.v1'], (string) Str::uuid(), threadId: $thread);
        $before = (array) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->first();
        $result = app(\App\Ai\Conversation\ConversationTurnRouter::class)->route($principal, $thread,
            ['message' => 'What is customer outstanding?', 'client_turn_id' => (string) Str::uuid()]);
        $this->assertNull($result);
        $agent = new \App\Ai\Agents\ErpCopilot($principal, readToolsOnly: true);
        $this->assertEquals(app(\App\Ai\Services\AiToolManifest::class)->sdkTools($principal), $agent->tools());
        $this->assertSame($before, (array) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->first());
        Http::assertNothingSent();
    }

    public function test_current_task_denies_wrong_actor_company_branch_and_fiscal_year(): void
    {
        [$thread, $task] = $this->draft();
        $original = (array) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->first();
        foreach (['actor_id', 'company_id', 'branch_id', 'financial_year_id'] as $field) {
            DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->update([$field => $original[$field] + 100]);
            $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'active_task_id' => $task['task_id'], 'expected_task_revision' => $task['task_revision'],
                'client_turn_id' => (string) Str::uuid(), 'message' => 'Cash'])->assertForbidden();
            DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->update([$field => $original[$field]]);
        }
        $this->assertSame($original, (array) DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->first());
        Http::assertNothingSent();
    }

    public function test_model_query_cannot_smuggle_numeric_erp_id(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Ai\Conversation\ConversationalSlotInterpreter::class)->validateModelResult(['slots_json' => '{}'], [
            'turn_intent' => 'continue_task', 'warnings' => [], 'corrections' => [['operation' => 'set', 'field' => 'supplier_query', 'value' => '99']],
        ]);
    }

    public function test_add_two_lines_and_remove_second_preserves_other_stable_ids_and_rates(): void
    {
        [$thread, $task] = $this->draft();
        $send = function (string $message) use ($thread, &$task): array {
            $task = $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'active_task_id' => $task['task_id'],
                'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(), 'message' => $message])->assertOk()->json('workflow');
            return json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true)['items'];
        };
        $first = $send('add 1 kg of Alpha yarn at 250');
        $three = $send('add 2 kg of Beta yarn at 300; add 3 kg of Gamma yarn at 350');
        $this->assertCount(3, $three);
        $this->assertSame($first[0], $three[0]);
        $this->assertCount(3, array_unique(array_column($three, 'client_line_id')));
        $two = $send('remove the second item');
        $this->assertSame([$three[0], $three[2]], $two);
        Http::assertNothingSent();
    }

    public function test_model_product_change_then_add_does_not_restore_old_product_defaults(): void
    {
        $parsed = app(\App\Ai\Conversation\ConversationalSlotInterpreter::class)->validateModelResult([
            'slots_json' => json_encode(['items' => [['client_line_id' => 'keep', 'product_id' => 8, 'qty' => '3.00', 'net_unit_cost' => '9.00', 'purchase_unit_id' => 1]]]),
        ], ['turn_intent' => 'continue_task', 'warnings' => [], 'corrections' => [
            ['operation' => 'change_line', 'line_id' => 'keep', 'field' => 'product_query', 'value' => 'Fresh product'],
            ['operation' => 'add_line', 'field' => 'items', 'value' => ['product_query' => 'Extra product', 'qty' => '2', 'uom' => 'pairs']],
        ]]);
        $line = $parsed['corrections'][1]['value'][0];
        $this->assertArrayNotHasKey('product_id', $line);
        $this->assertArrayNotHasKey('net_unit_cost', $line);
        $this->assertArrayNotHasKey('purchase_unit_id', $line);
        $this->assertSame('3.00', $line['qty']);
    }

    public function test_one_branch_warehouse_never_auto_selects_and_unconfirmed_master_rate_is_missing(): void
    {
        $product = $this->createCatalogueFixtureProduct();
        $warehouse = \App\Models\Warehouse::forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId,
            'name' => 'Only Warehouse', 'address' => 'Fixture branch', 'is_active' => true,
        ]);
        [$thread, $task] = $this->draft();
        $this->assertContains('warehouse_id', array_column($task['required_inputs'], 'field'),
            'A single warehouse must still require confirmation.');
        $this->assertNull(collect($task['confirmed_fields'])->firstWhere('field', 'warehouse_id'));
        $this->assertArrayNotHasKey('warehouse_id',
            json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true));
        $this->assertContains('warehouse', array_column($task['directories'], 'entity_type'));

        $choice = collect($task['choices'])->firstWhere('entity_type', 'product');
        $this->assertNotNull($choice);
        $selected = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'Select option', 'choice_set_id' => $task['choice_set_id'],
            'choice_id' => $choice['choice_id'],
        ])->assertOk()->json('workflow');
        $qty = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $selected['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => '10 pairs',
        ])->assertOk()->json('workflow');
        $fields = array_column($qty['required_inputs'], 'field');
        $this->assertContains('warehouse_id', $fields);
        $this->assertContains('payment_terms_type', $fields);
        $this->assertTrue(collect($fields)->contains(fn ($field) => str_ends_with($field, '.net_unit_cost')));
        $this->assertStringContainsString('Rate', $qty['message']);
        $this->assertStringNotContainsString('Remaining details:', $qty['message']);
        $this->assertContains('warehouse_id', array_column($qty['required_inputs'], 'field'));
        $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertArrayNotHasKey('net_unit_cost', $slots['items'][0]);
        $this->assertArrayNotHasKey('warehouse_id', $slots);
        $this->assertSame($product->id, $slots['items'][0]['product_id']);
        Http::assertNothingSent();
    }

    public function test_speaking_change_that_unit_cost_to_600_sets_exact_po_rate_and_preserves_quantity(): void
    {
        $product = $this->createCatalogueFixtureProduct();
        [$thread, $task] = $this->draft();
        $choice = collect($task['choices'])->firstWhere('entity_type', 'product');
        $selected = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'Select option', 'choice_set_id' => $task['choice_set_id'], 'choice_id' => $choice['choice_id'],
        ])->assertOk()->json('workflow');
        $initial = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $selected['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => '10 pairs at 500',
        ])->assertOk()->json('workflow');
        $fixed = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $initial['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'change that unit cost to 600',
        ])->assertOk()->json('workflow');
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame($product->id, $slots['items'][0]['product_id']);
        $this->assertSame('10.00', $slots['items'][0]['qty']);
        $this->assertSame('600.00', $slots['items'][0]['net_unit_cost']);
        $rateField = collect($fixed['confirmed_fields'])->firstWhere('field',
            'items['.$slots['items'][0]['client_line_id'].'].net_unit_cost');
        $this->assertSame('Line 1 Rate', $rateField['label']);
        $this->assertSame('user', $rateField['source']);
        $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_complete_po_workspace_can_patch_several_fields_together_and_still_requires_approval(): void
    {
        $product = $this->createCatalogueFixtureProduct();
        $warehouse = \App\Models\Warehouse::forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId,
            'name' => 'Warehouse for selection', 'address' => 'Fixture branch', 'is_active' => true,
        ]);
        [$thread, $task] = $this->draft();
        $productChoice = collect($task['choices'])->firstWhere('entity_type', 'product');
        $selected = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'Select option',
            'choice_set_id' => $task['choice_set_id'], 'choice_id' => $productChoice['choice_id'],
        ])->assertOk()->json('workflow');

        $lineId = collect(json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])
            ->value('slots_json'), true)['items'])->first()['client_line_id'];
        $patch = $this->patchJson('/ai/workflow-tasks/'.$task['task_id'].'/inputs', [
            'expected_revision' => $selected['task_revision'],
            'corrections' => [
                ['field' => 'items['.$lineId.'].qty', 'value' => '10', 'confirmation' => 'user_edited'],
                ['field' => 'items['.$lineId.'].net_unit_cost', 'value' => '600', 'confirmation' => 'user_edited'],
                ['field' => 'warehouse_id', 'value' => $warehouse->id, 'confirmation' => 'user_edited'],
                ['field' => 'date', 'value' => '2026-11-18', 'confirmation' => 'user_edited'],
                ['field' => 'payment_terms_type', 'value' => 'credit', 'confirmation' => 'user_edited'],
                ['field' => 'credit_days', 'value' => 30, 'confirmation' => 'user_edited'],
            ],
        ])->assertOk()->json();
        $this->assertSame($selected['task_revision'] + 1, $patch['task']['revision']);
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])
            ->value('slots_json'), true);
        $this->assertSame($product->id, $slots['items'][0]['product_id']);
        $this->assertSame('10.00', $slots['items'][0]['qty']);
        $this->assertSame('600.00', $slots['items'][0]['net_unit_cost']);
        $this->assertSame($warehouse->id, $slots['warehouse_id']);
        $this->assertSame('credit', $slots['payment_terms_type']);
        $this->assertSame(30, $slots['credit_days']);
        $this->assertSame('2026-11-18', $slots['date']);
        $this->assertContains('supplier_id', array_column($patch['evaluation']['required_inputs'], 'field'));
        $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
        Http::assertNothingSent();
    }

    public function test_rate_correction_requires_exactly_one_line_and_never_invents_amounts(): void
    {
        $interpreter = app(\App\Ai\Conversation\ConversationalSlotInterpreter::class);
        $one = ['slots_json' => json_encode(['items' => [['client_line_id' => 'line-a', 'qty' => '10']]])];
        $this->assertSame([['field' => 'items[line-a].net_unit_cost', 'value' => '600', 'confirmation' => 'user_text']],
            $interpreter->interpret($one, 'change the rate to ₹600')['corrections']);
        $two = ['slots_json' => json_encode(['items' => [
            ['client_line_id' => 'line-a', 'qty' => '10'], ['client_line_id' => 'line-b', 'qty' => '1'],
        ]])];
        $ambiguous = $interpreter->interpret($two, 'change that unit cost to 600');
        $this->assertSame([], $ambiguous['corrections']);
        $this->assertNotEmpty($ambiguous['warnings']);
    }

    public function test_conversation_preserves_domain_fractional_quantity_and_unit_cost(): void
    {
        [$thread, $task] = $this->draft();
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'active_task_id' => $task['task_id'],
            'expected_task_revision' => $task['task_revision'], 'client_turn_id' => (string) Str::uuid(),
            'message' => 'add 0.125 kg of Fractional yarn at 250.125'])->assertOk();
        $slots = json_decode(DB::table('ai_workflow_tasks')->where('id', $task['task_id'])->value('slots_json'), true);
        $this->assertSame('0.125', $slots['items'][0]['qty']);
        $this->assertSame('250.125', $slots['items'][0]['net_unit_cost']);
    }
}
