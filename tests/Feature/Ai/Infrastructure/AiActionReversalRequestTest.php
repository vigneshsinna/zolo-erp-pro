<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Services\AiActionReversalRequestService;
use App\Ai\Services\AiFeatureGate;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class AiActionReversalRequestTest extends TestCase
{
    use UsesAiMysql;

    private CompanyContext $context;
    private User $actor;
    private string $executionId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp_ai.enabled' => true, 'ai_actions.foundation_enabled' => true]);
        $this->actor = User::create(['name' => 'Reversal Approver', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'phone' => '1234567890',
            'is_active' => true, 'is_deleted' => false]);
        $company = Company::create(['legal_name' => 'Reversal Test Co', 'trade_name' => 'Reversal',
            'code' => 'REV-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']);
        $branch = $company->branches()->create(['name' => 'Reversal Branch', 'code' => 'REV', 'is_active' => true])->id;
        $fy = $company->fiscalYears()->create(['name' => 'Current FY', 'start_date' => '2026-04-01',
            'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false])->id;
        $company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $company->id,
            'user_id' => $this->actor->id, 'branch_id' => $branch]);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, false);
        $this->context = new CompanyContext($company->id, $branch, $fy);
        $this->actingAs($this->actor)->withSession(['company_id' => $company->id,
            'branch_id' => $branch, 'financial_year_id' => $fy]);

        $proposalId = (string) Str::uuid();
        $this->executionId = (string) Str::uuid();
        DB::table('ai_action_proposals')->insert([
            'id' => $proposalId, 'actor_id' => $this->actor->id, 'company_id' => $company->id,
            'branch_id' => $branch, 'financial_year_id' => $fy,
            'action_type' => 'purchase_order.create.v1', 'action_key' => 'purchase_order.create.v1',
            'action_title' => 'commercial', 'risk_tier' => 'r2_operational', 'version' => 1,
            'payload_json' => '{}', 'preview_json' => '{}',
            'payload_hash' => str_repeat('a', 64), 'source_state_hash' => str_repeat('b', 64),
            'status' => 'completed', 'expires_at' => now('UTC')->addHour(),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('ai_action_executions')->insert([
            'id' => $this->executionId, 'proposal_id' => $proposalId,
            'company_id' => $company->id, 'executor_id' => $this->actor->id,
            'idempotency_key' => 'ai-action:'.$proposalId.':v1',
            'version' => 1, 'status' => 'completed',
            'result_json' => json_encode(['resource_type' => 'purchase_order',
                'resource_id' => 9999, 'status' => 'completed']),
            'executed_at' => now('UTC'),
        ]);
    }

    public function test_reversal_review_is_idempotent_without_business_writes(): void
    {
        $service = app(AiActionReversalRequestService::class);
        $purchases = DB::table('purchases')->where('company_id', $this->context->companyId)->count();
        $stock = DB::table('stock_movements')->where('company_id', $this->context->companyId)->count();
        $first = $service->request($this->context, $this->actor->id, $this->executionId,
            'Please review this completed purchase order.');
        $second = $service->request($this->context, $this->actor->id, $this->executionId,
            'Duplicate review request should not create a second.');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('pending_manual_review', $first['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['execution_receipt_hash']);
        $this->assertSame(1, DB::table('ai_action_reversal_requests')
            ->where('company_id', $this->context->companyId)->count());
        $this->assertSame($purchases, DB::table('purchases')->where('company_id', $this->context->companyId)->count());
        $this->assertSame($stock, DB::table('stock_movements')->where('company_id', $this->context->companyId)->count());
        $this->assertSame('completed', DB::table('ai_action_executions')
            ->where('id', $this->executionId)->value('status'));
    }

    public function test_other_branch_cannot_request_reversal(): void
    {
        $company = Company::findOrFail($this->context->companyId);
        $another = $company->branches()->create(['name' => 'Other Branch', 'code' => 'REV2',
            'is_active' => true])->id;
        DB::table('company_user_branches')->insert(['company_id' => $company->id,
            'user_id' => $this->actor->id, 'branch_id' => $another]);
        $otherContext = new CompanyContext($company->id, $another, $this->context->financialYearId);
        $this->expectException(AuthorizationException::class);
        app(AiActionReversalRequestService::class)->request(
            $otherContext, $this->actor->id, $this->executionId, 'Other branch execution must fail.'
        );
    }
}
