<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\DTOs\ErpToolResult;
use App\Ai\Infrastructure\McpClientBindingService;
use App\Ai\Services\ActionApprovalService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\ApprovedActionExecutor;
use App\Ai\Services\ErpToolExecutor;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

/**
 * Covers ALL U2 mandatory acceptance cases M01 through M20 on real MySQL.
 */
final class McpGovernedProposalsTest extends TestCase
{
    use UsesAiMysql;

    private Company $companyA;
    private Company $companyB;
    private int $branchA1;
    private int $branchA2;
    private int $branchB1;
    private int $fyA1;
    private int $fyA2;
    private int $fyB1;
    private User $adminA;
    private User $otherActorA;
    private User $viewerA;
    private User $adminB;
    private Supplier $supplierA;
    private Warehouse $warehouseA;
    private Product $productA;
    private Unit $unitA;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'erp_ai.enabled' => true,
            'erp_ai.conversation_enabled' => false,
            'ai_actions.foundation_enabled' => true,
            'ai_actions.execution_enabled' => true,
            'ai_infrastructure.mcp.enabled' => true,
            'ai_infrastructure.mcp.governed_proposals_enabled' => true,
            'ai_capabilities.shared_discovery_enabled' => true,
        ]);

        $this->companyA = Company::create([
            'legal_name' => 'MCP Alpha Co', 'trade_name' => 'MCP Alpha',
            'code' => 'MCPA-' . Str::random(6), 'status' => 'active', 'country_code' => 'IN',
        ]);
        $this->branchA1 = $this->companyA->branches()->create(['name' => 'Alpha B1', 'code' => 'AB1', 'is_active' => true])->id;
        $this->branchA2 = $this->companyA->branches()->create(['name' => 'Alpha B2', 'code' => 'AB2', 'is_active' => true])->id;
        $this->fyA1 = $this->companyA->fiscalYears()->create([
            'name' => 'FY 2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;
        $this->fyA2 = $this->companyA->fiscalYears()->create([
            'name' => 'FY 2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31', 'status' => 'open',
        ])->id;

        $this->companyB = Company::create([
            'legal_name' => 'MCP Beta Co', 'trade_name' => 'MCP Beta',
            'code' => 'MCPB-' . Str::random(6), 'status' => 'active', 'country_code' => 'IN',
        ]);
        $this->branchB1 = $this->companyB->branches()->create(['name' => 'Beta B1', 'code' => 'BB1', 'is_active' => true])->id;
        $this->fyB1 = $this->companyB->fiscalYears()->create([
            'name' => 'FY 2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;

        DB::table('roles')->updateOrInsert(['id' => 4], ['name' => 'Viewer', 'is_active' => true, 'guard_name' => 'web']);

        $this->adminA = User::create([
            'name' => 'MCP Admin A', 'email' => Str::uuid() . '@example.test',
            'phone' => '9100000001', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->otherActorA = User::create([
            'name' => 'MCP Peer A', 'email' => Str::uuid() . '@example.test',
            'phone' => '9100000002', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->viewerA = User::create([
            'name' => 'MCP Viewer A', 'email' => Str::uuid() . '@example.test',
            'phone' => '9100000003', 'password' => bcrypt('secret'), 'role_id' => 4, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->adminB = User::create([
            'name' => 'MCP Admin B', 'email' => Str::uuid() . '@example.test',
            'phone' => '9100000004', 'password' => bcrypt('secret'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);

        $this->companyA->users()->attach($this->adminA->id, ['is_default' => true]);
        $this->companyA->users()->attach($this->otherActorA->id, ['is_default' => true]);
        $this->companyA->users()->attach($this->viewerA->id, ['is_default' => true, 'role_id_override' => 4]);
        $this->companyB->users()->attach($this->adminB->id, ['is_default' => true]);

        DB::table('company_user_branches')->insert([
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminA->id, 'branch_id' => $this->branchA1],
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminA->id, 'branch_id' => $this->branchA2],
            ['company_id' => $this->companyA->id, 'user_id' => $this->otherActorA->id, 'branch_id' => $this->branchA1],
            ['company_id' => $this->companyA->id, 'user_id' => $this->viewerA->id, 'branch_id' => $this->branchA1],
            ['company_id' => $this->companyB->id, 'user_id' => $this->adminB->id, 'branch_id' => $this->branchB1],
        ]);

        app(AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false, ['mcp_proposals_enabled' => true]);
        app(AiFeatureGate::class)->setCompanySetting($this->companyB->id, true, false, ['mcp_proposals_enabled' => true]);

        $this->unitA = Unit::forceCreate([
            'unit_code' => 'MTR', 'unit_name' => 'Metre', 'is_active' => true, 'company_id' => $this->companyA->id,
        ]);
        $this->supplierA = Supplier::forceCreate([
            'company_id' => $this->companyA->id, 'name' => 'ABC Traders', 'company_name' => 'ABC Traders',
            'phone_number' => '9876543210', 'vat_number' => '33AABCU9603R1ZM',
            'address' => 'Erode', 'city' => 'Erode', 'country' => 'India', 'is_active' => true,
        ]);
        $this->warehouseA = Warehouse::forceCreate([
            'company_id' => $this->companyA->id, 'branch_id' => $this->branchA1,
            'name' => 'Erode Central Warehouse', 'address' => 'Erode', 'is_active' => true,
        ]);
        $category = Category::firstOrCreate(['name' => 'Textiles'], ['is_active' => true]);
        $this->productA = Product::forceCreate([
            'company_id' => $this->companyA->id, 'name' => 'Linen Blue', 'code' => 'SKU-LINEN-MCP',
            'type' => 'standard', 'barcode_symbology' => 'C128', 'category_id' => $category->id,
            'cost' => 120, 'price' => 180,
            'unit_id' => $this->unitA->id, 'purchase_unit_id' => $this->unitA->id, 'sale_unit_id' => $this->unitA->id,
            'is_active' => true, 'qty' => 0,
        ]);
    }

    public function test_m01_m02_m03_m04_m05_discovery_gates_token_abilities_and_revocation(): void
    {
        $service = app(McpClientBindingService::class);

        // M01: Legacy read-only client sees CustomerOutstanding and zero prepare tools
        $readGrant = $service->issue($this->adminA, $this->companyA->id, $this->branchA1, $this->fyA1, 'read-client', ['read'], ['receivables.outstanding.v1']);
        $this->withToken($readGrant['plain_text_token'])->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonCount(1, 'result.tools')
            ->assertJsonPath('result.tools.0.name', 'CustomerOutstanding')
            ->assertDontSee('erp_purchase_order_prepare');

        // M03: Read-only token cannot call prepare
        $this->withToken($readGrant['plain_text_token'])->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'erp_purchase_order_prepare', 'arguments' => ['client_request_id' => 'req-readonly-01', 'inputs' => []]],
        ])->assertOk()->assertJsonPath('result.isError', true);

        // M02: Governed proposals flag OFF -> prepare/status hidden and denied
        $prepGrant = $service->issue($this->adminA, $this->companyA->id, $this->branchA1, $this->fyA1, 'prep-client', ['read', 'prepare', 'status'], ['receivables.outstanding.v1'], ['purchase_order.create.v1']);
        config(['ai_infrastructure.mcp.governed_proposals_enabled' => false]);
        auth()->forgetGuards();
        $this->withToken($prepGrant['plain_text_token'])->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])
            ->assertOk()->assertDontSee('erp_purchase_order_prepare')->assertDontSee('erp_proposal_status');

        // M02: Company opt-in OFF -> denied for prepare-only client
        config(['ai_infrastructure.mcp.governed_proposals_enabled' => true]);
        app(AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false, ['mcp_proposals_enabled' => false]);
        $prepOnly = $service->issue($this->adminA, $this->companyA->id, $this->branchA1, $this->fyA1, 'prep-only', ['prepare'], [], ['purchase_order.create.v1']);
        auth()->forgetGuards();
        $this->withToken($prepOnly['plain_text_token'])->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'])->assertForbidden();

        app(AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false, ['mcp_proposals_enabled' => true]);

        // M03: Wildcard token denied
        $wildcard = $this->adminA->createToken('wildcard', ['*'], now()->addDay())->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($wildcard)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'])->assertForbidden();

        // M04: Viewer role lacks purchases-add business permission -> PO prepare hidden and denied
        $viewerToken = $this->viewerA->createToken('viewer-mcp', ['mcp:read', 'mcp:prepare', 'mcp:tool:receivables.outstanding.v1', 'mcp:tool:purchase_order.create.v1'], now()->addDay());
        DB::table('ai_mcp_client_bindings')->insert([
            'id' => Str::uuid(), 'client_identifier' => 'viewer', 'personal_access_token_id' => $viewerToken->accessToken->id,
            'actor_id' => $this->viewerA->id, 'company_id' => $this->companyA->id, 'branch_id' => $this->branchA1, 'financial_year_id' => $this->fyA1,
            'allowed_tools' => json_encode(['receivables.outstanding.v1']), 'allowed_operations' => json_encode(['read', 'prepare']),
            'allowed_actions' => json_encode(['purchase_order.create.v1']), 'is_active' => true, 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        auth()->forgetGuards();
        $this->withToken($viewerToken->plainTextToken)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list'])
            ->assertOk()->assertDontSee('erp_purchase_order_prepare');

        // M05: Revocation after discovery immediately denies invocation
        auth()->forgetGuards();
        $this->withToken($prepOnly['plain_text_token'])->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list'])
            ->assertOk()->assertJsonPath('result.tools.0.name', 'erp_purchase_order_prepare');
        $service->revoke($prepOnly['binding_id'], $this->companyA->id, 'security_drill');
        auth()->forgetGuards();
        $this->withToken($prepOnly['plain_text_token'])->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
            'params' => ['name' => 'erp_purchase_order_prepare', 'arguments' => ['client_request_id' => 'req-revoked-001', 'inputs' => []]],
        ])->assertStatus(401);
    }

    public function test_m07_m08_m09_m14_m17_m18_m19_protocol_boundaries_clarification_and_redaction(): void
    {
        $grant = app(McpClientBindingService::class)->issue(
            $this->adminA, $this->companyA->id, $this->branchA1, $this->fyA1,
            'full-mcp-client', ['read', 'prepare', 'status'], ['receivables.outstanding.v1'], ['purchase_order.create.v1']
        );
        $token = $grant['plain_text_token'];

        // M18: Protocol versions 2025-11-25 and 2025-06-18, plus ping & notifications & unsupported version 400
        foreach (['2025-11-25', '2025-06-18'] as $ver) {
            $this->withToken($token)->withHeader('MCP-Protocol-Version', $ver)
                ->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 'init-' . $ver, 'method' => 'initialize'])
                ->assertOk()->assertJsonPath('result.protocolVersion', $ver);
        }
        $this->withToken($token)->withHeader('MCP-Protocol-Version', '1999-01-01')
            ->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(400);
        $this->flushHeaders();

        // M07: Authority fields nested inside items or _meta rejected
        $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                '_meta' => ['company_id' => $this->companyB->id],
                'arguments' => ['client_request_id' => 'req-meta-001', 'inputs' => ['supplier_query' => 'ABC']],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);

        $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => [
                    'client_request_id' => 'req-nested-001',
                    'inputs' => ['items' => [['product_query' => 'Linen', 'qty' => '5', 'company_id' => $this->companyB->id]]],
                ],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);

        // M08: Malformed JSON, oversized body
        $this->call('POST', '/mcp/erp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], '{bad-json')->assertOk()->assertJsonPath('error.code', -32700);
        config(['ai_infrastructure.mcp.max_request_bytes' => 32]);
        $this->withToken($token)->postJson('/mcp/erp', ['jsonrpc' => '2.0', 'id' => 12, 'method' => 'ping'])->assertStatus(413);
        config(['ai_infrastructure.mcp.max_request_bytes' => 16384]);

        // M09: Missing supplier/warehouse returns owned needs_input clarification
        $clarifyRes = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => [
                    'client_request_id' => 'req-clarify-001',
                    'inputs' => ['items' => [['product_query' => 'Linen Blue', 'qty' => '20', 'net_unit_cost' => '120']]],
                ],
            ],
        ])->assertOk()->assertJsonPath('result.isError', false);
        $clarifyPayload = json_decode($clarifyRes->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('needs_input', $clarifyPayload['status']);
        $this->assertNotEmpty($clarifyPayload['task_id']);

        // M14: approve/execute/reverse/send cannot be discovered or called
        foreach (['approve', 'execute', 'approve_and_execute', 'reverse', 'send', 'erp_approve'] as $forbidden) {
            $this->withToken($token)->postJson('/mcp/erp', [
                'jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/call',
                'params' => ['name' => $forbidden, 'arguments' => []],
            ])->assertOk()->assertJsonPath('result.isError', true);
        }

        // M17: ErpToolExecutor retains read-only rejection
        $principal = app(AiPrincipalFactory::class)->createForUser($this->adminA->id, $this->companyA->id, $this->branchA1, $this->fyA1);
        $badResult = app(ErpToolExecutor::class)->execute((string) Str::uuid(), $principal, 'purchase_order.create.v1', []);
        $this->assertSame('denied', $badResult->status);

        // M19: Audit logs never contain bearer tokens or supplier GSTIN
        $auditDump = json_encode(DB::table('ai_tool_invocations')->get()->toArray());
        $this->assertStringNotContainsString($token, (string) $auditDump);
        $this->assertStringNotContainsString('33AABCU9603R1ZM', (string) $auditDump);
    }

    public function test_m06_m10_m11_m12_m13_m15_m16_m20_end_to_end_prepare_idempotency_conflict_human_approval_and_status(): void
    {
        $service = app(McpClientBindingService::class);
        $grant = $service->issue(
            $this->adminA, $this->companyA->id, $this->branchA1, $this->fyA1,
            'po-erp-agent', ['read', 'prepare', 'status'], ['receivables.outstanding.v1'], ['purchase_order.create.v1']
        );
        $token = $grant['plain_text_token'];

        $poInputs = [
            'supplier_query' => 'ABC Traders',
            'warehouse_query' => 'Erode Central Warehouse',
            'payment_terms_type' => 'credit',
            'credit_days' => 30,
            'items' => [
                ['product_query' => 'Linen Blue', 'qty' => '100', 'net_unit_cost' => '120.00', 'uom' => 'MTR'],
            ],
        ];

        // M10: Valid PO preparation creates draft proposal only; zero PO/stock/ledger writes
        $res1 = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => ['client_request_id' => 'mcp-po-create-0001', 'inputs' => $poInputs],
            ],
        ])->assertOk()->assertJsonPath('result.isError', false);

        $p1 = json_decode($res1->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('proposal_created', $p1['status']);
        $this->assertTrue($p1['requires_approval']);
        $this->assertSame(1, $p1['proposal_version']);
        $this->assertStringContainsString('/ai/action-review', $p1['review_url']);
        $this->assertSame(0, Purchase::where('company_id', $this->companyA->id)->count());

        // M11: Replay same client_request_id + same inputs returns same task/proposal without duplicates
        $resReplay = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => ['client_request_id' => 'mcp-po-create-0001', 'inputs' => $poInputs],
            ],
        ])->assertOk();
        $pReplay = json_decode($resReplay->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($p1['proposal_id'], $pReplay['proposal_id']);
        $this->assertTrue($pReplay['idempotent_replay']);
        $this->assertSame(1, DB::table('ai_action_proposals')->where('company_id', $this->companyA->id)->count());
        $this->assertSame(1, DB::table('ai_mcp_preparation_requests')->where('company_id', $this->companyA->id)->count());

        // M12: Same client_request_id + different inputs -> conflict; prior draft untouched
        $changedInputs = $poInputs;
        $changedInputs['items'][0]['qty'] = '999';
        $resConflict = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 22, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => ['client_request_id' => 'mcp-po-create-0001', 'inputs' => $changedInputs],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);
        $pConflict = json_decode($resConflict->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('conflict', $pConflict['status']);
        $this->assertSame('MCP_IDEMPOTENCY_CONFLICT', $pConflict['error_code']);

        // M13: Stale task revision -> conflict; valid revision amendment bumps proposal v1 -> v2
        $resStale = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 23, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => [
                    'client_request_id' => 'mcp-po-amend-stale-0001',
                    'task_id' => $p1['task_id'],
                    'expected_revision' => 1,
                    'inputs' => ['items' => [['product_query' => 'Linen Blue', 'qty' => '80', 'net_unit_cost' => '120.00', 'uom' => 'MTR']]],
                ],
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);
        $pStale = json_decode($resStale->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('TASK_REVISION_CONFLICT', $pStale['error_code']);

        $resAmend = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 24, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => [
                    'client_request_id' => 'mcp-po-amend-valid-0002',
                    'task_id' => $p1['task_id'],
                    'expected_revision' => (int) $p1['task_revision'],
                    'inputs' => ['items' => [['product_query' => 'Linen Blue', 'qty' => '80', 'net_unit_cost' => '120.00', 'uom' => 'MTR']]],
                ],
            ],
        ])->assertOk()->assertJsonPath('result.isError', false);
        $pAmend = json_decode($resAmend->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $pAmend['proposal_version']);

        // M06: Another actor / foreign branch / foreign FY / foreign company cannot inspect proposal status
        $peerGrant = $service->issue($this->otherActorA, $this->companyA->id, $this->branchA1, $this->fyA1, 'peer-client', ['status'], [], []);
        $branch2Grant = $service->issue($this->adminA, $this->companyA->id, $this->branchA2, $this->fyA1, 'b2-client', ['status'], [], []);
        $fy2Grant = $service->issue($this->adminA, $this->companyA->id, $this->branchA1, $this->fyA2, 'fy2-client', ['status'], [], []);
        $companyBGrant = $service->issue($this->adminB, $this->companyB->id, $this->branchB1, $this->fyB1, 'cb-client', ['status'], [], []);

        foreach ([$peerGrant, $branch2Grant, $fy2Grant, $companyBGrant] as $foreign) {
            auth()->forgetGuards();
            $this->withToken($foreign['plain_text_token'])->postJson('/mcp/erp', [
                'jsonrpc' => '2.0', 'id' => 25, 'method' => 'tools/call',
                'params' => ['name' => 'erp_proposal_status', 'arguments' => ['proposal_id' => $p1['proposal_id']]],
            ])->assertOk()->assertJsonPath('result.isError', true);
        }

        // M16: Source change after preview blocks stale posting on execution
        $this->supplierA->update(['is_active' => false]);
        $this->actingAs($this->adminA);
        $principal = app(AiPrincipalFactory::class)->createForUser($this->adminA->id, $this->companyA->id, $this->branchA1, $this->fyA1);
        app(ActionApprovalService::class)->approve($principal, $p1['proposal_id'], 2);
        try {
            app(ApprovedActionExecutor::class)->execute($principal, $p1['proposal_id'], 2);
            $this->fail('Source state change must prevent stale execution.');
        } catch (ValidationException) {
            $this->assertSame(0, Purchase::where('company_id', $this->companyA->id)->count());
        }

        // Restore supplier, prepare fresh v1 via MCP, approve & execute via ERP, then verify M15 status & receipt
        $this->supplierA->update(['is_active' => true]);
        auth()->forgetGuards();
        $resFresh = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 26, 'method' => 'tools/call',
            'params' => [
                'name' => 'erp_purchase_order_prepare',
                'arguments' => ['client_request_id' => 'mcp-po-fresh-0003', 'inputs' => $poInputs],
            ],
        ])->assertOk();
        $pFresh = json_decode($resFresh->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);

        $this->actingAs($this->adminA);
        app(ActionApprovalService::class)->approve($principal, $pFresh['proposal_id'], 1);
        app(ApprovedActionExecutor::class)->execute($principal, $pFresh['proposal_id'], 1);
        $this->assertSame(1, Purchase::where('company_id', $this->companyA->id)->count());

        $dbProposal = DB::table('ai_action_proposals')->where('id', $pFresh['proposal_id'])->first();
        $this->assertSame('mcp', $dbProposal->source_channel);
        $this->assertSame($grant['binding_id'], $dbProposal->source_client_binding_id);

        auth()->forgetGuards();
        $statusRes = $this->withToken($token)->postJson('/mcp/erp', [
            'jsonrpc' => '2.0', 'id' => 27, 'method' => 'tools/call',
            'params' => ['name' => 'erp_proposal_status', 'arguments' => ['proposal_id' => $pFresh['proposal_id']]],
        ])->assertOk()->assertJsonPath('result.isError', false);
        $statusPayload = json_decode($statusRes->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $statusPayload['status']);
        $this->assertSame('purchase_order', $statusPayload['receipt']['resource_type']);
        $this->assertNotNull($statusPayload['receipt']['reference_no']);

        // M20: Zero model invocations and zero credit debits across all MCP prepare/status/denied calls
        if (Schema::hasTable('ai_model_invocations')) {
            $this->assertSame(0, DB::table('ai_model_invocations')->where('company_id', $this->companyA->id)->count());
        }
        if (Schema::hasTable('ai_credit_ledger')) {
            $this->assertSame(0, DB::table('ai_credit_ledger')->where('company_id', $this->companyA->id)->where('kind', 'usage_debit')->count());
        }
    }
}
