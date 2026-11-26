<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task T08: Edge Infrastructure, Health Probes and Origin Protection Test Suite.
 */
class EdgeSecurityAndHealthTest extends TestCase
{
    use DatabaseTransactions;

    private User $adminUser;
    private Company $company;
    private CompanyBranch $branch;
    private FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai_actions.foundation_enabled', true);
        config()->set('erp_ai.enabled', true);

        $this->adminUser = User::create([
            'name' => 'Edge Security Admin',
            'email' => 'edge_admin_' . uniqid() . '@example.com',
            'phone' => '98888888' . rand(10, 99),
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->company = Company::create([
            'name' => 'Edge Security Textiles Ltd',
            'legal_name' => 'Edge Security Textiles Ltd',
            'code' => 'EDGE_' . uniqid(),
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

        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
    }

    /**
     * Test 1: Public Health & Ping endpoints return 200 OK.
     */
    public function test_public_health_and_ping_endpoints_respond_with_ok(): void
    {
        $ping = $this->getJson('/api/v1/ping');
        $ping->assertOk()
            ->assertJsonPath('status', 'OK');

        $health = $this->getJson('/api/v1/health');
        $health->assertOk()
            ->assertJsonPath('status', 'OK');
    }

    /**
     * Test 2: AI responses include security headers and correlation tracking.
     */
    public function test_ai_responses_include_security_and_correlation_headers(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/ai/credits/balance');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Request-ID');
        $response->assertHeader('X-Correlation-ID');
    }

    /**
     * Test 3: Global emergency foundation killswitch halts all AI processing with 403.
     */
    public function test_foundation_killswitch_denies_ai_requests(): void
    {
        config()->set('ai_actions.foundation_enabled', false);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/ai/workflow-tasks', [
                'action_key' => 'purchase_bill.create.v1',
                'slots' => [],
            ]);

        $response->assertStatus(403);
    }
}
