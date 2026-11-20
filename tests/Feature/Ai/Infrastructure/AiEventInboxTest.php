<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Events\AiEventInboxService;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class AiEventInboxTest extends TestCase
{
    use UsesAiMysql;

    public function test_completed_stock_event_is_deduplicated_and_never_posts_erp_records(): void
    {
        config(['ai_events.internal_schedule_ingestion_enabled' => true]);
        $user = User::create(['name' => 'Event Admin', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'phone' => '1234567890', 'is_active' => true, 'is_deleted' => false]);
        $company = Company::create(['legal_name' => 'Events Co', 'trade_name' => 'Events',
            'code' => 'EVT-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']);
        $branch = $company->branches()->create(['name' => 'Event Branch', 'code' => 'EVT', 'is_active' => true])->id;
        $year = $company->fiscalYears()->create(['name' => 'Current FY', 'start_date' => '2026-04-01',
            'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false])->id;
        $company->users()->attach($user->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $company->id,
            'branch_id' => $branch, 'user_id' => $user->id]);
        $principal = new AiPrincipal(actorId: $user->id, companyId: $company->id,
            branchId: $branch, financialYearId: $year, requestId: 'event-fixture');
        $ruleId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        DB::table('ai_scheduled_rules')->insert([
            'id' => $ruleId, 'company_id' => $company->id, 'branch_id' => $branch,
            'financial_year_id' => $year, 'actor_id' => $user->id, 'name' => 'Test',
            'kind' => 'low_stock', 'revision' => 1, 'is_active' => true,
            'prepare_purchase_order' => false, 'inputs_json' => '{}',
            'interval_minutes' => 60, 'max_runs_per_day' => 2,
            'next_run_at' => now('UTC'), 'expires_at' => now('UTC')->addDay(),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('ai_schedule_runs')->insert([
            'id' => $runId, 'company_id' => $company->id, 'actor_id' => $user->id,
            'rule_id' => $ruleId, 'rule_revision' => 1, 'scheduled_for' => now('UTC'),
            'quota_date' => now('UTC')->toDateString(), 'status' => 'completed',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $service = app(AiEventInboxService::class);
        $alert = [['product_id' => 777]];
        $service->recordScheduledAlert($principal, $ruleId, $runId, $alert);
        $service->recordScheduledAlert($principal, $ruleId, $runId, $alert);
        $this->assertSame(1, DB::table('ai_event_inbox')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('ai_action_approvals')->count());
        $this->assertSame(0, DB::table('ai_action_executions')->count());
    }
}
