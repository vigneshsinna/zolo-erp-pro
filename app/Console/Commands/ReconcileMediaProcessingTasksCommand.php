<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Multimodal\Services\MediaTaskRecoveryService;
use Illuminate\Console\Command;

final class ReconcileMediaProcessingTasksCommand extends Command
{
    protected $signature = 'ai:reconcile-media-tasks';

    protected $description = 'Recover undispatched async media tasks, stale worker leases, expired deadlines, and stranded credit reservations.';

    public function handle(MediaTaskRecoveryService $recovery): int
    {
        $summary = $recovery->reconcile();

        $this->info(sprintf(
            'Media task reconciliation complete: redispatched=%d, leases_recovered=%d, expired=%d, credits_reconciled=%d',
            $summary['redispatched'],
            $summary['leases_recovered'],
            $summary['expired'],
            $summary['credits_reconciled'],
        ));

        return self::SUCCESS;
    }
}
