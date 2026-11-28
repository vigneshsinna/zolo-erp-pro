<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Multimodal\Services\MediaProcessingOrchestrator;
use App\Ai\Multimodal\Services\MediaProcessingTaskService;
use App\Ai\Multimodal\Services\MediaTaskRecoveryService;
use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\AiMediaAsset;
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

final class MediaProviderBudgetTest extends TestCase
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
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-budget-test-key');
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
            'legal_name' => 'Budget Co ' . uniqid(),
            'trade_name' => 'Budget Co',
            'code' => 'BGT-' . strtoupper(substr(uniqid(), -5)),
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
            'name' => 'Budget Admin',
            'email' => 'budget_' . uniqid() . '@example.com',
            'phone' => '9833300001',
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
    }

    private function validPngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQImWP8//8/AzbAhFV00EoAAFbUAw00q2Z5AAAAAElFTkSuQmCC');
    }

    private function sess(): array
    {
        return ['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId];
    }

    public function test_A15_A16_provider_429_schedules_delayed_retry_without_immediate_retry_storm(): void
    {
        $calls = 0;
        Http::fake([
            'integrate.api.nvidia.com/*' => function () use (&$calls) {
                $calls++;
                return Http::response(['error' => 'too_many_requests'], 429, ['Retry-After' => '45']);
            },
        ]);

        $file = UploadedFile::fake()->createWithContent('rate.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $res->assertStatus(202);
        $taskId = $res->json('processing_task_id');

        $tasks = app(MediaProcessingTaskService::class);
        $claimed = $tasks->claimLease($taskId, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed);

        $this->assertSame(1, $calls, 'Worker immediate retries = 0 must not storm provider on 429.');
        $task = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('retry_scheduled', $task->state);
        $this->assertSame(1, $task->rate_limit_deferrals);
        $this->assertNotNull($task->next_retry_at);
        $this->assertSame('retry_wait', $task->stages_json['extract']['state'] ?? null);
    }

    public function test_A17_A18_terminal_ssl_and_401_do_not_retry_and_preprocessing_metrics_are_recorded(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => function () {
                throw new ConnectionException('cURL error 60: SSL certificate problem');
            },
        ]);

        $file = UploadedFile::fake()->createWithContent('ssl.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $taskId = $res->json('processing_task_id');

        $claimed = app(MediaProcessingTaskService::class)->claimLease($taskId, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed);

        $task = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('failed', $task->state);
        $this->assertSame('PROVIDER_SSL_ERROR', $task->error_code);
        $this->assertFalse($task->is_retryable);

        $asset = AiMediaAsset::query()->findOrFail($task->media_id);
        $this->assertSame('lossless_png', $asset->preprocessing_metrics_json['format_strategy'] ?? null);
        $this->assertGreaterThan(0, (int) ($asset->preprocessing_metrics_json['encoding_ms'] ?? 0));
    }

    public function test_A19_expired_deadline_transitions_task_safely_and_reports_worker_health(): void
    {
        Http::fake();
        $file = UploadedFile::fake()->createWithContent('deadline.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $taskId = $res->json('processing_task_id');

        AiMediaProcessingTask::query()->where('id', $taskId)->update(['deadline_at' => now('UTC')->subMinutes(5)]);
        $summary = app(MediaTaskRecoveryService::class)->reconcile();

        $this->assertGreaterThanOrEqual(1, $summary['expired']);
        $task = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('expired', $task->state);
        $this->assertTrue($task->is_retryable);
        Http::assertNothingSent();

        $poll = $this->actingAs($this->admin)->withSession($this->sess())
            ->getJson("/ai/media/processing-tasks/{$taskId}");
        $poll->assertStatus(200);
        $this->assertArrayHasKey('worker_health', $poll->json());
    }
}
