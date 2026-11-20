<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Services\AiFeatureGate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class BusinessWorkflowApiTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private User $actor;
    private int $branchId;
    private int $fyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'legal_name' => 'Business Workflow Fixture', 'code' => 'BWF-'.Str::random(8),
            'status' => 'active', 'country_code' => 'IN',
        ]);
        $this->branchId = $this->company->branches()->create([
            'name' => 'Workflow Branch', 'code' => 'BWF', 'is_active' => true,
        ])->id;
        $this->fyId = $this->company->fiscalYears()->create([
            'name' => 'FY 2026', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open',
        ])->id;
        $this->actor = User::create([
            'name' => 'Workflow Tester', 'email' => Str::uuid().'@example.test',
            'phone' => '7000000002', 'password' => bcrypt('fixture'),
            'role_id' => 1, 'is_active' => true, 'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $this->branchId,
        ]);
        config([
            'erp_ai.enabled' => true, 'erp_ai.conversation_enabled' => true,
            'ai_actions.foundation_enabled' => true,
            'ai_business_knowledge.enabled' => true, 'ai_business_knowledge.planner_enabled' => true,
        ]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, true);
        $this->actingAs($this->actor)->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
        ]);
    }

    public function test_draft_process_previews_but_cannot_be_started_or_posted(): void
    {
        $this->getJson(route('api.v1.ai.business-workflows.preview', ['processId' => 'process.purchase_to_pay.v1']))
            ->assertOk()->assertJsonPath('definition_status', 'draft')
            ->assertJsonPath('can_start', false)->assertJsonPath('writes_executed', false);
        $this->postJson(route('api.v1.ai.business-workflows.store'), [
            'process_id' => 'process.purchase_to_pay.v1',
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('ai_business_workflows')->count());
    }

    public function test_feature_flags_deny_even_read_access(): void
    {
        config(['ai_business_knowledge.planner_enabled' => false]);
        $this->getJson(route('api.v1.ai.business-workflows.index'))->assertForbidden();
    }

    public function test_another_company_workflow_is_not_visible(): void
    {
        $foreign = (string) Str::uuid();
        $this->seedRow($foreign, $this->company->id + 991, 'planned');
        $this->getJson(route('api.v1.ai.business-workflows.index'))
            ->assertOk()->assertJsonCount(0, 'workflows');
        $this->getJson(route('api.v1.ai.business-workflows.show', ['id' => $foreign]))
            ->assertForbidden();
        $this->getJson(route('api.v1.ai.business-workflows.metrics'))
            ->assertOk()->assertJsonPath('workflow_status_counts', []);
    }

    public function test_notes_revision_conflict_preserves_original_erp_records(): void
    {
        $id = (string) Str::uuid();
        $this->seedRow($id, $this->company->id, 'planned');
        $this->patchJson(route('api.v1.ai.business-workflows.correct', ['id' => $id]), [
            'expected_revision' => 1, 'patch' => ['notes' => 'Check supplier delivery'],
        ])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson(route('api.v1.ai.business-workflows.correct', ['id' => $id]), [
            'expected_revision' => 1, 'patch' => ['notes' => 'Overwrite after stale preview'],
        ])->assertConflict();
        $this->assertSame(0, DB::table('ai_action_proposals')->count());
        $this->assertSame(2, (int) DB::table('ai_business_workflows')->where('id', $id)->value('revision'));
    }

    private function seedRow(string $id, int $companyId, string $status): void
    {
        DB::table('ai_business_workflows')->insert([
            'id' => $id, 'company_id' => $companyId,
            'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId,
            'actor_id' => $this->actor->id, 'process_id' => 'process.purchase_to_pay.v1',
            'knowledge_hash' => str_repeat('a', 64), 'status' => $status,
            'revision' => 1, 'facts_json' => '{}',
            'expires_at' => now('UTC')->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
