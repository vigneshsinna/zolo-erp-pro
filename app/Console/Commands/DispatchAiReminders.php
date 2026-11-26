<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Communications\DeliverPaymentReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Requeue only untouched outbox rows. Unknown/sending outcomes are never retried here. */
final class DispatchAiReminders extends Command
{
    protected $signature = 'ai:dispatch-reminders {--limit=100 : Maximum untouched queued deliveries to dispatch}';
    protected $description = 'Queue pending approved AI payment reminders; workers revalidate access, recipient and balance.';

    public function handle(): int
    {
        if (!config('ai_communications.reminders_enabled') || !config('ai_actions.execution_enabled')) {
            $this->info('AI payment reminder delivery is disabled.');
            return self::SUCCESS;
        }
        $connection = (string) config('ai_communications.queue_connection', 'redis');
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            $this->error('Payment reminders require an asynchronous queue connection.');
            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) { $this->error('Use a limit between 1 and 500.'); return self::FAILURE; }
        $count = 0;
        foreach (\App\Models\Company::where('status', 'active')->orderBy('id')->cursor(['id']) as $company) {
            $rows = DB::table('ai_reminder_outbox')->where('company_id', $company->id)->where('status', 'queued')
                ->where('expires_at', '>', now('UTC'))->orderBy('id')->limit($limit - $count)->get(['id']);
            foreach ($rows as $row) {
                Bus::dispatch(new DeliverPaymentReminder((int) $company->id, (int) $row->id));
                $count++;
            }
            if ($count >= $limit) break;
        }
        $this->info('Queued '.$count.' approved reminder deliveries.');
        return self::SUCCESS;
    }
}
