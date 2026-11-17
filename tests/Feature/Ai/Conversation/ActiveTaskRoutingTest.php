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
        $default = collect($result['confirmed_fields'])->firstWhere('field', 'credit_days');
        $this->assertSame('30', $default['display']);
        $this->assertSame('erp_default', $default['source']);
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
}
