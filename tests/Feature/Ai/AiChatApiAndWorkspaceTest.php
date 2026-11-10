<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\ErpCopilot;
use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use Tests\TestCase;

class AiChatApiAndWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    protected Company $company;
    protected User $adminUser;
    protected User $unpermittedUser;
    protected int $branchId;
    protected int $fyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::where('status', 'active')->first() ?? Company::create([
            'legal_name' => 'Chat Test Company',
            'trade_name' => 'Chat Company',
            'code' => 'CHAT-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $branch = $this->company->branches()->first() ?? $this->company->branches()->create([
            'name' => 'Chat Branch',
            'code' => 'BR-CHAT-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branchId = $branch->id;

        $fy = FiscalYear::where('company_id', $this->company->id)->first() ?? FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $this->fyId = $fy->id;

        $this->adminUser = User::create([
            'name' => 'Chat Admin',
            'email' => 'chat_admin_' . uniqid() . '@example.com',
            'phone' => '7777711111',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->unpermittedUser = User::create([
            'name' => 'Chat Viewer',
            'email' => 'chat_viewer_' . uniqid() . '@example.com',
            'phone' => '7777722222',
            'password' => bcrypt('secret'),
            'role_id' => 99,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company->users()->attach($this->adminUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->adminUser->id,
            'branch_id' => $this->branchId,
        ]);

        $this->company->users()->attach($this->unpermittedUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->unpermittedUser->id,
            'branch_id' => $this->branchId,
        ]);

        // Enable global AI and company settings
        config(['erp_ai.enabled' => true]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);
    }

    public function test_workspace_view_renders_for_authenticated_actor(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->get(route('ai.workspace'));

        $response->assertStatus(200);
        $response->assertSee('ai-workspace-container');
        $response->assertSee('zoloERP Copilot');
        $response->assertSee($this->company->legal_name ?? $this->company->name);
    }

    public function test_settings_endpoint_returns_effective_company_configuration(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->getJson(route('api.v1.ai.settings'));

        $response->assertStatus(200);
        $response->assertJson([
            'enabled' => true,
            'read_only' => true,
            'provider' => config('erp_ai.provider', 'openai'),
        ]);
        $this->assertContains('receivables.outstanding.v1', $response->json('allowed_tools'));
    }

    public function test_thread_lifecycle_create_list_and_ownership(): void
    {
        // 1. Create thread
        $createRes = $this->actingAs($this->adminUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->postJson(route('api.v1.ai.threads.store'), [
                'title' => 'Receivables Investigation Thread',
            ]);

        $createRes->assertStatus(201);
        $threadId = $createRes->json('id');
        $this->assertNotEmpty($threadId);

        // 2. List threads
        $listRes = $this->actingAs($this->adminUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->getJson(route('api.v1.ai.threads.index'));

        $listRes->assertStatus(200);
        $this->assertCount(1, $listRes->json('threads'));
        $this->assertSame($threadId, $listRes->json('threads.0.id'));

        // 3. Show thread
        $showRes = $this->actingAs($this->adminUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->getJson(route('api.v1.ai.threads.show', ['id' => $threadId]));

        $showRes->assertStatus(200);
        $this->assertSame($threadId, $showRes->json('thread.id'));

        // 4. Other user cannot access this thread
        $otherRes = $this->actingAs($this->unpermittedUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->getJson(route('api.v1.ai.threads.show', ['id' => $threadId]));

        $otherRes->assertStatus(404);
        $this->assertSame('AI_THREAD_NOT_FOUND', $otherRes->json('error_code'));
    }

    public function test_chat_endpoint_denies_unpermitted_user(): void
    {
        $response = $this->actingAs($this->unpermittedUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->postJson(route('api.v1.ai.chat'), [
                'message' => 'Show me the customer balance.',
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error_code' => 'AI_TOOL_FORBIDDEN',
        ]);
    }

    public function test_chat_endpoint_denies_when_company_ai_disabled(): void
    {
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, false, true);

        $response = $this->actingAs($this->adminUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->postJson(route('api.v1.ai.chat'), [
                'message' => 'Show me the customer balance.',
            ]);

        $response->assertStatus(503);
        $response->assertJson([
            'error_code' => 'AI_DISABLED',
        ]);
    }

    public function test_chat_endpoint_executes_copilot_and_persists_telemetry(): void
    {
        // Fake ErpCopilot prompt response using SDK
        Ai::fakeAgent(ErpCopilot::class, [
            'Total receivables balance is 45,000 INR across 3 customers.',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->postJson(route('api.v1.ai.chat'), [
                'message' => 'What is my total customer outstanding balance?',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'completed',
            'message' => 'Total receivables balance is 45,000 INR across 3 customers.',
            'scope' => [
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ],
        ]);

        $runId = $response->json('run_id');
        $threadId = $response->json('thread_id');

        // Assert run record persisted
        $run = DB::table('ai_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertSame('erp_copilot', $run->agent_key);

        // Assert messages persisted in conversation history
        $userMsg = DB::table('agent_conversation_messages')
            ->where('conversation_id', $threadId)
            ->where('role', 'user')
            ->first();
        $this->assertNotNull($userMsg);
        $this->assertSame('What is my total customer outstanding balance?', $userMsg->content);

        $assistantMsg = DB::table('agent_conversation_messages')
            ->where('conversation_id', $threadId)
            ->where('role', 'assistant')
            ->first();
        $this->assertNotNull($assistantMsg);
        $this->assertSame('Total receivables balance is 45,000 INR across 3 customers.', $assistantMsg->content);

        // Assert usage counter recorded
        $counter = DB::table('ai_usage_counters')
            ->where('company_id', $this->company->id)
            ->where('user_id', $this->adminUser->id)
            ->where('date', now()->toDateString())
            ->first();

        $this->assertNotNull($counter);
        $this->assertGreaterThanOrEqual(1, $counter->request_count);
    }
}
