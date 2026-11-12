<?php

declare(strict_types=1);

namespace App\Ai\Scheduling;

use App\Ai\Services\ActionPreparationService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAvailabilityService;
use App\Services\Platform\CompanyContextResolver;
use App\Support\LedgerAmount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ScheduledRuleRunner
{
    public function dispatchDue(int $companyId): int
    {
        if (!config('ai_infrastructure.scheduling.enabled', false)) return 0;
        $limit = max(1, min(100, (int) config('ai_infrastructure.scheduling.claim_batch_size', 50)));
        $ids = DB::table('ai_scheduled_rules')->where('company_id', $companyId)->where('is_active', true)
            ->where('expires_at', '>', now('UTC'))->where('next_run_at', '<=', now('UTC'))->orderBy('next_run_at')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $run = DB::transaction(function () use ($companyId, $id): ?array {
                $company = DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
                $rule = DB::table('ai_scheduled_rules')->where('company_id', $companyId)->where('id', $id)->lockForUpdate()->first();
                if (!$company || !$rule || !$rule->is_active || $company->status !== 'active'
                    || $rule->expires_at <= now('UTC')->format('Y-m-d H:i:s') || $rule->next_run_at > now('UTC')->format('Y-m-d H:i:s')) return null;
                $date = now($company->timezone)->toDateString();
                $daily = DB::table('ai_schedule_runs')->where('company_id', $companyId)->where('quota_date', $date);
                if ((clone $daily)->count() >= config('ai_infrastructure.scheduling.max_company_runs_per_day', 100)
                    || (clone $daily)->where('rule_id', $id)->count() >= $rule->max_runs_per_day) {
                    DB::table('ai_scheduled_rules')->where('company_id', $companyId)->where('id', $id)
                        ->update(['next_run_at' => now($company->timezone)->addDay()->startOfDay()->utc(), 'updated_at' => now('UTC')]);
                    return null;
                }
                $runId = (string) Str::uuid();
                DB::table('ai_schedule_runs')->insert([
                    'id' => $runId, 'rule_id' => $id, 'company_id' => $companyId, 'actor_id' => $rule->actor_id,
                    'rule_revision' => $rule->revision, 'scheduled_for' => $rule->next_run_at, 'quota_date' => $date,
                    'status' => 'queued', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
                DB::table('ai_scheduled_rules')->where('company_id', $companyId)->where('id', $id)
                    ->update(['next_run_at' => now('UTC')->addMinutes($rule->interval_minutes), 'updated_at' => now('UTC')]);
                return ['id' => $runId, 'company_id' => $companyId];
            });
            if ($run) {
                try {
                    $connection = config('ai_infrastructure.queue_connection', 'redis');
                    if (config("queue.connections.{$connection}.driver") === 'sync') throw new \LogicException('Scheduled work requires an asynchronous connection.');
                    Bus::dispatch(new RunScheduledRule($run['id'], $companyId));
                    $count++;
                } catch (Throwable) {
                    $this->fail($companyId, $run['id'], 'AI_SCHEDULE_QUEUE_UNAVAILABLE');
                }
            }
        }
        return $count;
    }

    public function run(int $companyId, string $runId): void
    {
        try {
            DB::transaction(function () use ($companyId, $runId): void {
                $run = DB::table('ai_schedule_runs')->where('company_id', $companyId)->where('id', $runId)->lockForUpdate()->first();
                if (!$run || $run->status !== 'queued') return;
                $rule = DB::table('ai_scheduled_rules')->where('company_id', $companyId)->where('id', $run->rule_id)->lockForUpdate()->first();
                if (!$rule || !$rule->is_active || (int) $rule->revision !== (int) $run->rule_revision
                    || $rule->expires_at <= now('UTC')->format('Y-m-d H:i:s') || !config('ai_infrastructure.scheduling.enabled', false)) {
                    $this->finish($companyId, $runId, 'cancelled', ['message' => 'Rule was cancelled, changed, expired, or disabled.']);
                    return;
                }
                DB::table('ai_schedule_runs')->where('company_id', $companyId)->where('id', $runId)
                    ->update(['started_at' => now('UTC'), 'updated_at' => now('UTC')]);
                $principal = app(AiPrincipalFactory::class)->createForUser((int) $rule->actor_id, $companyId, (int) $rule->branch_id, (int) $rule->financial_year_id, $runId);
                if (!$principal->isActive || !$principal->hasTool('inventory.stock_summary.v1')
                    || !app(AiFeatureGate::class)->isEnabledForCompany($companyId)
                    || $rule->prepare_purchase_order && !$principal->hasTool('purchase_order.create.v1')) {
                    throw new AuthorizationException('Scheduled rule permission was revoked.');
                }
                $company = DB::table('companies')->where('id', $companyId)->first();
                $date = now($company->timezone)->toDateString();
                $context = app(CompanyContextResolver::class)->resolve($principal->actorId, $companyId, $principal->branchId, $principal->financialYearId, $date);
                $input = json_decode($rule->inputs_json, true, flags: JSON_THROW_ON_ERROR);
                $warehouse = Warehouse::where('company_id', $companyId)->where('branch_id', $principal->branchId)->where('is_active', true)->find($input['warehouse_id']);
                if (!$warehouse) throw new AuthorizationException('Scheduled warehouse is no longer accessible.');
                $products = Product::where('company_id', $companyId)->where('is_active', true)->whereNotIn('type', ['service', 'digital'])
                    ->whereIn('id', array_column($input['items'], 'product_id'))->get()->keyBy('id');
                if ($products->count() !== count($input['items'])) throw new AuthorizationException('Scheduled products are no longer accessible.');
                $stock = app(InventoryAvailabilityService::class)->forCatalogBatch($context, $products, [(int) $warehouse->id]);
                $alerts = $items = [];
                foreach ($input['items'] as $line) {
                    $product = $products[$line['product_id']];
                    $balance = $stock[$product->id] ?? [];
                    $available = ($balance['on_hand'] ?? 0) - ($balance['reserved'] ?? 0);
                    $minimum = LedgerAmount::units((string) $line['minimum_qty']);
                    if ($available <= $minimum) {
                        $alerts[] = ['product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->code,
                            'unit_id' => $product->unit_id, 'available' => LedgerAmount::decimal($available),
                            'minimum_qty' => LedgerAmount::decimal($minimum), 'reconciled' => !($balance['unreconciled'] ?? false)];
                        // Reorder quantities are explicitly configured in the product's purchase UOM.
                        $items[] = ['product_id' => $product->id, 'qty' => (string) $line['order_qty'],
                            'purchase_unit_id' => $product->purchase_unit_id ?? $product->unit_id,
                            'net_unit_cost' => (string) $product->cost];
                    }
                }
                $result = ['kind' => 'low_stock', 'as_of' => $date, 'warehouse_id' => $warehouse->id, 'alerts' => $alerts,
                    'policy' => 'Read alerts and unapproved proposals only. No posting or outbound sending.'];
                $proposalId = $taskId = null;
                if ($alerts && $rule->prepare_purchase_order) {
                    if (collect($alerts)->contains(fn ($alert) => !$alert['reconciled'])) {
                        $result['preparation_status'] = 'requires_stock_reconciliation';
                    } elseif ($this->hasPendingReview($companyId, $rule->id)) {
                        $result['preparation_status'] = 'awaiting_existing_review';
                    } else {
                        if (!Supplier::where('company_id', $companyId)->where('is_active', true)->whereKey($input['supplier_id'])->exists()) {
                            throw new AuthorizationException('Scheduled supplier is no longer accessible.');
                        }
                        $prepared = app(ActionPreparationService::class)->prepare($principal, 'purchase_order.create.v1', [
                            'supplier_id' => (int) $input['supplier_id'], 'warehouse_id' => (int) $warehouse->id,
                            'date' => $date, 'items' => $items, 'note' => 'Prepared by opt-in low-stock schedule '.$rule->id.'. Human approval is required.',
                        ]);
                        $proposalId = $prepared['data']['proposal_id'] ?? null;
                        $taskId = $prepared['data']['task_id'] ?? $prepared['task_id'] ?? null;
                        $result['preparation_status'] = $prepared['status'];
                        $result['preparation'] = $prepared;
                    }
                }
                $this->finish($companyId, $runId, 'completed', $result, $proposalId, $taskId);
            });
        } catch (Throwable $error) {
            $code = $error instanceof AuthorizationException ? 'AI_SCHEDULE_PERMISSION_REVOKED'
                : ($error instanceof ValidationException ? 'AI_SCHEDULE_INPUT_INVALID' : 'AI_SCHEDULE_FAILED');
            $this->fail($companyId, $runId, $code);
        }
    }

    public function fail(int $companyId, string $runId, string $code): void
    {
        DB::table('ai_schedule_runs')->where('company_id', $companyId)->where('id', $runId)->where('status', 'queued')
            ->update(['status' => 'failed', 'error_code' => $code, 'completed_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    private function finish(int $companyId, string $runId, string $status, array $result, ?string $proposalId = null, ?string $taskId = null): void
    {
        DB::table('ai_schedule_runs')->where('company_id', $companyId)->where('id', $runId)
            ->update(['status' => $status, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'proposal_id' => $proposalId,
                'task_id' => $taskId, 'completed_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    private function hasPendingReview(int $companyId, string $ruleId): bool
    {
        $runs = DB::table('ai_schedule_runs')->where('company_id', $companyId)->where('rule_id', $ruleId);
        if (DB::table('ai_action_proposals')->where('company_id', $companyId)->whereIn('id', (clone $runs)->whereNotNull('proposal_id')->select('proposal_id'))
            ->whereIn('status', ['pending', 'approved', 'executing', 'requires_reconciliation'])->where('expires_at', '>', now('UTC'))->exists()) return true;
        return DB::table('ai_workflow_tasks')->where('company_id', $companyId)->whereIn('id', (clone $runs)->whereNotNull('task_id')->select('task_id'))
            ->whereIn('status', ['collecting', 'ready'])->where('expires_at', '>', now('UTC'))->exists();
    }
}
