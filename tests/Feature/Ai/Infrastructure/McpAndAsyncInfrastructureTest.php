<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Infrastructure\AsyncActionDispatch;
use App\Ai\Jobs\ExecuteApprovedAction;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\ActionProposalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\ApprovedActionExecutor;
use App\Ai\Services\ErpToolExecutor;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class McpAndAsyncInfrastructureTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private Company $company;
    private int $branch;
    private int $year;
    private string $token;
    private int $tokenId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Route::has('ai.mcp')) {
            require base_path('routes/ai-infrastructure.php');
        }
        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true, 'ai_actions.execution_enabled' => true,
            'ai_infrastructure.mcp.enabled' => true, 'ai_infrastructure.async_execution_enabled' => true,
            'ai_infrastructure.queue_connection' => 'database']);
        $this->actor = User::create(['name' => 'Infrastructure Actor', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'phone' => '1234567890', 'is_active' => true, 'is_deleted' => false]);
        $this->company = Company::create(['legal_name' => 'Infrastructure Company', 'trade_name' => 'Infrastructure',
            'code' => 'INF-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']);
        $this->branch = $this->company->branches()->create(['name' => 'Infrastructure Branch', 'code' => 'INF', 'is_active' => true])->id;
        $this->year = $this->company->fiscalYears()->create(['name' => 'Infrastructure FY', 'start_date' => '2026-04-01',
            'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false])->id;
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $this->branch]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
        $issued = $this->actor->createToken('MCP fixture', ['mcp:read', 'mcp:tool:receivables.outstanding.v1'], now()->addDay());
        $this->token = $issued->plainTextToken;
        $this->tokenId = $issued->accessToken->id;
        DB::table('ai_mcp_client_bindings')->insert(['id' => Str::uuid(), 'personal_access_token_id' => $this->tokenId,
            'actor_id' => $this->actor->id, 'company_id' => $this->company->id, 'branch_id' => $this->branch,
            'financial_year_id' => $this->year, 'allowed_tools' => json_encode(['receivables.outstanding.v1']),
            'is_active' => true, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_mcp_discovery_is_filtered_by_token_and_server_allowlists(): void
    {
        $result = $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $result->assertOk()->assertJsonCount(1, 'result.tools')->assertJsonPath('result.tools.0.name', 'CustomerOutstanding');
        $result->assertDontSee('prepare_purchase_order');
    }

    public function test_mcp_rejects_session_authentication_and_wildcard_tokens(): void
    {
        $this->actingAs($this->actor)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
        auth()->forgetGuards();
        $wildcard = $this->actor->createToken('ordinary ERP token', ['*'])->plainTextToken;
        $this->withToken($wildcard)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertForbidden();
    }

    public function test_mcp_rejects_expired_binding_origin_and_revoked_membership(): void
    {
        $this->withToken($this->token)->withHeader('Origin', 'https://untrusted.example')->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertForbidden();
        $this->flushHeaders();
        DB::table('ai_mcp_client_bindings')->where('personal_access_token_id', $this->tokenId)->update(['expires_at' => now()->subMinute()]);
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertForbidden();
        DB::table('ai_mcp_client_bindings')->where('personal_access_token_id', $this->tokenId)->update(['expires_at' => now()->addDay()]);
        DB::table('company_user_branches')->where('company_id', $this->company->id)->where('user_id', $this->actor->id)->delete();
        auth()->forgetGuards();
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertForbidden();
    }

    public function test_mcp_scope_injection_fails_executor_and_write_alias_is_unavailable(): void
    {
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'CustomerOutstanding', 'arguments' => ['company_id' => 999999]]])
            ->assertOk()->assertJsonPath('result.isError', true)->assertSee('AI_INPUT_VALIDATION_FAILED');
        $this->assertSame(1, DB::table('ai_tool_invocations')->where('tool_key', 'receivables.outstanding.v1')->where('status', 'denied')->count());
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'prepare_purchase_order']])->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_mcp_initialization_notifications_limits_and_kill_switch(): void
    {
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 'init', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-11-25');
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        config(['ai_infrastructure.mcp.max_request_bytes' => 10]);
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(413);
        config(['ai_infrastructure.mcp.enabled' => false]);
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertNotFound();
    }

    public function test_mcp_returns_same_authoritative_facts_as_native_executor_and_bounds_output(): void
    {
        $native = app(ErpToolExecutor::class)->execute((string) Str::uuid(), $this->principal(), 'receivables.outstanding.v1', ['limit' => 5]);
        $response = $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'CustomerOutstanding', 'arguments' => ['limit' => 5]]])->assertOk();
        $external = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($native->toArray(), $external);
        config(['ai_infrastructure.mcp.max_response_bytes' => 10]);
        $this->withToken($this->token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'CustomerOutstanding', 'arguments' => ['limit' => 5]]])->assertOk()->assertJsonPath('result.isError', true)->assertSee('Result exceeds external response limit');
    }

    public function test_async_execution_queues_references_then_executes_once(): void
    {
        $principal = $this->principal();
        $proposal = app(ActionProposalService::class)->create($principal, 'setup.supplier.create.v1', ['name' => 'Async Supplier']);
        app(ActionApprovalService::class)->approve($principal, $proposal['id'], 1);
        Bus::fake([ExecuteApprovedAction::class]);
        $result = app(AsyncActionDispatch::class)->dispatch($principal, $proposal['id'], 1);
        $this->assertSame('queued', $result['status']);
        $this->assertSame(0, Supplier::where('company_id', $this->company->id)->where('name', 'Async Supplier')->count());
        Bus::assertDispatched(ExecuteApprovedAction::class, fn ($job) => $job->actorId === $this->actor->id && $job->queue === 'ai-execution' && $job->tries === 1);
        $job = new ExecuteApprovedAction($proposal['id'], 1, $this->actor->id);
        $job->handle(app(AiPrincipalFactory::class), app(ApprovedActionExecutor::class));
        $job->handle(app(AiPrincipalFactory::class), app(ApprovedActionExecutor::class));
        $this->assertSame(1, Supplier::where('company_id', $this->company->id)->where('name', 'Async Supplier')->count());
        $this->assertSame(1, DB::table('ai_action_executions')->where('company_id', $this->company->id)->where('proposal_id', $proposal['id'])->where('status', 'completed')->count());
    }

    public function test_async_worker_revalidates_membership_and_skips_stale_version(): void
    {
        $principal = $this->principal();
        $proposal = app(ActionProposalService::class)->create($principal, 'setup.supplier.create.v1', ['name' => 'Revoked Supplier']);
        app(ActionApprovalService::class)->approve($principal, $proposal['id'], 1);
        (new ExecuteApprovedAction($proposal['id'], 2, $this->actor->id))->handle(app(AiPrincipalFactory::class), app(ApprovedActionExecutor::class));
        $this->assertSame(0, DB::table('ai_action_executions')->where('company_id', $this->company->id)->count());
        DB::table('company_user_branches')->where('company_id', $this->company->id)->where('user_id', $this->actor->id)->delete();
        $this->expectException(AuthorizationException::class);
        (new ExecuteApprovedAction($proposal['id'], 1, $this->actor->id))->handle(app(AiPrincipalFactory::class), app(ApprovedActionExecutor::class));
    }

    public function test_async_worker_respects_emergency_execution_switch_after_dispatch(): void
    {
        $principal = $this->principal();
        $proposal = app(ActionProposalService::class)->create($principal, 'setup.supplier.create.v1', ['name' => 'Disabled Supplier']);
        app(ActionApprovalService::class)->approve($principal, $proposal['id'], 1);
        config(['ai_actions.execution_enabled' => false]);
        try {
            (new ExecuteApprovedAction($proposal['id'], 1, $this->actor->id))->handle(app(AiPrincipalFactory::class), app(ApprovedActionExecutor::class));
            $this->fail('Worker must deny disabled execution.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Supplier::where('company_id', $this->company->id)->where('name', 'Disabled Supplier')->count());
            $this->assertSame(0, DB::table('ai_action_executions')->where('company_id', $this->company->id)->count());
        }
    }

    private function principal(): \App\Ai\DTOs\AiPrincipal
    {
        return app(AiPrincipalFactory::class)->createForUser($this->actor->id, $this->company->id, $this->branch, $this->year);
    }
}
