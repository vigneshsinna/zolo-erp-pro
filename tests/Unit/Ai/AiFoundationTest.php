<?php

namespace Tests\Unit\Ai;

use App\Ai\Contracts\AiPrincipalAuthorizerInterface;
use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\AiAuditRecorder;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalAuthorizer;
use App\Ai\Services\AiToolManifest;
use App\Ai\Services\ErpToolExecutor;
use PHPUnit\Framework\TestCase;

class AiFoundationTest extends TestCase
{
    public function test_ai_principal_is_immutable_and_holds_tenancy_context(): void
    {
        $principal = new AiPrincipal(
            actorId: 42,
            companyId: 14,
            branchId: 1,
            financialYearId: 2026,
            permittedToolNames: ['receivables.outstanding.v1'],
            requestId: 'req-uuid-1234',
            effectiveRole: 'accountant'
        );

        $this->assertSame(42, $principal->actorId);
        $this->assertSame(14, $principal->companyId);
        $this->assertSame(1, $principal->branchId);
        $this->assertSame(2026, $principal->financialYearId);
        $this->assertSame(['receivables.outstanding.v1'], $principal->permittedToolNames);
        $this->assertSame('req-uuid-1234', $principal->requestId);
        $this->assertSame('accountant', $principal->effectiveRole);
        $this->assertTrue($principal->isActive);
    }

    public function test_principal_authorizer_denies_unpermitted_tools(): void
    {
        $authorizer = new AiPrincipalAuthorizer();
        $principal = new AiPrincipal(
            actorId: 42,
            companyId: 14,
            branchId: 1,
            financialYearId: 2026,
            permittedToolNames: ['receivables.outstanding.v1']
        );

        $this->assertTrue($authorizer->authorize($principal, 'receivables.outstanding.v1'));
        $this->assertFalse($authorizer->authorize($principal, 'unauthorized.secret.tool'));
    }

    public function test_erp_tool_result_factory_methods(): void
    {
        $okResult = ErpToolResult::ok(
            toolKey: 'receivables.outstanding.v1',
            scope: ['company_id' => 14],
            data: [['customer' => 'Customer A', 'amount' => '100.00']],
            sourceRefs: ['/report/customer_report'],
            warnings: [],
            asOf: '2026-11-10'
        );

        $this->assertSame('ok', $okResult->status);
        $this->assertSame(1, $okResult->rowCount);
        $this->assertTrue($okResult->reconciled);
        $this->assertSame('2026-11-10', $okResult->asOf);

        $deniedResult = ErpToolResult::denied('secret.tool', 'Access Denied', 'AI_TOOL_FORBIDDEN');
        $this->assertSame('denied', $deniedResult->status);
        $this->assertSame('AI_TOOL_FORBIDDEN', $deniedResult->errorCode);
        $this->assertSame('Access Denied', $deniedResult->errorMessage);
    }

    public function test_tool_manifest_registers_and_retrieves_handlers(): void
    {
        $manifest = new AiToolManifest();

        $handler = new class implements ErpToolHandlerInterface {
            public function key(): string { return 'test.tool.v1'; }
            public function description(): string { return 'Test tool'; }
            public function risk(): string { return 'read_only'; }
            public function schema(): array { return ['type' => 'object']; }
            public function requiredPermissions(): array { return ['ai.use']; }
            public function authorize(AiPrincipal $principal, array $input): bool { return true; }
            public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult {
                return ErpToolResult::ok('test.tool.v1', ['company_id' => $principal->companyId], ['tested' => true]);
            }
        };

        $manifest->register($handler);

        $this->assertTrue($manifest->has('test.tool.v1'));
        $this->assertSame($handler, $manifest->get('test.tool.v1'));
        $this->assertFalse($manifest->has('nonexistent.tool'));
    }

    public function test_erp_tool_executor_enforces_authorization(): void
    {
        $manifest = new AiToolManifest();
        $authorizer = new AiPrincipalAuthorizer();
        $auditRecorder = new class extends AiAuditRecorder {
            public array $logs = [];
            public function recordInvocation(string $runId, string $toolKey, array $input, ErpToolResult $result, int $durationMs): void {
                $this->logs[] = compact('runId', 'toolKey', 'result');
            }
        };

        $handler = new class implements ErpToolHandlerInterface {
            public function key(): string { return 'receivables.outstanding.v1'; }
            public function description(): string { return 'Receivables tool'; }
            public function risk(): string { return 'read_only'; }
            public function schema(): array {
                return [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer']
                    ],
                ];
            }
            public function requiredPermissions(): array { return []; }
            public function authorize(AiPrincipal $principal, array $input): bool { return true; }
            public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult {
                return ErpToolResult::ok('receivables.outstanding.v1', ['company_id' => $principal->companyId], ['total' => '500.00']);
            }
        };

        $manifest->register($handler);
        $executor = new ErpToolExecutor($manifest, $authorizer, $auditRecorder);

        // Permitted principal
        $authorizedPrincipal = new AiPrincipal(
            actorId: 1,
            companyId: 14,
            branchId: 1,
            financialYearId: 2026,
            permittedToolNames: ['receivables.outstanding.v1']
        );

        $result = $executor->execute('run-1', $authorizedPrincipal, 'receivables.outstanding.v1', ['limit' => 5]);
        $this->assertSame('ok', $result->status);
        $this->assertSame('500.00', $result->data['total']);
        $this->assertCount(1, $auditRecorder->logs);

        // Forbidden principal
        $unauthorizedPrincipal = new AiPrincipal(
            actorId: 2,
            companyId: 14,
            branchId: 1,
            financialYearId: 2026,
            permittedToolNames: []
        );

        $deniedResult = $executor->execute('run-2', $unauthorizedPrincipal, 'receivables.outstanding.v1', ['limit' => 5]);
        $this->assertSame('denied', $deniedResult->status);
        $this->assertSame('AI_TOOL_FORBIDDEN', $deniedResult->errorCode);
    }

    public function test_audit_recorder_redacts_sensitive_fields(): void
    {
        $recorder = new AiAuditRecorder();

        $sensitiveInput = [
            'password' => 'supersecret',
            'api_key' => 'sk-live-12345678',
            'token' => 'bearer-abc',
            'credit_card' => '4111111111111234',
            'customer_name' => 'Acme Corp',
            'limit' => 10,
        ];

        $redacted = $recorder->redact($sensitiveInput);

        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['api_key']);
        $this->assertSame('[REDACTED]', $redacted['token']);
        $this->assertSame('[REDACTED]', $redacted['credit_card']);
        $this->assertSame('Acme Corp', $redacted['customer_name']);
        $this->assertSame(10, $redacted['limit']);
    }

    public function test_feature_gate_evaluates_emergency_killswitch(): void
    {
        $gate = new AiFeatureGate(enabledGlobally: false, readOnlyGlobally: true);
        $this->assertFalse($gate->isEnabledForCompany(14));

        $enabledGate = new AiFeatureGate(enabledGlobally: true, readOnlyGlobally: true);
        $this->assertFalse($enabledGate->isEnabledForCompany(14));
        $this->assertTrue($enabledGate->isReadOnly(14));
    }
}

