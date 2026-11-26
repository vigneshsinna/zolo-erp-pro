<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AutonomyPolicyService;
use App\Models\Company;
use App\Models\User;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class AiAutonomyPolicyTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private Company $company;
    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp_ai.enabled' => true, 'ai_actions.foundation_enabled' => true,
            'ai_autonomy.policy_management_enabled' => true]);
        $this->actor = User::create(['name' => 'Policy Owner', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'phone' => '1234567890', 'is_active' => true, 'is_deleted' => false]);
        $this->company = Company::create(['legal_name' => 'Autonomy Co', 'trade_name' => 'Autonomy',
            'code' => 'AUT-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']);
        $branchId = $this->company->branches()->create(['name' => 'Autonomy Branch', 'code' => 'AUT', 'is_active' => true])->id;
        $fyId = $this->company->fiscalYears()->create(['name' => 'Current FY', 'start_date' => '2026-04-01',
            'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false])->id;
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $this->company->id,
            'user_id' => $this->actor->id, 'branch_id' => $branchId]);
        $this->context = new CompanyContext($this->company->id, $branchId, $fyId);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
        $this->actingAs($this->actor)->withSession(['company_id' => $this->company->id,
            'branch_id' => $branchId, 'financial_year_id' => $fyId]);
    }

    public function test_default_policy_and_saved_auto_preference_cannot_authorize_execution(): void
    {
        $service = app(AutonomyPolicyService::class);
        $default = $service->evaluate($this->context, 'purchase_order.create.v1');
        $this->assertSame('draft', $default['requested_level']);
        $this->assertFalse($default['automatic_execution_allowed']);
        $saved = $service->save($this->context, $this->actor->id, 'purchase_order.create.v1', [
            'requested_level' => 'auto_under_limits', 'max_amount_rupees' => 10000,
            'daily_action_limit' => 2, 'expected_version' => 0,
        ]);
        $this->assertSame(1000000, $saved['max_amount_minor']);
        $this->assertSame(1, $saved['version']);
        $this->assertFalse($service->evaluate($this->context, 'purchase_order.create.v1')['automatic_execution_allowed']);
        $this->assertSame(1, DB::table('ai_autonomy_policy_events')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('ai_action_approvals')->where('proposal_id', 'nonexistent')->count());
    }

    public function test_financial_auto_preference_and_stale_update_are_rejected(): void
    {
        $service = app(AutonomyPolicyService::class);
        $this->expectException(ValidationException::class);
        $service->save($this->context, $this->actor->id, 'purchase_bill.create.v1', [
            'requested_level' => 'auto_under_limits', 'max_amount_rupees' => 10000,
            'daily_action_limit' => 2, 'expected_version' => 0,
        ]);
    }

    public function test_stale_version_cannot_overwrite_an_existing_policy(): void
    {
        $service = app(AutonomyPolicyService::class);
        $input = ['requested_level' => 'draft', 'max_amount_rupees' => 0,
            'daily_action_limit' => 1, 'expected_version' => 0];
        $service->save($this->context, $this->actor->id, 'purchase_order.create.v1', $input);
        $this->expectException(ValidationException::class);
        $service->save($this->context, $this->actor->id, 'purchase_order.create.v1', $input);
    }

    public function test_policy_screen_and_updates_respect_disable_switch(): void
    {
        $this->get(route('ai.autonomy.index'))->assertOk()->assertSee('Human approval is still mandatory');
        config(['ai_autonomy.policy_management_enabled' => false]);
        $this->get(route('ai.autonomy.index'))->assertForbidden();
    }
}
