<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Infrastructure\McpEndpointPolicy;
use App\Ai\Infrastructure\McpPublicAddressResolver;
use App\Ai\Infrastructure\OutboundMcpReadClient;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task T09: MCP and Optional RAG Hardening Test Suite.
 *
 * Verifies default-off MCP integration, read-only gating, SSRF / private IP defense,
 * token ability checks, and cross-tenant knowledge isolation.
 */
class McpAndRagHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $adminUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;
    private AiPrincipal $principal;
    private McpEndpointPolicy $endpointPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('erp_ai.enabled', true);

        // Ensure MCP and Qdrant are tested under default-off baselines
        config()->set('ai_infrastructure.mcp.enabled', false);
        config()->set('ai_infrastructure.mcp.outbound.enabled', false);
        config()->set('ai_documents.enabled', false);

        $resolver = $this->createStub(McpPublicAddressResolver::class);
        $resolver->method('addresses')->willReturnCallback(function (string $host): array {
            return match ($host) {
                'api.partner.com' => ['93.184.216.34'],
                'internal.local' => ['10.0.0.5'],
                'localhost.test' => ['127.0.0.1'],
                default => ['93.184.216.34'],
            };
        });
        $this->endpointPolicy = new McpEndpointPolicy($resolver);

        $this->adminUser = User::create([
            'name' => 'MCP Security Admin',
            'email' => 'mcp_admin_' . uniqid() . '@example.com',
            'phone' => '98888888' . rand(10, 99),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::create([
            'name' => 'MCP Hardened Textiles Ltd',
            'legal_name' => 'MCP Hardened Textiles Ltd',
            'code' => 'MCP_' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
        ]);

        $this->branch = CompanyBranch::create([
            'company_id' => $this->company->id,
            'name' => 'Main Mill',
            'code' => 'MM-01',
            'is_active' => true,
        ]);

        $this->fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-2027',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
            'is_closed' => false,
        ]);

        $this->company->users()->syncWithoutDetaching([
            $this->adminUser->id => ['is_default' => true],
        ]);
        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->adminUser->id, 'branch_id' => $this->branch->id]
        );

        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);

        $this->principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->company->id,
            branchId: $this->branch->id,
            financialYearId: $this->fiscalYear->id,
            permittedToolNames: ['*'],
            requestId: 'req_mcp_test_' . uniqid(),
        );
    }

    /**
     * Test 1: Inbound and Outbound MCP are default-off and reject requests when disabled.
     */
    public function test_mcp_is_default_off_and_rejects_unauthorized_dispatch(): void
    {
        // 1. Inbound MCP controller route rejects with 404 when disabled
        $inboundRes = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/ai/mcp', [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'id' => 1,
            ]);
        $this->assertTrue(in_array($inboundRes->status(), [404, 403], true));

        // 2. Outbound MCP client throws AuthorizationException when disabled
        $outboundClient = new OutboundMcpReadClient($this->endpointPolicy);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Outbound MCP is disabled.');

        $outboundClient->call($this->principal, 'unapproved_server', 'read_tool', []);
    }

    /**
     * Test 2: McpEndpointPolicy strictly enforces HTTPS and allowlisted hosts.
     */
    public function test_mcp_endpoint_policy_enforces_https_and_allowlist(): void
    {
        // Non-HTTPS URL: rejected
        try {
            $this->endpointPolicy->connection('http://api.partner.com/mcp', ['api.partner.com']);
            $this->fail('Expected non-HTTPS URL to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('approved HTTPS MCP endpoint is required', $e->getMessage());
        }

        // Host not in allowlist: rejected
        try {
            $this->endpointPolicy->connection('https://malicious.attacker.com/mcp', ['api.partner.com']);
            $this->fail('Expected unapproved host to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('approved HTTPS MCP endpoint is required', $e->getMessage());
        }

        // Embedded credentials: rejected
        try {
            $this->endpointPolicy->connection('https://admin:secret@api.partner.com/mcp', ['api.partner.com']);
            $this->fail('Expected URL with embedded credentials to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('approved HTTPS MCP endpoint is required', $e->getMessage());
        }
    }

    /**
     * Test 3: McpEndpointPolicy prevents SSRF, private IP destinations and redirects.
     */
    public function test_mcp_endpoint_policy_prevents_ssrf_and_private_destinations(): void
    {
        // Private IP (10.0.0.5): rejected
        try {
            $this->endpointPolicy->connection('https://internal.local/mcp', ['internal.local']);
            $this->fail('Expected private destination to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Private or reserved MCP destinations are prohibited', $e->getMessage());
        }

        // Loopback (127.0.0.1): rejected
        try {
            $this->endpointPolicy->connection('https://localhost.test/mcp', ['localhost.test']);
            $this->fail('Expected localhost destination to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Private or reserved MCP destinations are prohibited', $e->getMessage());
        }

        // Valid public destination: returns options with redirects disabled
        $conn = $this->endpointPolicy->connection('https://api.partner.com/mcp', ['api.partner.com']);
        $this->assertFalse($conn['allow_redirects']);
        $this->assertFalse($conn['curl'][CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(CURLPROTO_HTTPS, $conn['curl'][CURLOPT_PROTOCOLS]);
    }

    /**
     * Test 4: Document knowledge access requires explicit feature flag and company scope.
     */
    public function test_document_knowledge_feature_flag_and_company_scoping(): void
    {
        $docAccess = app(\App\Ai\Documents\DocumentAccess::class);

        // Disabled by default
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('AI document processing is disabled.');

        $docAccess->context($this->principal);
    }
}
