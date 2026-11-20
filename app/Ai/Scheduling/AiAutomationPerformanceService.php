<?php

declare(strict_types=1);

namespace App\Ai\Scheduling;

use App\Ai\Services\AiFeatureGate;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bounded, permission-scoped reports from persisted scheduler and human review
 * evidence. No model calls, assumed accuracy or fabricated cost savings.
 */
final class AiAutomationPerformanceService
{
    public function __construct(
        private readonly CompanyContextResolver $resolver,
        private readonly CommercialPermission $permissions,
        private readonly AiFeatureGate $featureGate,
    ) {}

    public function report(CompanyContext $context, int $actorId, int $days = 30): array
    {
        $this->resolver->forActor($context, $actorId);
        $this->permissions->assert('ai.use', $context, $actorId);
        if (!in_array($days, [7, 30, 90], true)) {
            throw ValidationException::withMessages(['days' => 'Select a supported reporting period.']);
        }
        $canReview = $this->permissions->allows('ai.action.approve', $context, $actorId);
        $enabled = $this->featureGate->isEnabledForCompany($context->companyId)
            && (bool) config('ai_infrastructure.scheduling.enabled', false);
        $empty = [
            'period_days' => $days, 'enabled' => $enabled, 'can_review' => $canReview,
            'total' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0, 'queued' => 0,
            'exceptions' => 0, 'reconciliation' => 0, 'drafts' => 0, 'alerts' => 0,
            'accuracy_percent' => null, 'reviewed_count' => 0, 'correct_count' => 0,
            'estimated_gross_savings_minor' => 0, 'attributed_model_cost_minor' => 0,
            'attributed_credits_debited' => 0, 'average_execution_ms' => null,
            'p95_sample_ms' => null, 'latency_sample_count' => 0,
            'recent' => [], 'daily' => [],
        ];
        if (!$enabled) return $empty;

        $since = now('UTC')->subDays($days);
        $rows = DB::table('ai_schedule_runs as run')
            ->join('ai_scheduled_rules as rule', function ($join): void {
                $join->on('rule.id', '=', 'run.rule_id')
                    ->on('rule.company_id', '=', 'run.company_id')
                    ->on('rule.actor_id', '=', 'run.actor_id');
            })
            ->leftJoin('ai_automation_reviews as review', function ($join): void {
                $join->on('review.run_id', '=', 'run.id')
                    ->on('review.company_id', '=', 'run.company_id')
                    ->on('review.branch_id', '=', 'rule.branch_id')
                    ->on('review.financial_year_id', '=', 'rule.financial_year_id');
            })
            ->leftJoin('ai_action_proposals as proposal', function ($join): void {
                $join->on('proposal.id', '=', 'run.proposal_id')
                    ->on('proposal.company_id', '=', 'run.company_id')
                    ->on('proposal.branch_id', '=', 'rule.branch_id')
                    ->on('proposal.financial_year_id', '=', 'rule.financial_year_id');
            })
            ->where('run.company_id', $context->companyId)
            ->where('rule.branch_id', $context->branchId)
            ->where('rule.financial_year_id', $context->financialYearId)
            ->when(!$canReview, static fn ($query) => $query->where('run.actor_id', $actorId))
            ->where('run.scheduled_for', '>=', $since->toDateTimeString())
            ->orderByDesc('run.scheduled_for')
            ->limit(10000)
            ->get([
                'run.id as run_id', 'run.status', 'run.proposal_id', 'run.error_code',
                'run.scheduled_for', 'run.started_at', 'run.completed_at', 'run.result_json',
                'rule.name as rule_name', 'review.outcome', 'review.estimated_gross_savings_minor',
                'proposal.status as proposal_status',
            ]);

        $daysMap = [];
        $latencies = [];
        $elapsedTotal = 0;
        $metrics = $empty;
        $metrics['total'] = $rows->count();
        foreach ($rows as $run) {
            $status = (string) $run->status;
            if (array_key_exists($status, $metrics)) {
                $metrics[$status]++;
            }
            if ($status === 'failed') $metrics['exceptions']++;
            if ($run->proposal_status === 'requires_reconciliation') {
                $metrics['reconciliation']++;
                $metrics['exceptions']++;
            }
            if ($run->proposal_id) $metrics['drafts']++;
            if ($status === 'completed') {
                $result = json_decode((string) ($run->result_json ?? '{}'), true);
                if (is_array($result) && is_array($result['alerts'] ?? null)) {
                    $metrics['alerts'] += count($result['alerts']);
                }
            }
            if (in_array($run->outcome, ['correct', 'incorrect'], true)) {
                $metrics['reviewed_count']++;
                if ($run->outcome === 'correct') $metrics['correct_count']++;
                $metrics['estimated_gross_savings_minor'] +=
                    max(0, (int) $run->estimated_gross_savings_minor);
            }
            if ($run->started_at && $run->completed_at) {
                $ms = max(0, (int) Carbon::parse($run->started_at, 'UTC')
                    ->diffInMilliseconds(Carbon::parse($run->completed_at, 'UTC')));
                if (count($latencies) < 5000) $latencies[] = $ms;
                $elapsedTotal += $ms;
                $metrics['latency_sample_count']++;
            }
            $day = Carbon::parse($run->scheduled_for, 'UTC')->toDateString();
            $daysMap[$day] ??= ['day' => $day, 'total' => 0, 'completed' => 0, 'failed' => 0];
            $daysMap[$day]['total']++;
            if ($status === 'completed') $daysMap[$day]['completed']++;
            if ($status === 'failed') $daysMap[$day]['failed']++;
            if (count($metrics['recent']) < 25) {
                $metrics['recent'][] = [
                    'run_id' => $run->run_id, 'name' => $run->rule_name,
                    'status' => $status, 'scheduled_for' => $run->scheduled_for,
                    'error_code' => $run->error_code, 'reviewed' => $run->outcome !== null,
                    'proposal_id' => $run->proposal_id,
                ];
            }
        }
        if ($metrics['reviewed_count']) {
            $metrics['accuracy_percent'] =
                round(100 * $metrics['correct_count'] / $metrics['reviewed_count'], 1);
        }
        if ($metrics['latency_sample_count']) {
            $metrics['average_execution_ms'] =
                round($elapsedTotal / $metrics['latency_sample_count']);
        }
        if ($latencies !== []) {
            sort($latencies, SORT_NUMERIC);
            $metrics['p95_sample_ms'] = $latencies[max(0, (int) ceil(count($latencies) * 0.95) - 1)];
        }
        foreach (range(min($days, 14) - 1, 0) as $offset) {
            $key = now('UTC')->subDays($offset)->toDateString();
            $metrics['daily'][] = $daysMap[$key] ?? [
                'day' => $key, 'total' => 0, 'completed' => 0, 'failed' => 0,
            ];
        }

        // Attribute model cost to exact, scope-checked run IDs, rather than
        // attributing every company Copilot call to background automation.
        if ($rows->isNotEmpty()) {
            $ids = $rows->pluck('run_id')->all();
            $spend = DB::table('ai_model_invocations')
                ->where('company_id', $context->companyId)
                ->whereIn('run_id', $ids)
                ->selectRaw('COALESCE(SUM(charge_minor_inr), 0) AS cost_minor, COALESCE(SUM(credits_debited), 0) AS credits')
                ->first();
            $metrics['attributed_model_cost_minor'] = max(0, (int) ($spend->cost_minor ?? 0));
            $metrics['attributed_credits_debited'] = max(0, (int) ($spend->credits ?? 0));
        }
        return $metrics;
    }
}
