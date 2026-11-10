<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agents\ErpCopilot;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\ErpToolExecutor;
use App\Ai\Tools\CustomerOutstanding;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use Tests\Support\UsesAiMysql;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ReceivablesVerticalSliceTest extends TestCase
{
    use UsesAiMysql;

    protected User $user;
    protected Company $company;
    protected int $branchId;
    protected int $financialYearId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('role_id', 1)->first() ?? User::create([
            'name' => 'AI Test User',
            'email' => 'ai_test@example.com',
            'phone' => '9999999999',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->actingAs($this->user);

        $this->company = Company::where('status', 'active')->first() ?? Company::first();

        if (!$this->company->users()->where('users.id', $this->user->id)->exists()) {
            $this->company->users()->attach($this->user->id, ['is_default' => true]);
        }

        $branch = $this->company->branches()->first()
            ?? $this->company->branches()->create(['code' => 'MAIN', 'name' => 'Main Branch', 'is_active' => true]);
        $this->branchId = $branch->id;

        DB::table('company_user_branches')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $branch->id],
            ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'branch_id' => $branch->id]
        );

        $year = FiscalYear::where('company_id', $this->company->id)->first()
            ?? FiscalYear::create([
                'company_id' => $this->company->id,
                'name' => 'FY 2026',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'status' => 'open',
            ]);
        $this->financialYearId = $year->id;

        $this->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->financialYearId,
        ]);

        config(['erp_ai.enabled' => true]);
        app(\App\Ai\Services\AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);
    }

    public function test_erp_copilot_registers_only_authorized_tools(): void
    {
        $authorizedPrincipal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: ['receivables.outstanding.v1']
        );

        $copilot = new ErpCopilot($authorizedPrincipal);
        $tools = iterator_to_array($copilot->tools());

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(CustomerOutstanding::class, $tools[0]);

        // Principal without permissions
        $unauthorizedPrincipal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: []
        );

        $emptyCopilot = new ErpCopilot($unauthorizedPrincipal);
        $emptyTools = iterator_to_array($emptyCopilot->tools());

        $this->assertCount(0, $emptyTools);
    }

    public function test_customer_outstanding_tool_executes_deterministically(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: ['receivables.outstanding.v1'],
            requestId: 'test-req-001'
        );

        $tool = new CustomerOutstanding($principal);
        $request = new Request(['limit' => 5]);

        $jsonOutput = (string) $tool->handle($request);
        $response = json_decode($jsonOutput, true);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('receivables.outstanding.v1', $response['tool']);
        $this->assertSame($this->company->id, $response['scope']['company_id']);
        $this->assertArrayHasKey('total_outstanding', $response['data']);
        $this->assertArrayHasKey('top_customers', $response['data']);
        $this->assertArrayHasKey('is_reconciled', $response['reconciliation']);
        $this->assertNotEmpty($response['source_refs']);
    }

    public function test_customer_outstanding_tool_denies_unauthorized_principal(): void
    {
        $restrictedUser = User::create([
            'name' => 'Restricted Viewer',
            'email' => 'restricted_' . uniqid() . '@example.com',
            'phone' => '8888888888',
            'password' => bcrypt('secret'),
            'role_id' => 99, // Non-admin role without reporting permissions
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company->users()->attach($restrictedUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $restrictedUser->id,
            'branch_id' => $this->branchId,
        ]);
        $this->actingAs($restrictedUser);

        $unauthorizedPrincipal = new AiPrincipal(
            actorId: $restrictedUser->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: []
        );

        $tool = new CustomerOutstanding($unauthorizedPrincipal);
        $request = new Request(['limit' => 5]);

        $jsonOutput = (string) $tool->handle($request);
        $response = json_decode($jsonOutput, true);

        $this->assertSame('denied', $response['status']);
        $this->assertSame('AI_TOOL_FORBIDDEN', $response['error_code']);
    }

    public function test_customer_outstanding_clamps_as_of_outside_financial_year(): void
    {
        $principal = new AiPrincipal(
            actorId: $this->user->id,
            companyId: $this->company->id,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
            permittedToolNames: ['receivables.outstanding.v1'],
            requestId: 'test-req-002'
        );

        $tool = new CustomerOutstanding($principal);
        // Pass a date outside the financial year (e.g. 2025-08-27 when FY is 2026-04-01 to 2027-03-31)
        $request = new Request(['as_of' => '2025-08-27', 'limit' => 5]);

        $jsonOutput = (string) $tool->handle($request);
        $response = json_decode($jsonOutput, true);

        $this->assertSame('ok', $response['status']);
        $this->assertNotEmpty($response['warnings']);
        $this->assertStringContainsString('falls outside the active financial year', $response['warnings'][0]);
    }
}

