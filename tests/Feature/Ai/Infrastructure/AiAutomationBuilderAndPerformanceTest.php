<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Scheduling\AiAutomationPerformanceService;
use App\Ai\Scheduling\AiAutomationReviewService;
use App\Ai\Scheduling\ScheduledRuleService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class AiAutomationBuilderAndPerformanceTest extends TestCase
{
    use UsesAiMysql;

    private User $actor;
    private Company $company;
    private int $branch;
    private int $year;
    private Warehouse $warehouse;
    private Product $product;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false,
            'ai_actions.foundation_enabled' => true, 'ai_infrastructure.scheduling.enabled' => true,
            'ai_infrastructure.queue_connection' => 'database']);
        $this->actor = User::create(['name' => 'Builder QA', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'phone' => '1234567890',
            'is_active' => true, 'is_deleted' => false]);
        $this->company = Company::create(['legal_name' => 'Builder QA', 'trade_name' => 'Builder QA',
            'code' => 'BUILDER-'.Str::random(8), 'status' => 'active',
            'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']);
        $this->branch = $this->company->branches()->create([
            'name' => 'QA Main', 'code' => 'QA', 'is_active' => true])->id;
        $this->year = $this->company->fiscalYears()->create([
            'name' => 'FY 2026', 'start_date' => '2026-04-01',
            'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false])->id;
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $this->company->id,
            'user_id' => $this->actor->id, 'branch_id' => $this->branch]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
        $this->warehouse = Warehouse::forceCreate(['name' => 'QA Warehouse', 'address' => 'Fixture',
            'company_id' => $this->company->id, 'branch_id' => $this->branch, 'is_active' => true]);
        $unit = Unit::forceCreate(['unit_code' => 'U'.strtoupper(Str::random(6)),
            'unit_name' => 'Pieces', 'company_id' => $this->company->id, 'is_active' => true]);
        $category = Category::forceCreate(['name' => 'Fixture', 'company_id' => $this->company->id, 'is_active' => true]);
        $this->product = Product::forceCreate(['name' => 'QA Cotton Product',
            'code' => 'QP-'.strtoupper(Str::random(8)), 'type' => 'standard',
            'barcode_symbology' => 'code128', 'company_id' => $this->company->id,
            'category_id' => $category->id, 'unit_id' => $unit->id,
            'purchase_unit_id' => $unit->id, 'sale_unit_id' => $unit->id,
            'cost' => 100, 'price' => 120, 'qty' => 0, 'is_active' => true]);
        $this->supplier = Supplier::forceCreate(['name' => 'QA Supplier', 'company_name' => 'QA Supplier',
            'phone_number' => '1234567890', 'address' => 'Fixture', 'city' => 'QA',
            'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->actor)->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch, 'financial_year_id' => $this->year]);
    }

    public function test_builder_catalog_is_tenant_scoped_and_shows_supported_controls(): void
    {
        $this->get(route('ai.builder.index'))->assertOk()
            ->assertSee('Automation Builder')->assertSee('Create automation')
            ->assertSee('Minimum available quantity');
        $this->getJson(route('ai.builder.catalog', ['type' => 'product', 'q' => 'QA Cotton']))
            ->assertOk()->assertJsonPath('items.0.id', $this->product->id);
        $this->getJson(route('ai.builder.catalog', ['type' => 'warehouse', 'q' => 'QA Warehouse']))
            ->assertOk()->assertJsonPath('items.0.id', $this->warehouse->id);
        $this->getJson(route('ai.builder.catalog', ['type' => 'supplier', 'q' => 'QA Supplier']))
            ->assertOk()->assertJsonPath('items.0.id', $this->supplier->id);
        $foreignBranch = $this->company->branches()->create([
            'name' => 'Foreign Branch', 'code' => 'FRN', 'is_active' => true])->id;
        Warehouse::forceCreate(['name' => 'Foreign Warehouse', 'address' => 'Fixture',
            'company_id' => $this->company->id, 'branch_id' => $foreignBranch, 'is_active' => true]);
        $this->getJson(route('ai.builder.catalog', ['type' => 'warehouse', 'q' => 'Foreign']))
            ->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_create_and_cancel_uses_existing_scheduler_without_business_writes(): void
    {
        $this->post(route('ai.builder.store'), $this->input())
            ->assertRedirect(route('ai.builder.index'));
        $rule = DB::table('ai_scheduled_rules')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($rule);
        $this->assertTrue((bool) $rule->prepare_purchase_order);
        $this->get(route('ai.builder.runs', ['id' => $rule->id]))
            ->assertOk()->assertSee('No runs recorded');
        $this->post(route('ai.builder.cancel', ['id' => $rule->id]),
            ['revision' => 1])->assertRedirect(route('ai.builder.index'));
        $this->assertFalse((bool) DB::table('ai_scheduled_rules')->where('id', $rule->id)->value('is_active'));
        $this->assertSame(0, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('purchases')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->count());
    }

    public function test_forged_tenant_fields_and_disabled_scheduling_fail_closed(): void
    {
        $this->post(route('ai.builder.store'), $this->input() + ['company_id' => 999])
            ->assertSessionHasErrors('rule');
        $foreign = $this->input();
        $foreign['warehouse_id'] = 987654321;
        $this->post(route('ai.builder.store'), $foreign)->assertSessionHasErrors('warehouse_id');
        $this->assertSame(0, DB::table('ai_scheduled_rules')->where('company_id', $this->company->id)->count());
        config(['ai_infrastructure.scheduling.enabled' => false]);
        $this->get(route('ai.builder.index'))->assertOk()->assertSee('Scheduling is disabled');
        $this->getJson(route('ai.builder.catalog', ['type' => 'product']))->assertForbidden();
        $this->post(route('ai.builder.store'), $this->input())->assertForbidden();
    }

    public function test_performance_counts_real_runs_but_never_assumes_accuracy_or_savings(): void
    {
        $rule = $this->rule();
        $this->insertScheduledRun($rule['id'], 'completed', 2);
        $this->insertScheduledRun($rule['id'], 'failed', 5);
        $data = app(AiAutomationPerformanceService::class)->report($this->context(), $this->actor->id, 30);
        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['completed']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(1, $data['alerts']);
        $this->assertSame(1, $data['exceptions']);
        $this->assertNull($data['accuracy_percent']);
        $this->assertSame(0, $data['estimated_gross_savings_minor']);
        $this->assertGreaterThan(0, $data['average_execution_ms']);
        $this->get(route('ai.performance.index'))->assertOk()->assertSee('Not measured');
    }

    public function test_human_reviews_are_immutable_and_labour_savings_are_calculated_by_php(): void
    {
        $rule = $this->rule();
        $runId = $this->insertScheduledRun($rule['id'], 'completed', 2);
        $input = ['run_id' => $runId, 'outcome' => 'correct',
            'minutes_saved' => 30, 'hourly_rate_rupees' => 600,
            'notes' => 'Observed time compared with manual checking', 'days' => 30];
        $this->post(route('ai.performance.review'), $input)
            ->assertRedirect(route('ai.performance.index', ['days' => 30]));
        $data = app(AiAutomationPerformanceService::class)->report($this->context(), $this->actor->id, 30);
        $this->assertSame(100.0, $data['accuracy_percent']);
        $this->assertSame(1, $data['reviewed_count']);
        $this->assertSame(30000, $data['estimated_gross_savings_minor']);
        $this->post(route('ai.performance.review'), $input)->assertSessionHasErrors('review');
        $this->assertSame(1, DB::table('ai_automation_reviews')->where('run_id', $runId)->count());
        $this->assertSame(0, DB::table('ai_action_approvals')->count());
        $this->assertSame(0, DB::table('purchases')->where('company_id', $this->company->id)->count());
    }

    public function test_incorrect_reviews_cannot_claim_time_savings(): void
    {
        $rule = $this->rule();
        $runId = $this->insertScheduledRun($rule['id'], 'completed', 2);
        $this->expectException(ValidationException::class);
        app(AiAutomationReviewService::class)->record($this->context(), $this->actor->id,
            ['run_id' => $runId, 'outcome' => 'incorrect',
                'minutes_saved' => 30, 'hourly_rate_rupees' => 600, 'notes' => 'Incorrect result']);
    }

    public function test_completed_run_review_cannot_cross_branch_even_for_an_approver(): void
    {
        $rule = $this->rule();
        $runId = $this->insertScheduledRun($rule['id'], 'completed', 2);
        $other = $this->company->branches()->create([
            'name' => 'Other Branch', 'code' => 'OTH', 'is_active' => true])->id;
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $other]);
        $this->expectException(AuthorizationException::class);
        app(AiAutomationReviewService::class)->record(
            new CompanyContext($this->company->id, $other, $this->year), $this->actor->id,
            ['run_id' => $runId, 'outcome' => 'correct', 'minutes_saved' => 0,
                'hourly_rate_rupees' => 0, 'notes' => null]);
    }

    private function context(): CompanyContext
    {
        return new CompanyContext($this->company->id, $this->branch, $this->year);
    }

    private function input(): array
    {
        return ['name' => 'Daily QA threshold', 'opt_in' => '1',
            'warehouse_id' => $this->warehouse->id, 'supplier_id' => $this->supplier->id,
            'prepare_purchase_order' => '1', 'interval_minutes' => 1440,
            'max_runs_per_day' => 2, 'expires_days' => 30,
            'items' => [['product_id' => $this->product->id,
                'minimum_qty' => '5.0000', 'order_qty' => '10.0000']]];
    }

    private function rule(): array
    {
        $input = $this->input();
        unset($input['expires_days']);
        $input['expires_at'] = now('UTC')->addDays(30)->toIso8601String();
        return app(ScheduledRuleService::class)->create(
            app(AiPrincipalFactory::class)->createForUser(
                $this->actor->id, $this->company->id, $this->branch, $this->year),
            $input);
    }

    private function insertScheduledRun(string $ruleId, string $status, int $secondsAgo): string
    {
        $id = (string) Str::uuid();
        $now = now('UTC');
        DB::table('ai_schedule_runs')->insert([
            'id' => $id, 'rule_id' => $ruleId, 'company_id' => $this->company->id,
            'actor_id' => $this->actor->id, 'rule_revision' => 1,
            'scheduled_for' => $now->copy()->subSeconds($secondsAgo + 5),
            'quota_date' => $now->toDateString(), 'status' => $status,
            'result_json' => $status === 'completed'
                ? json_encode(['kind' => 'low_stock', 'alerts' => [['product_id' => $this->product->id]]], JSON_THROW_ON_ERROR)
                : null,
            'error_code' => $status === 'failed' ? 'AI_SCHEDULE_FAILED' : null,
            'started_at' => $now->copy()->subSeconds($secondsAgo),
            'completed_at' => $now->copy(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }
}
