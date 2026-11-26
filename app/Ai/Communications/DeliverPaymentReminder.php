<?php

declare(strict_types=1);

namespace App\Ai\Communications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** A reference-only job. The worker reloads the sealed approval and current ERP access. */
final class DeliverPaymentReminder implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 30;
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $companyId, public readonly int $outboxId)
    {
        $this->onConnection(config('ai_communications.queue_connection', 'redis'))
            ->onQueue(config('ai_communications.queue', 'ai-communications'))->afterCommit();
    }

    public function handle(PaymentReminderService $service): void
    {
        $service->deliver($this->companyId, $this->outboxId);
    }

    public function failed(?Throwable $exception): void
    {
        // A worker can stop after SMTP accepted a message. Never automatically send it again.
        DB::table('ai_reminder_outbox')->where('company_id', $this->companyId)->where('id', $this->outboxId)
            ->where('status', 'sending')->update(['status' => 'delivery_unknown',
                'failure_category' => 'worker_stopped', 'updated_at' => now('UTC')]);
    }
}
