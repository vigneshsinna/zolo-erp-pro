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

    private function draft(): array
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $task = $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'message' => 'Create a purchase order', 'client_turn_id' => (string) Str::uuid()])
            ->assertOk()->json('workflow');
        return [$thread, $task];
    }
}
