<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Billing\Services\AiCreditMeter;
use App\Ai\Billing\Services\AiCreditReconciliationService;
use App\Ai\Multimodal\Services\MediaProcessingOrchestrator;
use App\Ai\Multimodal\Services\MediaProcessingTaskService;
use App\Ai\Multimodal\Services\MediaTaskRecoveryService;
use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\AiMediaProcessingTask;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class MediaCreditReconciliationTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('ai_private');
        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('ai_credits.enabled', true);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-credit-test-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_multimodal.storage_disk', 'ai_private');
        config()->set('ai_multimodal.async.enabled', true);
        config()->set('ai_multimodal.async.queue_connection', 'ai_media_database');
        config()->set('ai_multimodal.async.queue_name', 'ai-perception');
        config()->set('ai_multimodal.async.provider_immediate_retries', 0);
        config()->set('queue.connections.ai_media_database.driver', 'database');
        config()->set('queue.connections.ai_media_database.retry_after', 240);

        $this->company = Company::create([
            'legal_name' => 'Credit Media Co ' . uniqid(),
            'trade_name' => 'Credit Media Co',
            'code' => 'CMC-' . strtoupper(substr(uniqid(), -5)),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchId = $this->company->branches()->create(['name' => 'Main', 'code' => 'M-' . uniqid(), 'is_active' => true])->id;
        $this->fyId = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->admin = User::create([
            'name' => 'Credit Admin',
            'email' => 'cred_' . uniqid() . '@example.com',
            'phone' => '9866600001',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->company->users()->attach($this->admin->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'branch_id' => $this->branchId,
        ]);

        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 200000,
        ]);

        $bal = app(AiCreditMeter::class)->balance($this->company->id);
        DB::table('ai_credit_periods')->where('id', $bal->periodId)->update([
            'included_granted' => 1000,
            'spent_credits' => 0,
            'reserved_credits' => 0,
            'reconcile_required' => 0,
        ]);
        $existingGrant = DB::table('ai_credit_ledger')
            ->where('period_id', $bal->periodId)
            ->where('kind', 'included_grant')
            ->first();
        if ($existingGrant) {
            DB::table('ai_credit_ledger')->where('id', $existingGrant->id)->update(['amount_credits' => 1000]);
        } else {
            DB::table('ai_credit_ledger')->insert([
                'id' => (string) Str::uuid(),
                'account_id' => $bal->accountId,
                'period_id' => $bal->periodId,
                'company_id' => $this->company->id,
                'actor_id' => $this->admin->id,
                'kind' => 'included_grant',
                'amount_credits' => 1000,
                'currency' => 'INR',
                'cost_minor_inr' => 0,
                'rate_card_version' => 'cost_v1',
                'fx_rate_version' => 'fx_2026_11',
                'source_type' => 'monthly_grant',
                'source_id' => $bal->periodId,
                'idempotency_key' => 'grant_' . $bal->periodId,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    private function validPngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQImWP8//8/AzbAhFV00EoAAFbUAw00q2Z5AAAAAElFTkSuQmCC');
    }

    private function sess(): array
    {
        return ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];
    }

    public function test_A23_A25_successful_settlement_poll_and_retry_reuse_consume_exact_credits(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'document_type' => 'purchase_bill',
                        'supplier_name' => 'Credit Vendor',
                        'lines' => [['description' => 'Item', 'qty' => 1, 'unit_price' => 50, 'line_total' => 50]],
                    ])],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 100],
            ], 200),
        ]);

        $meter = app(AiCreditMeter::class);
        $before = $meter->balance($this->company->id);

        $file = UploadedFile::fake()->createWithContent('credit_ok.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $res->assertStatus(202);
        $taskId = $res->json('processing_task_id');

        $claimed = app(MediaProcessingTaskService::class)->claimLease($taskId, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed);

        $after = $meter->balance($this->company->id);
        $this->assertGreaterThan(0, $after->spentCredits);
        $this->assertSame(0, $after->reservedCredits);
        $this->assertSame($before->includedGranted - $after->spentCredits, $after->availableCredits);

        // Status polling consumes zero extra credits
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->admin)->withSession($this->sess())
                ->getJson("/ai/media/processing-tasks/{$taskId}")->assertStatus(200);
        }
        $afterPoll = $meter->balance($this->company->id);
        $this->assertSame($after->spentCredits, $afterPoll->spentCredits);

        // Reconcile account & attempt matches ledger 1:1
        $attempt = DB::table('ai_media_provider_attempts')->where('task_id', $taskId)->first();
        $this->assertNotNull($attempt);
        $this->assertSame('responded', $attempt->status);
        $attemptAudit = app(AiCreditReconciliationService::class)->reconcileAttempt($attempt->provider_attempt_id);
        $this->assertTrue($attemptAudit['is_matched']);
        $accountAudit = app(AiCreditReconciliationService::class)->reconcileAccount($after->accountId);
        $this->assertTrue($accountAudit['is_reconciled']);
    }

    public function test_A23_post_send_timeout_holds_reservation_as_unknown_billability_while_unsent_ssl_releases(): void
    {
        $call = 0;
        Http::fake([
            'integrate.api.nvidia.com/*' => function () use (&$call) {
                $call++;
                if ($call === 1) {
                    throw new ConnectionException('cURL error 60: SSL certificate problem');
                }
                throw new ConnectionException('cURL error 28: Operation timed out after 75000 milliseconds');
            },
        ]);

        // 1. Unsent SSL error releases held credits cleanly
        $file1 = UploadedFile::fake()->createWithContent('ssl_cred.png', $this->validPngBytes());
        $res1 = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file1, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $task1 = $res1->json('processing_task_id');

        $claimed1 = app(MediaProcessingTaskService::class)->claimLease($task1, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed1);

        $meter = app(AiCreditMeter::class);
        $balAfterSsl = $meter->balance($this->company->id);
        $this->assertSame(0, $balAfterSsl->reservedCredits);
        $this->assertSame(0, $balAfterSsl->spentCredits);

        // 2. Post-send timeout (cURL 28) keeps reservation as unknown_billability (no blind refund)
        $file2 = UploadedFile::fake()->createWithContent('timeout_cred.png', $this->validPngBytes());
        $res2 = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file2, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $task2 = $res2->json('processing_task_id');

        $claimed2 = app(MediaProcessingTaskService::class)->claimLease($task2, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed2);

        $att2 = DB::table('ai_media_provider_attempts')->where('task_id', $task2)->first();
        $this->assertSame('uncertain', $att2->status);
        $resRow = DB::table('ai_credit_reservations')->where('id', $att2->reservation_id)->first();
        $this->assertSame('unknown_billability', $resRow->status);
        $this->assertGreaterThan(0, $meter->balance($this->company->id)->reservedCredits);
    }

    public function test_A24_credit_exhaustion_blocks_dispatch_without_calling_provider(): void
    {
        Http::fake();
        $meter = app(AiCreditMeter::class);
        $bal = $meter->balance($this->company->id);
        DB::table('ai_credit_periods')->where('id', $bal->periodId)->update(['included_granted' => 0]);

        $file = UploadedFile::fake()->createWithContent('exhausted.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $taskId = $res->json('processing_task_id');

        $claimed = app(MediaProcessingTaskService::class)->claimLease($taskId, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed);

        $failed = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('failed', $failed->state);
        $this->assertTrue($failed->is_retryable);
        Http::assertNothingSent();
    }

    public function test_worker_killed_after_sent_marks_attempt_uncertain_on_recovery_sweep(): void
    {
        Http::fake();
        $file = UploadedFile::fake()->createWithContent('killed_sent.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $taskId = $res->json('processing_task_id');

        $claimed = app(MediaProcessingTaskService::class)->claimLease($taskId, 'worker-kill');
        $resId = (string) Str::uuid();
        $bal = app(AiCreditMeter::class)->balance($this->company->id);
        DB::table('ai_credit_reservations')->insert([
            'id' => $resId,
            'account_id' => $bal->accountId,
            'period_id' => $bal->periodId,
            'company_id' => $this->company->id,
            'actor_id' => $this->admin->id,
            'run_id' => (string) Str::uuid(),
            'operation_key' => 'perception.extract',
            'requested_model' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
            'estimated_credits' => 25,
            'remaining_held_credits' => 25,
            'status' => 'active',
            'idempotency_key' => 'kill_' . Str::uuid(),
            'expires_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_media_provider_attempts')->insert([
            'id' => (string) Str::uuid(),
            'task_id' => $taskId,
            'company_id' => $this->company->id,
            'media_id' => $claimed->media_id,
            'page_number' => 1,
            'attempt_number' => 1,
            'provider' => 'nvidia',
            'model_key' => 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
            'provider_attempt_id' => 'att_kill_' . Str::uuid(),
            'reservation_id' => $resId,
            'status' => 'sent',
            'sent_at' => now('UTC')->subMinutes(5),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        AiMediaProcessingTask::query()->where('id', $taskId)->update(['lease_expires_at' => now('UTC')->subMinutes(1)]);

        app(MediaTaskRecoveryService::class)->reconcile();

        $taskAfter = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('failed', $taskAfter->state);
        $this->assertSame('WORKER_CRASHED_PROVIDER_UNCERTAIN', $taskAfter->error_code);
        $this->assertSame('unknown_billability', DB::table('ai_credit_reservations')->where('id', $resId)->value('status'));
    }
}
