<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Services\AiFeatureGate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class AiOperationsPageTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private Company $company;
    private int $branchId;
    private int $yearId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp_ai.enabled' => true, 'ai_actions.foundation_enabled' => true,
            'ai_infrastructure.scheduling.enabled' => true]);
        $this->actor = User::create(['name' => 'AI Operations Admin', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'phone' => '1234567890', 'is_active' => true, 'is_deleted' => false]);
        $this->company = Company::create(['legal_name' => 'AI Operations Co', 'trade_name' => 'Operations Co',
            'code' => 'AIO-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']);
        $this->branchId = $this->company->branches()->create(['name' => 'Operations Branch', 'code' => 'AIO', 'is_active' => true])->id;
        $this->yearId = $this->company->fiscalYears()->create(['name' => 'Current FY', 'start_date' => '2026-04-01',
            'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false])->id;
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $this->company->id,
            'user_id' => $this->actor->id, 'branch_id' => $this->branchId]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
        $this->actingAs($this->actor)->withSession(['company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->yearId]);
    }

    public function test_operations_home_is_read_only_and_links_to_existing_workspaces(): void
    {
        $before = DB::table('ai_action_proposals')->count();
        $this->get(route('ai.operations'))->assertOk()
            ->assertSee('AI Operations')
            ->assertSee('Needs Review')
            ->assertSee('Ask Zolo')
            ->assertSee('Scheduled Work')
            ->assertSee(route('ai.workspace'))
            ->assertSee(route('ai.actions.review'), false);
        $this->assertSame($before, DB::table('ai_action_proposals')->count());
    }

    public function test_company_scoped_settings_can_disable_operations_without_executing_actions(): void
    {
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, false, true);
        $this->get(route('ai.operations'))->assertOk()
            ->assertSee('AI is unavailable for this company')
            ->assertDontSee('Open review queue');
        $this->assertSame(0, DB::table('ai_action_executions')->where('company_id', $this->company->id)->count());
    }
}
