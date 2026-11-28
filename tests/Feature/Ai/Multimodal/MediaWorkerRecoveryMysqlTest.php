<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Multimodal\Services\MediaProcessingOrchestrator;
use App\Ai\Multimodal\Services\MediaProcessingTaskService;
use App\Ai\Multimodal\Services\MediaTaskRecoveryService;
use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\AiMediaProcessingTask;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class MediaWorkerRecoveryMysqlTest extends TestCase
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
        config()->set('ai.providers.nvidia.key', 'nvapi-recovery-test-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_multimodal.storage_disk', 'ai_private');
        config()->set('ai_multimodal.async.enabled', true);
        config()->set('ai_multimodal.async.queue_connection', 'ai_media_database');
        config()->set('ai_multimodal.async.queue_name', 'ai-perception');
        config()->set('queue.connections.ai_media_database.driver', 'database');
        config()->set('queue.connections.ai_media_database.retry_after', 240);

        $this->company = Company::create([
            'legal_name' => 'Recovery Co ' . uniqid(),
            'trade_name' => 'Recovery Co',
            'code' => 'RCV-' . strtoupper(substr(uniqid(), -5)),
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
            'name' => 'Recovery Admin',
            'email' => 'rcv_' . uniqid() . '@example.com',
            'phone' => '9844400001',
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

    public function test_A13_A14_outbox_recovery_and_cas_lease_fencing_block_stale_worker(): void
    {
        Http::fake();
        $file = UploadedFile::fake()->createWithContent('cas.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $taskId = $res->json('processing_task_id');

        // Simulate crash between DB commit and queue dispatch
        AiMediaProcessingTask::query()->where('id', $taskId)->update([
            'dispatched_at' => null,
            'created_at' => now('UTC')->subMinutes(2),
        ]);
        $summary = app(MediaTaskRecoveryService::class)->reconcile();
        $this->assertGreaterThanOrEqual(1, $summary['redispatched']);

        $tasks = app(MediaProcessingTaskService::class);
        $claimedA = $tasks->claimLease($taskId, 'worker-A');
        $this->assertNotNull($claimedA);

        // Duplicate delivery claim while lease active must return null
        $this->assertNull($tasks->claimLease($taskId, 'worker-B'));

        // Simulate stale lease expiry & recovery
        AiMediaProcessingTask::query()->where('id', $taskId)->update(['lease_expires_at' => now('UTC')->subMinutes(1)]);
        app(MediaTaskRecoveryService::class)->reconcile();

        $claimedB = $tasks->claimLease($taskId, 'worker-B');
        $this->assertNotNull($claimedB);

        // Fenced worker-A with old lease_token cannot write new results
        $this->expectException(MultimodalException::class);
        $tasks->transitionWithLease($claimedA, (string) $claimedA->lease_token, ['state' => 'failed'], 'stale_write', 'extract');
    }

    public function test_A22_crash_after_proposal_commit_recovers_review_ready_without_duplicate_proposal(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'document_type' => 'purchase_bill',
                        'supplier_name' => 'Existing Vendor',
                        'lines' => [['description' => 'Item', 'qty' => 1, 'unit_price' => 50, 'line_total' => 50]],
                    ])],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 40],
            ], 200),
        ]);

        $file = UploadedFile::fake()->createWithContent('crash_prop.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', ['file' => $file, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $taskId = $res->json('processing_task_id');

        $tasks = app(MediaProcessingTaskService::class);
        $claimed = $tasks->claimLease($taskId, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed);

        $afterFirst = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertNotNull($afterFirst->extraction_id);

        // Simulate crash with persisted proposal_id while still in processing + expired lease
        $fakeProposalId = (string) Str::uuid();
        AiMediaProcessingTask::query()->where('id', $taskId)->update([
            'state' => 'processing',
            'proposal_id' => $fakeProposalId,
            'lease_expires_at' => now('UTC')->subMinutes(1),
        ]);

        app(MediaTaskRecoveryService::class)->reconcile();

        $recovered = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('review_ready', $recovered->state);
        $this->assertSame($fakeProposalId, $recovered->proposal_id);
        Http::assertSentCount(1);
    }
}
