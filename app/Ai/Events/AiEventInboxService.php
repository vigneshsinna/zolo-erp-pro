<?php

declare(strict_types=1);

namespace App\Ai\Events;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\CanonicalActionPayload;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Durable deterministic events from trusted ERP infrastructure only.
 * No external webhook, model dispatch, transaction posting, or implicit approval.
 */
final class AiEventInboxService
{
    public function recordScheduledAlert(
        AiPrincipal $principal,
        string $ruleId,
        string $runId,
        array $alerts,
        ?string $proposalId = null,
    ): void {
        if (!config('ai_events.internal_schedule_ingestion_enabled', false) || $alerts === []) return;

        $run = DB::table('ai_schedule_runs')->where('id', $runId)
            ->where('rule_id', $ruleId)
            ->where('company_id', $principal->companyId)
            ->where('actor_id', $principal->actorId)
            ->where('status', 'completed')->first();
        $rule = DB::table('ai_scheduled_rules')->where('id', $ruleId)
            ->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)
            ->where('financial_year_id', $principal->financialYearId)
            ->where('actor_id', $principal->actorId)->first();
        if (!$run || !$rule) throw new LogicException('A completed scoped run and its parent rule are required for event ingestion.');

        $summary = [
            'alert_count' => count($alerts),
            'product_ids' => array_values(array_unique(array_map(
                static fn (array $alert): int => (int) $alert['product_id'], $alerts
            ))),
            'rule_id' => $ruleId,
            'run_id' => $runId,
            'requires_review' => true,
        ];
        $digest = CanonicalActionPayload::hash($summary);
        $sourceKey = 'low-stock:'.$runId;

        $inserted = DB::table('ai_event_inbox')->insertOrIgnore([
            'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId, 'actor_id' => $principal->actorId,
            'source_type' => 'internal_schedule', 'source_key' => $sourceKey,
            'event_type' => 'stock.reorder_attention', 'status' => 'pending_review',
            'payload_hash' => $digest, 'summary_json' => CanonicalActionPayload::json($summary),
            'proposal_id' => $proposalId, 'occurred_at' => now('UTC'),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        if ($inserted === 0) {
            $existing = DB::table('ai_event_inbox')
                ->where('company_id', $principal->companyId)
                ->where('source_type', 'internal_schedule')
                ->where('source_key', $sourceKey)->first();
            if (!$existing || !hash_equals((string) $existing->payload_hash, $digest)) {
                throw new LogicException('Event idempotency key was reused with a different payload.');
            }
        }
    }
}
