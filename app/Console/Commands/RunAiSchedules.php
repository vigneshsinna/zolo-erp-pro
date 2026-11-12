<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Scheduling\ScheduledRuleRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RunAiSchedules extends Command
{
    protected $signature = 'ai:run-schedules';
    protected $description = 'Dispatch opt-in tenant-scoped AI read and draft schedules; never post or send';

    public function handle(ScheduledRuleRunner $runner): int
    {
        if (!config('ai_infrastructure.scheduling.enabled', false)) return self::SUCCESS;
        $total = 0;
        // System metadata discovery only. Every run and all ERP reads are company-scoped below.
        DB::table('companies')->where('status', 'active')->orderBy('id')->chunkById(100, function ($companies) use ($runner, &$total): void {
            foreach ($companies as $company) $total += $runner->dispatchDue((int) $company->id);
        });
        $this->info("Dispatched {$total} bounded AI schedule runs.");
        return self::SUCCESS;
    }
}
