<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalAuthorizer;
use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\ErpToolExecutor;
use App\Ai\Tools\CustomerOutstanding;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ReceivablesSecurityAndAccuracyTest extends TestCase
{
    use DatabaseTransactions;

    protected Company $companyA;
    protected Company $companyB;
    protected User $adminUser;
    protected User $restrictedUser;
    protected int $branchAId;
    protected int $branchBId;
    protected int $fyAId;
    protected int $fyBId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Company A
        $this->companyA = Company::where('status', 'active')->first() ?? Company::create([
            'name' => 'Security Test Company A',
            'code' => 'COMP-A-' . uniqid(),
            'status' => 'active',
        ]);

        $branchA = $this->companyA->branches()->first() ?? $this->companyA->branches()->create([
            'name' => 'Branch A',
            'code' => 'BR-A-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branchAId = $branchA->id;

        $fyA = FiscalYear::where('company_id', $this->companyA->id)->first() ?? FiscalYear::create([
            'company_id' => $this->companyA->id,
            'name' => 'FY 2026 A',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $this->fyAId = $fyA->id;

        // 2. Setup Company B
        $this->companyB = Company::create([
            'legal_name' => 'Security Test Company B',
            'trade_name' => 'Test Company B',
            'code' => 'COMP-B-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);

        $branchB = $this->companyB->branches()->create([
            'name' => 'Branch B',
            'code' => 'BR-B-' . uniqid(),
            'is_active' => true,
        ]);
        $this->branchBId = $branchB->id;

        $fyB = FiscalYear::create([
            'company_id' => $this->companyB->id,
            'name' => 'FY 2026 B',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ]);
        $this->fyBId = $fyB->id;

        // 3. Setup Users
        $this->adminUser = User::create([
            'name' => 'Security Admin A',
            'email' => 'admin_a_' . uniqid() . '@example.com',
            'phone' => '9999911111',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->restrictedUser = User::create([
            'name' => 'Security Restricted A',
            'email' => 'restricted_a_' . uniqid() . '@example.com',
            'phone' => '9999922222',
            'password' => bcrypt('secret'),
            'role_id' => 99,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        // Attach users to Company A only
        $this->companyA->users()->attach($this->adminUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminUser->id,
            'branch_id' => $this->branchAId,
        ]);

        $this->companyA->users()->attach($this->restrictedUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->companyA->id,
            'user_id' => $this->restrictedUser->id,
            'branch_id' => $this->branchAId,
        ]);

        // Enable AI for Company A and B
        config(['erp_ai.enabled' => true]);
        app(AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, true);
        app(AiFeatureGate::class)->setCompanySetting($this->companyB->id, true, true);
    }

    public function test_cross_company_isolation_blocks_user_from_accessing_foreign_company(): void
    {
        // Admin User has membership in Company A, NOT Company B
        // Attempting to construct/refresh principal for Company B must deactivate the principal
        $authorizer = app(AiPrincipalAuthorizer::class);

        $foreignPrincipal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->companyB->id,
            branchId: $this->branchBId,
            financialYearId: $this->fyBId,
            permittedToolNames: ['receivables.outstanding.v1']
        );

        $refreshed = $authorizer->refresh($foreignPrincipal);

        $this->assertFalse($refreshed->isActive, 'Cross-company access must result in an inactive principal.');
        $this->assertEmpty($refreshed->permittedToolNames, 'Cross-company principal must lose all tool permissions.');

        // Executing tool with foreign principal must be denied
        $executor = app(ErpToolExecutor::class);
        $result = $executor->execute('run-cross-tenant-1', $foreignPrincipal, 'receivables.outstanding.v1', ['limit' => 5]);

        $this->assertSame('denied', $result->status);
        $this->assertSame('AI_PRINCIPAL_INACTIVE', $result->errorCode);
    }

    public function test_unpermitted_role_is_denied_receivables_tool(): void
    {
        $factory = app(AiPrincipalFactory::class);
        $principal = $factory->createForUser(
            userId: $this->restrictedUser->id,
            companyId: $this->companyA->id,
            branchId: $this->branchAId,
            financialYearId: $this->fyAId
        );

        $this->assertNotContains('receivables.outstanding.v1', $principal->permittedToolNames);

        $executor = app(ErpToolExecutor::class);
        $result = $executor->execute('run-unpermitted-1', $principal, 'receivables.outstanding.v1', ['limit' => 5]);

        $this->assertSame('denied', $result->status);
        $this->assertSame('AI_TOOL_FORBIDDEN', $result->errorCode);
    }

    public function test_company_level_killswitch_blocks_execution(): void
    {
        // Disable AI for Company A
        app(AiFeatureGate::class)->setCompanySetting($this->companyA->id, false, true);

        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->companyA->id,
            branchId: $this->branchAId,
            financialYearId: $this->fyAId,
            permittedToolNames: ['receivables.outstanding.v1']
        );

        $executor = app(ErpToolExecutor::class);
        $result = $executor->execute('run-killswitch-1', $principal, 'receivables.outstanding.v1', ['limit' => 5]);

        $this->assertSame('denied', $result->status);
        $this->assertSame('AI_FEATURE_DISABLED', $result->errorCode);
    }

    public function test_input_schema_validation_rejects_out_of_bounds_and_unexpected_inputs(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->companyA->id,
            branchId: $this->branchAId,
            financialYearId: $this->fyAId,
            permittedToolNames: ['receivables.outstanding.v1']
        );

        $executor = app(ErpToolExecutor::class);

        // 1. Limit exceeding maximum (20)
        $resultExcess = $executor->execute('run-schema-1', $principal, 'receivables.outstanding.v1', ['limit' => 50]);
        $this->assertSame('denied', $resultExcess->status);
        $this->assertSame('AI_INPUT_VALIDATION_FAILED', $resultExcess->errorCode);

        // 2. Limit below minimum (1)
        $resultUnder = $executor->execute('run-schema-2', $principal, 'receivables.outstanding.v1', ['limit' => 0]);
        $this->assertSame('denied', $resultUnder->status);
        $this->assertSame('AI_INPUT_VALIDATION_FAILED', $resultUnder->errorCode);

        // 3. Unexpected parameter
        $resultUnexpected = $executor->execute('run-schema-3', $principal, 'receivables.outstanding.v1', [
            'limit' => 5,
            'unexpected_field' => 'injection_attempt',
        ]);
        $this->assertSame('denied', $resultUnexpected->status);
        $this->assertSame('AI_INPUT_VALIDATION_FAILED', $resultUnexpected->errorCode);
    }

    public function test_audit_log_persists_redacted_records_in_database(): void
    {
        $runId = 'test-audit-run-' . uniqid();
        $principal = new AiPrincipal(
            actorId: $this->adminUser->id,
            companyId: $this->companyA->id,
            branchId: $this->branchAId,
            financialYearId: $this->fyAId,
            permittedToolNames: ['receivables.outstanding.v1'],
            requestId: $runId
        );

        $tool = new CustomerOutstanding($principal);
        $request = new Request(['limit' => 5]);
        $tool->handle($request);

        // Assert audit log row was persisted in ai_tool_invocations
        $log = DB::table('ai_tool_invocations')->where('run_id', $runId)->first();

        $this->assertNotNull($log, 'Tool execution must be recorded in ai_tool_invocations table.');
        $this->assertSame('receivables.outstanding.v1', $log->tool_key);
        $this->assertSame('ok', $log->status);
        $this->assertSame('authorized', $log->authorization_decision);
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        $this->assertNotNull($log->scope_hash);

        $inputDecoded = json_decode($log->input_redacted, true);
        $this->assertSame(5, $inputDecoded['limit']);
    }
}
