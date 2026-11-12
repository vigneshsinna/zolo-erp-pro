<?php

declare(strict_types=1);

namespace App\Ai\Jobs;

use App\Ai\Services\AiPrincipalFactory;
use App\Ai\Services\ApprovedActionExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** References only. Permissions, context, approval and source state are reloaded by the worker. */
final class ExecuteApprovedAction implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $proposalId, public readonly int $version, public readonly int $actorId)
    {
        $this->onConnection(config('ai_infrastructure.queue_connection', 'redis'))->onQueue('ai-execution')->afterCommit();
    }

    public function handle(AiPrincipalFactory $principals, ApprovedActionExecutor $executor): void
    {
        if (!config('ai_infrastructure.async_execution_enabled', false)) {
            return;
        }
        $proposal = DB::table('ai_action_proposals')->where('id', $this->proposalId)->where('actor_id', $this->actorId)->first();
        if (!$proposal || (int) $proposal->version !== $this->version
            || !in_array($proposal->status, ['approved', 'executing', 'completed', 'requires_reconciliation'], true)) {
            return;
        }
        $principal = $principals->createForUser($this->actorId, (int) $proposal->company_id, (int) $proposal->branch_id, (int) $proposal->financial_year_id);
        $executor->execute($principal, $this->proposalId, $this->version);
    }

    public function failed(?Throwable $exception): void
    {
        $proposal = DB::table('ai_action_proposals')->where('id', $this->proposalId)->where('actor_id', $this->actorId)->first();
        if (!$proposal) return;
        DB::transaction(function () use ($proposal): void {
            $changed = DB::table('ai_action_executions')->where('company_id', $proposal->company_id)
                ->where('proposal_id', $this->proposalId)->where('version', $this->version)->where('status', 'executing')
                ->update(['status' => 'uncertain', 'error_message' => 'Worker stopped; reconcile the authoritative ERP receipt before any retry.']);
            if ($changed) {
                DB::table('ai_action_proposals')->where('company_id', $proposal->company_id)->where('id', $this->proposalId)
                    ->where('version', $this->version)->where('status', 'executing')
                    ->update(['status' => 'requires_reconciliation', 'updated_at' => now('UTC')]);
            }
        });
        Log::warning('AI execution worker failed.', ['proposal_id' => $this->proposalId, 'version' => $this->version,
            'exception_type' => $exception ? $exception::class : null]);
    }
}
