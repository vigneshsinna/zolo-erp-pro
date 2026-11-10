<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\PartyOpeningBalanceService;
use App\Services\Platform\CompanyContext;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\PendingStep;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

class AiChatApiAndWorkspaceTest extends TestCase
{
    use UsesAiMysql;

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
        DB::table('general_settings')->insert([
            'site_title' => 'ERP fixture', 'currency' => $this->company->base_currency_id,
            'staff_access' => 'all', 'date_format' => 'd/m/Y', 'theme' => 'default.css', 'currency_position' => 'prefix',
        ]);
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
            ->getJson(route('ai.settings'));

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
            ->postJson(route('ai.threads.store'), [
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
            ->getJson(route('ai.threads.index'));

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
            ->getJson(route('ai.threads.show', ['id' => $threadId]));

        $showRes->assertStatus(200);
        $this->assertSame($threadId, $showRes->json('thread.id'));

        // 4. Other user cannot access this thread
        $this->unpermittedUser->update(['role_id' => 1]);
        $otherRes = $this->actingAs($this->unpermittedUser)
            ->withSession([
                'company_id' => $this->company->id,
                'branch_id' => $this->branchId,
                'financial_year_id' => $this->fyId,
            ])
            ->getJson(route('ai.threads.show', ['id' => $threadId]));

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
            ->postJson(route('ai.chat'), [
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
            ->postJson(route('ai.chat'), [
                'message' => 'Show me the customer balance.',
            ]);

        $response->assertStatus(503);
        $response->assertJson([
            'error_code' => 'AI_DISABLED',
        ]);
    }

    public function test_feature_gate_fails_closed_when_company_settings_table_is_unavailable(): void
    {
        $gate = app(AiFeatureGate::class);
        $connection = DB::connection();
        $prefix = $connection->getTablePrefix();
        try {
            $connection->setTablePrefix('missing_ai_fixture_');
            $this->assertFalse($gate->isEnabledForCompany($this->company->id));
        } finally {
            $connection->setTablePrefix($prefix);
        }
    }

    public function test_legacy_turn_orders_the_question_before_its_answer_on_timestamp_ties(): void
    {
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $id = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $common = [
            'conversation_id' => $id, 'agent' => 'erp_copilot', 'status' => 'completed',
            'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]', 'created_at' => now(),
        ];
        DB::table('agent_conversation_messages')->insert([
            $common + ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'role' => 'user', 'content' => 'Original question'],
            $common + ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'role' => 'assistant', 'content' => 'Original answer'],
        ]);

        $this->getJson(route('ai.threads.show', $id))->assertOk()
            ->assertJsonPath('messages.0.role', 'user')->assertJsonPath('messages.1.role', 'assistant');
    }

    public function test_revoked_tool_facts_are_hidden_from_history_and_provider_replay(): void
    {
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $protected = 'Protected receivables from an earlier turn';
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $thread,
            'agent' => 'erp_copilot',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => $protected,
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => json_encode(['facts' => [['tool' => 'receivables.outstanding.v1', 'data' => ['protected' => $protected]]]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $role = DB::table('roles')->insertGetId(['name' => 'Inventory AI fixture', 'guard_name' => 'web', 'is_active' => true]);
        foreach (['ai.use', 'products-index'] as $name) {
            $permission = DB::table('permissions')->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('role_has_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->adminUser->id)
            ->update(['role_id_override' => $role]);

        $this->getJson(route('ai.threads.show', $thread))->assertOk()
            ->assertJsonPath('messages.0.facts', [])->assertDontSee($protected);
        $this->configureProvider();
        Http::fake(['https://provider.example/v1/responses' => Http::response($this->providerResponse($this->providerMessage('Continue'), 20, 3))]);
        $this->postJson(route('ai.chat'), ['thread_id' => $thread, 'message' => 'Continue'])->assertOk();
        Http::assertSent(fn (ProviderRequest $request) => !str_contains($request->body(), $protected));
    }

    public function test_sanctum_routes_accept_bearer_tokens_and_workspace_routes_require_sessions(): void
    {
        $token = $this->adminUser->createToken('AI fixture')->plainTextToken;
        $this->withToken($token)->withHeaders([
            'X-Company-ID' => (string) $this->company->id,
            'X-Branch-ID' => (string) $this->branchId,
            'X-Financial-Year-ID' => (string) $this->fyId,
        ]);
        $this->getJson('/ai/settings')->assertUnauthorized();
        $this->getJson('/api/v1/ai/settings')->assertOk()->assertJsonPath('scope', $this->workspaceContext());
    }

    public function test_threads_are_bound_to_their_active_branch_and_financial_year(): void
    {
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $id = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $branch = $this->company->branches()->create(['name' => 'Other branch', 'code' => 'AI-OTHER', 'is_active' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $branch->id,
        ]);
        $year = $this->company->fiscalYears()->create([
            'name' => 'AI historical fixture', 'start_date' => '2020-04-01', 'end_date' => '2021-03-31', 'status' => 'closed',
        ]);

        foreach ([['branch_id' => $branch->id], ['financial_year_id' => $year->id]] as $change) {
            $this->withSession(array_replace($this->workspaceContext(), $change));
            $this->getJson(route('ai.threads.index'))->assertOk()->assertJsonCount(0, 'threads');
            $this->getJson(route('ai.threads.show', $id))->assertNotFound();
            $this->postJson(route('ai.chat'), ['thread_id' => $id, 'message' => 'Continue'])->assertNotFound();
            $this->getJson(route('ai.threads.index'), ['X-AI-Scope' => implode('/', $this->workspaceContext())])->assertConflict();
        }

        DB::table('company_user_branches')->where('company_id', $this->company->id)
            ->where('user_id', $this->adminUser->id)->where('branch_id', $this->branchId)->delete();
        $this->withSession($this->workspaceContext())->getJson(route('ai.threads.show', $id))->assertForbidden();
    }

    public function test_report_role_requires_ai_permission_and_company_override_applies_to_admin(): void
    {
        DB::table('roles')->insert(['id' => 99, 'name' => 'AI reporting fixture', 'guard_name' => 'web', 'is_active' => true]);
        $this->company->users()->updateExistingPivot($this->adminUser->id, ['role_id_override' => 99]);
        $grant = function (string $name): void {
            DB::table('role_has_permissions')->insert([
                'role_id' => 99, 'permission_id' => DB::table('permissions')->where('name', $name)->value('id'),
            ]);
        };
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $grant('accounting.reports.view');
        $this->postJson(route('ai.threads.store'))->assertForbidden();
        $grant('ai.use');
        $id = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        DB::table('role_has_permissions')->where('role_id', 99)->delete();
        $this->getJson(route('ai.threads.show', $id))->assertForbidden();
    }

    public function test_step_reservation_blocks_an_overlapping_company_call_and_releases_unused_tokens(): void
    {
        config(['erp_ai.daily_company_tokens' => 4000]);
        $this->configureProvider();
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $date = now('UTC')->toDateString();
        $gateway = app(ApprovedModelGateway::class);
        $otherActor = new AiPrincipal($this->unpermittedUser->id, $this->company->id, $this->branchId, $this->fyId);
        Http::fake(['https://provider.example/v1/responses' => function () use ($date, $gateway, $otherActor) {
            $step = new PendingStep(0, true, 'openai', 'gpt-4o', 'instructions', [], [], null, null);
            $this->assertGreaterThan(0, DB::table('ai_usage_counters')->where('company_id', $this->company->id)->sum('reserved_tokens'));
            try {
                $gateway->budgetStep($otherActor, $date, $step, fn () => $this->fail('Overlapping step exceeded quota'));
                $this->fail('Expected company quota denial');
            } catch (TooManyRequestsHttpException $error) {
                $this->assertStringContainsString('company AI token quota', $error->getMessage());
            }

            return Http::response($this->providerResponse($this->providerMessage('Unverified answer'), 150, 25));
        }]);

        $this->postJson(route('ai.chat'), ['message' => 'Show customer dues'])->assertOk()->assertJsonPath('facts', [])
            ->assertJsonPath('message', 'The copilot could not produce a verified answer. Review any available ERP results below.');
        $this->assertDatabaseHas('ai_usage_counters', [
            'company_id' => $this->company->id, 'input_tokens' => 150, 'output_tokens' => 25, 'reserved_tokens' => 0,
        ]);
        config(['erp_ai.daily_user_requests' => 1]);
        $this->postJson(route('ai.chat'), ['message' => 'Try again'])->assertTooManyRequests();
        Http::assertSentCount(1);
    }

    public function test_chat_endpoint_executes_copilot_and_persists_telemetry(): void
    {
        $this->configureProvider();
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $context = new CompanyContext($this->company->id, $this->branchId, $this->fyId);
        $groupId = DB::table('customer_groups')->where('company_id', $this->company->id)->value('id');
        $customer = Customer::create([
            'company_id' => $this->company->id, 'customer_group_id' => $groupId,
            'name' => 'AI fixture customer', 'phone_number' => '5550100', 'is_active' => true,
        ]);
        app(PartyOpeningBalanceService::class)->post($customer, 45000.2512, $context);
        // An unmatched control opening amount proves reconciliation warnings come from the ERP tool.
        (new ChartOfAccount)->forceFill([
            'company_id' => $this->company->id, 'code' => 'AI-FIXTURE-AR', 'name' => 'AI reconciliation fixture',
            'type' => 'asset', 'sub_type' => 'current_asset', 'control_type' => 'ar', 'opening_balance' => '20.00', 'is_active' => true,
        ])->save();
        $report = app(FinancialReportService::class)->ageing($context, 'customer', actorId: $this->adminUser->id);
        $this->assertFalse($report['reconciliation']['is_reconciled']);

        $question = 'What is my total customer outstanding balance?';
        $answer = 'The ERP reported a reconciliation difference. Review the figures and warnings below.';
        $fabricated = 'Total receivables balance is 99,999,999 INR.';
        $followUp = 'What about the same date?';
        Http::fake(['https://provider.example/v1/responses' => Http::sequence()
            ->push($this->providerResponse([
                'type' => 'function_call', 'call_id' => 'call_receivables', 'name' => 'CustomerOutstanding',
                'arguments' => json_encode(['as_of' => $report['as_of_date'], 'limit' => 5]),
            ], 111, 17))
            ->push($this->providerResponse($this->providerMessage($fabricated), 303, 23))
            ->push($this->providerResponse([
                'type' => 'function_call', 'call_id' => 'call_followup', 'name' => 'CustomerOutstanding', 'arguments' => '{}',
            ], 175, 13))
            ->push($this->providerResponse($this->providerMessage($fabricated), 370, 22))]);

        $response = $this->postJson(route('ai.chat'), ['message' => $question, 'options' => ['model' => 'unapproved']]);
        $response->assertOk();
        $response->assertJson([
            'status' => 'completed',
            'answer_status' => 'unreconciled',
            'message' => $answer,
            'scope' => $this->workspaceContext(),
            'usage' => ['input_tokens' => 414, 'output_tokens' => 40, 'tool_count' => 1],
        ]);
        $response->assertJsonPath('facts.0.data.total_outstanding', '45000.2512');
        $response->assertJsonPath('facts.0.data.reconciliation.open_item_balance', $report['reconciliation']['open_item_balance']);
        $response->assertJsonPath('facts.0.data.reconciliation.difference', $report['reconciliation']['difference']);
        $response->assertJsonPath('facts.0.data.top_customers.0.customer_name', $customer->name);
        $this->assertStringContainsString('Control account variance detected', $response->json('warnings.0'));
        $this->assertStringStartsWith('/accounting/ageing?', $response->json('sources.0.href'));

        $runId = $response->json('run_id');
        $threadId = $response->json('thread_id');
        $this->assertDatabaseHas('ai_runs', [
            'id' => $runId, 'status' => 'completed', 'model_provider' => 'openai', 'model_key' => 'gpt-4o',
            'input_tokens' => 414, 'output_tokens' => 40,
        ]);
        $this->assertDatabaseHas('ai_tool_invocations', ['run_id' => $runId, 'tool_key' => 'receivables.outstanding.v1', 'status' => 'ok']);
        $this->assertDatabaseHas('ai_model_invocations', ['run_id' => $runId, 'step_number' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('ai_model_invocations', ['run_id' => $runId, 'step_number' => 2, 'status' => 'completed']);
        $this->assertSame(2, (int) DB::table('ai_model_invocations')->where('run_id', $runId)->sum('http_attempts'));
        $this->assertDatabaseHas('ai_usage_counters', [
            'company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'request_count' => 1,
            'input_tokens' => 414, 'output_tokens' => 40, 'reserved_tokens' => 0,
        ]);
        $this->getJson(route('ai.threads.show', $threadId))->assertOk()
            ->assertJsonPath('messages.0.content', $question)
            ->assertJsonPath('messages.1.facts.0.data.total_outstanding', '45000.2512')
            ->assertJsonPath('messages.1.warnings.0', $response->json('warnings.0'));

        $this->postJson(route('ai.chat'), ['thread_id' => $threadId, 'message' => $followUp])->assertOk();
        $requests = Http::recorded()->map(fn (array $exchange): ProviderRequest => $exchange[0]);
        $this->assertCount(4, $requests);
        foreach ($requests as $request) {
            $this->assertSame('gpt-4o', $request['model']);
        }
        $tools = collect($requests[0]['tools'])->keyBy('name');
        $this->assertArrayHasKey('CustomerOutstanding', $tools->all());
        $this->assertArrayHasKey('sales_summary', $tools->all());
        $this->assertArrayNotHasKey('proposals.draft.v1', $tools->all());
        $this->assertSame(20, $tools['CustomerOutstanding']['parameters']['properties']['limit']['maximum']);
        $this->assertFalse($tools['CustomerOutstanding']['parameters']['additionalProperties']);
        $this->assertSame('date', $tools['CustomerOutstanding']['parameters']['properties']['as_of']['format']);
        foreach ($tools->keys() as $name) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/D', $name);
        }
        $history = json_encode($requests[2]['input']);
        $this->assertStringContainsString($question, $history);
        $this->assertStringContainsString($answer, $history);
        $this->assertSame(1, substr_count($history, $followUp));
    }

    public function test_monitoring_endpoint_returns_telemetry_for_admins_and_denies_unauthorized_users(): void
    {
        $this->configureProvider();

        // 1. Unauthenticated or non-admin user is denied with 403
        $this->actingAs($this->unpermittedUser)->withSession($this->workspaceContext());
        $this->getJson(route('ai.monitoring'))->assertStatus(403)
            ->assertJsonPath('error_code', 'AI_FORBIDDEN');

        // 2. Admin user receives structured monitoring telemetry
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $response = $this->getJson(route('ai.monitoring'));
        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'provider',
                'model',
                'rpm' => ['provider', 'limit', 'current', 'remaining', 'status'],
                'today' => [
                    'date',
                    'user_requests',
                    'model_invocations',
                    'erp_tool_calls',
                    'input_tokens',
                    'output_tokens',
                    'total_tokens',
                    'daily_token_limit',
                    'tokens_remaining',
                ],
                'recent_runs',
                'scope',
            ])
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('rpm.status', 'healthy');

        // 3. Sanctum endpoint parity
        $responseApi = $this->getJson(route('api.v1.ai.monitoring'));
        $responseApi->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_provider_rpm_rate_limiter_throttles_when_limit_exceeded(): void
    {
        $this->configureProvider();
        config(['erp_ai.nvidia_rpm_limit' => 2]);
        RateLimiter::clear('ai:rpm:openai');
        RateLimiter::clear('ai:rpm:nvidia');

        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        $gateway = app(ApprovedModelGateway::class);

        // 1st request should pass
        $gateway->enforceProviderRateLimit('nvidia');
        $this->assertSame(1, RateLimiter::attempts('ai:rpm:nvidia'));

        // 2nd request should pass
        $gateway->enforceProviderRateLimit('nvidia');
        $this->assertSame(2, RateLimiter::attempts('ai:rpm:nvidia'));

        // 3rd request should hit limit and throw 429
        try {
            $gateway->enforceProviderRateLimit('nvidia');
            $this->fail('Expected rate limit exception');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertStringContainsString('limit reached (2 requests per minute)', $e->getMessage());
        }

        $rpmUsage = $gateway->getProviderRpmUsage('nvidia');
        $this->assertSame(2, $rpmUsage['current']);
        $this->assertSame(2, $rpmUsage['limit']);
        $this->assertSame(0, $rpmUsage['remaining']);
        $this->assertSame('limited', $rpmUsage['status']);

        RateLimiter::clear('ai:rpm:nvidia');
    }

    public function test_nvidia_retries_share_provider_bucket_and_count_separately_from_steps(): void
    {
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());
        config([
            'erp_ai.provider' => 'nvidia',
            'erp_ai.model' => 'deepseek-ai/deepseek-v4.1-flash',
            'erp_ai.nvidia_rpm_limit' => 3,
            'ai.providers.nvidia.url' => 'https://nim.example/v1',
            'ai.providers.nvidia.key' => 'fixture-key',
        ]);
        RateLimiter::clear('ai:rpm:nvidia');
        Http::preventStrayRequests();
        Http::fake(['https://nim.example/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'fixture overload']], 503, ['Retry-After' => '0'])
            ->push([
                'id' => 'fixture-completion',
                'model' => 'deepseek-ai/deepseek-v4.1-flash',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Fixture response'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 18, 'completion_tokens' => 3, 'total_tokens' => 21],
            ])]);

        $run = $this->postJson(route('ai.chat'), ['message' => 'What can you do?'])->assertOk()->json('run_id');
        Http::assertSentCount(2);
        $this->assertDatabaseHas('ai_model_invocations', ['run_id' => $run, 'step_number' => 1, 'http_attempts' => 2]);
        $this->assertSame(2, app(ApprovedModelGateway::class)->getProviderRpmUsage('deepseek')['current']);
        $this->getJson(route('ai.monitoring'))->assertOk()->assertJsonPath('today.provider_retries', 1);
    }

    public function test_chat_turn_persists_model_invocation_steps(): void
    {
        $this->configureProvider();
        RateLimiter::clear('ai:rpm:openai');
        $this->actingAs($this->adminUser)->withSession($this->workspaceContext());

        Http::fake([
            'https://provider.example/v1/responses' => Http::sequence()
                ->push($this->providerResponse($this->providerMessage('Direct answer without tools'), 120, 35)),
        ]);

        $res = $this->postJson(route('ai.chat'), ['message' => 'Hello Copilot'])->assertOk();
        $runId = $res->json('run_id');

        $this->assertDatabaseHas('ai_model_invocations', [
            'run_id' => $runId,
            'company_id' => $this->company->id,
            'step_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'status' => 'completed',
            'input_tokens' => 120,
            'output_tokens' => 35,
        ]);
    }

    private function providerResponse(array $output, int $inputTokens, int $outputTokens): array
    {
        return [
            'id' => 'resp_fixture', 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$output + ['status' => 'completed']],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    private function configureProvider(): void
    {
        config([
            'erp_ai.provider' => 'openai', 'erp_ai.model' => 'gpt-4o',
            'ai.providers.openai.key' => 'fixture-key', 'ai.providers.openai.store' => false,
            'ai.providers.openai.url' => 'https://provider.example/v1',
        ]);
        Http::preventStrayRequests();
    }

    private function providerMessage(string $text): array
    {
        return ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]];
    }

    private function workspaceContext(): array
    {
        return ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];
    }
}
