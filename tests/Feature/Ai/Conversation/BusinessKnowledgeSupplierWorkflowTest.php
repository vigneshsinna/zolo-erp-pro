<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Conversation;

use App\Ai\Services\AiFeatureGate;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class BusinessKnowledgeSupplierWorkflowTest extends TestCase
{
    use UsesAiMysql;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['legal_name' => 'Knowledge Supplier Fixture',
            'code' => 'KS-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN']);
        $branch = $company->branches()->create(['name' => 'Knowledge Branch', 'code' => 'KSB', 'is_active' => true]);
        $fy = $company->fiscalYears()->create(['name' => 'FY 2026',
            'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open']);
        $user = User::create(['name' => 'Knowledge User', 'email' => Str::uuid().'@example.test',
            'phone' => '7000000001', 'password' => bcrypt('fixture'), 'role_id' => 1,
            'is_active' => true, 'is_deleted' => false]);
        $company->users()->attach($user->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $company->id, 'user_id' => $user->id, 'branch_id' => $branch->id]);
        config([
            'erp_ai.enabled' => true, 'erp_ai.conversation_enabled' => true,
            'ai_actions.foundation_enabled' => true,
            'ai_business_knowledge.enabled' => true,
            'ai_business_knowledge.semantic_enabled' => true,
        ]);
        app(AiFeatureGate::class)->setCompanySetting($company->id, true, true);
        Http::preventStrayRequests();
        $this->actingAs($user)->withSession([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'financial_year_id' => $fy->id,
        ]);
    }

    public function test_ambiguous_party_resolves_to_supplier_without_creating_an_erp_record(): void
    {
        $thread = $this->postJson(route('ai.threads.store'))->assertCreated()->json('id');
        $ask = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'message' => 'Create a new party for buying linen',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();
        $ask->assertJsonPath('workflow.question_type', 'party_direction');
        $this->assertSame(0, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());

        $supplier = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'message' => 'Supplier',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();
        $supplier->assertJsonPath('workflow.action_key', 'setup.supplier.create.v1');
        $taskId = $supplier->json('workflow.task_id');
        $this->assertNotEmpty($taskId);

        $name = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'message' => 'New Trader',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertStringContainsString('phone', $name->json('workflow.message'));

        $skip = $this->postJson(route('ai.chat'), [
            'thread_id' => $thread, 'message' => 'skip all optional',
            'client_turn_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertStringContainsString('preview supplier', $skip->json('workflow.message'));
        $draft = json_decode(DB::table('ai_workflow_tasks')->where('id', $taskId)->value('slots_json'), true);
        $this->assertSame('New Trader', $draft['name']);
        $this->assertSame(['contact', 'address', 'tax'], $draft['_conversation_skips']);
        $this->assertSame(0, Supplier::where('name', 'New Trader')->count());
        Http::assertNothingSent();
    }
}
