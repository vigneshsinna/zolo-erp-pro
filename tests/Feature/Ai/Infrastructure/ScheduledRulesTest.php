<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Infrastructure;

use App\Ai\Scheduling\RunScheduledRule;
use App\Ai\Scheduling\ScheduledRuleRunner;
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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class ScheduledRulesTest extends TestCase
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
        config(['erp_ai.enabled' => true, 'erp_ai.read_only' => false, 'ai_actions.foundation_enabled' => true,
            'ai_infrastructure.scheduling.enabled' => true, 'ai_infrastructure.queue_connection' => 'database']);
        $this->actor = User::create(['name' => 'Schedule Owner', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('fixture'), 'role_id' => 1, 'phone' => '1234567890', 'is_active' => true, 'is_deleted' => false]);
        $this->company = Company::create(['legal_name' => 'Schedule Company', 'trade_name' => 'Schedule',
            'code' => 'SCH-'.Str::random(10), 'status' => 'active', 'country_code' => 'IN', 'timezone' => 'Asia/Kolkata']);
        $this->branch = $this->company->branches()->create(['name' => 'Schedule Branch', 'code' => 'SCH', 'is_active' => true])->id;
        $this->year = $this->company->fiscalYears()->create(['name' => 'Schedule FY', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'status' => 'open', 'is_closed' => false])->id;
        $this->company->users()->attach($this->actor->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert(['company_id' => $this->company->id, 'user_id' => $this->actor->id, 'branch_id' => $this->branch]);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false);
        $this->warehouse = Warehouse::forceCreate(['name' => 'Schedule Warehouse', 'address' => 'Fixture address', 'company_id' => $this->company->id, 'branch_id' => $this->branch, 'is_active' => true]);
        $unit = Unit::forceCreate(['unit_code' => 'PCS', 'unit_name' => 'Pieces', 'company_id' => $this->company->id, 'is_active' => true]);
        $category = Category::forceCreate(['name' => 'Schedule Goods', 'company_id' => $this->company->id, 'is_active' => true]);
        $this->product = Product::forceCreate(['name' => 'Low Stock Product', 'code' => 'SCH-'.Str::random(8), 'type' => 'standard', 'barcode_symbology' => 'code128',
            'company_id' => $this->company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'purchase_unit_id' => $unit->id,
            'sale_unit_id' => $unit->id, 'cost' => '100.00', 'price' => '120.00', 'qty' => 0, 'is_active' => true]);
        $this->supplier = Supplier::forceCreate(['name' => 'Explicit Reorder Supplier', 'company_name' => 'Explicit Reorder Supplier', 'phone_number' => '1234567890', 'address' => 'Fixture address', 'city' => 'Fixture City',
            'company_id' => $this->company->id, 'is_active' => true]);
    }

    public function test_opt_in_is_required_and_tenant_or_branch_injection_is_denied(): void
    {
        $service = app(ScheduledRuleService::class);
        foreach ([['opt_in' => false], ['company_id' => 999], ['warehouse_id' => 999999], ['items' => [['product_id' => 999999, 'minimum_qty' => '5', 'order_qty' => '10']]]] as $change) {
            try {
                $service->create($this->principal(), array_replace($this->input(), $change));
                $this->fail('Invalid or foreign rule must not be created.');
            } catch (ValidationException) {
                $this->assertSame(0, DB::table('ai_scheduled_rules')->where('company_id', $this->company->id)->count());
            }
        }
    }

    public function test_stock_alert_run_is_durable_deduplicated_and_has_no_business_writes(): void
    {
        Bus::fake([RunScheduledRule::class]);
        $rule = app(ScheduledRuleService::class)->create($this->principal(), $this->input());
        $runner = app(ScheduledRuleRunner::class);
        $this->assertSame(1, $runner->dispatchDue($this->company->id));
        $this->assertSame(0, $runner->dispatchDue($this->company->id));
        Bus::assertDispatched(RunScheduledRule::class, fn ($job) => $job->queue === 'ai-scheduled');
        $runId = DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('rule_id', $rule['id'])->value('id');
        $runner->run($this->company->id, $runId);
        $runner->run($this->company->id, $runId);
        $run = DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('id', $runId)->first();
        $result = json_decode($run->result_json, true);
        $this->assertSame('completed', $run->status);
        $this->assertSame('0.0000', $result['alerts'][0]['available']);
        $this->assertSame(0, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('purchases')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->count());
    }

    public function test_scheduled_purchase_preparation_creates_pending_proposal_only_once_per_review(): void
    {
        config(['ai_actions.proposal_ttl_minutes' => 180]);
        Bus::fake([RunScheduledRule::class]);
        $rule = app(ScheduledRuleService::class)->create($this->principal(), $this->input(true));
        $runner = app(ScheduledRuleRunner::class);
        $runner->dispatchDue($this->company->id);
        $runId = DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('rule_id', $rule['id'])->value('id');
        $runner->run($this->company->id, $runId);
        $run = DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('id', $runId)->first();
        $this->assertSame('completed', $run->status, $run->error_code ?? '');
        $this->assertNotNull($run->proposal_id);
        $this->assertSame('pending', DB::table('ai_action_proposals')->where('company_id', $this->company->id)->where('id', $run->proposal_id)->value('status'));
        $this->assertSame(0, DB::table('ai_action_approvals')->where('proposal_id', $run->proposal_id)->count());
        $this->assertSame(0, DB::table('purchases')->where('company_id', $this->company->id)->count());
        $this->travel(61)->minutes();
        $runner->dispatchDue($this->company->id);
        $second = DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('rule_id', $rule['id'])->where('id', '!=', $runId)->first();
        $runner->run($this->company->id, $second->id);
        $result = json_decode(DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('id', $second->id)->value('result_json'), true);
        $this->assertSame('awaiting_existing_review', $result['preparation_status']);
        $this->assertSame(1, DB::table('ai_action_proposals')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('purchases')->where('company_id', $this->company->id)->count());
    }

    public function test_cancellation_and_permission_revocation_stop_queued_runs(): void
    {
        Bus::fake([RunScheduledRule::class]);
        $service = app(ScheduledRuleService::class);
        $rule = $service->create($this->principal(), $this->input());
        $runner = app(ScheduledRuleRunner::class);
        $runner->dispatchDue($this->company->id);
        $run = DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('rule_id', $rule['id'])->first();
        $service->cancel($this->principal(), $rule['id'], 1);
        $runner->run($this->company->id, $run->id);
        $this->assertSame('cancelled', DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('id', $run->id)->value('status'));
        $other = $service->create($this->principal(), $this->input());
        $runner->dispatchDue($this->company->id);
        DB::table('company_user_branches')->where('company_id', $this->company->id)->where('user_id', $this->actor->id)->delete();
        $run = DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('rule_id', $other['id'])->first();
        $runner->run($this->company->id, $run->id);
        $this->assertSame('AI_SCHEDULE_PERMISSION_REVOKED', DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->where('id', $run->id)->value('error_code'));
    }

    public function test_daily_caps_and_expiry_prevent_more_runs(): void
    {
        Bus::fake([RunScheduledRule::class]);
        $rule = app(ScheduledRuleService::class)->create($this->principal(), $this->input());
        DB::table('ai_scheduled_rules')->where('company_id', $this->company->id)->where('id', $rule['id'])->update(['max_runs_per_day' => 1]);
        $runner = app(ScheduledRuleRunner::class);
        $this->assertSame(1, $runner->dispatchDue($this->company->id));
        $this->travel(61)->minutes();
        $this->assertSame(0, $runner->dispatchDue($this->company->id));
        $this->assertSame(1, DB::table('ai_schedule_runs')->where('company_id', $this->company->id)->count());
        $this->travel(3)->days();
        $this->assertSame(0, $runner->dispatchDue($this->company->id));
    }

    private function input(bool $prepare = false): array
    {
        return ['name' => 'Opt-in low-stock rule', 'opt_in' => true, 'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id, 'prepare_purchase_order' => $prepare, 'interval_minutes' => 60,
            'max_runs_per_day' => 24, 'expires_at' => now('UTC')->addDays(2)->toIso8601String(),
            'items' => [['product_id' => $this->product->id, 'minimum_qty' => '5.0000', 'order_qty' => '10.0000']]];
    }

    private function principal(): \App\Ai\DTOs\AiPrincipal
    {
        return app(AiPrincipalFactory::class)->createForUser($this->actor->id, $this->company->id, $this->branch, $this->year);
    }
}
