<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\AiMediaAsset;
use App\Models\AiMediaProcessingTask;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class AsyncMediaAdmissionTest extends TestCase
{
    use UsesAiMysql;

    private Company $companyA;
    private Company $companyB;
    private int $branchA;
    private int $branchA2;
    private int $branchB;
    private int $fyA;
    private int $fyB;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('ai_private');
        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-test-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_multimodal.storage_disk', 'ai_private');
        config()->set('ai_multimodal.async.enabled', true);
        config()->set('ai_multimodal.async.queue_connection', 'ai_media_database');
        config()->set('ai_multimodal.async.queue_name', 'ai-perception');
        config()->set('queue.connections.ai_media_database.driver', 'database');
        config()->set('queue.connections.ai_media_database.retry_after', 240);

        $this->companyA = Company::create([
            'legal_name' => 'Async Alpha ' . uniqid(),
            'trade_name' => 'Async Alpha',
            'code' => 'AA-' . strtoupper(substr(uniqid(), -5)),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchA = $this->companyA->branches()->create(['name' => 'Alpha Main', 'code' => 'AM-' . uniqid(), 'is_active' => true])->id;
        $this->branchA2 = $this->companyA->branches()->create(['name' => 'Alpha Sec', 'code' => 'AS-' . uniqid(), 'is_active' => true])->id;
        $this->fyA = FiscalYear::create([
            'company_id' => $this->companyA->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->companyB = Company::create([
            'legal_name' => 'Async Beta ' . uniqid(),
            'trade_name' => 'Async Beta',
            'code' => 'AB-' . strtoupper(substr(uniqid(), -5)),
            'status' => 'active',
            'country_code' => 'IN',
            'state_code' => '33',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchB = $this->companyB->branches()->create(['name' => 'Beta Main', 'code' => 'BM-' . uniqid(), 'is_active' => true])->id;
        $this->fyB = FiscalYear::create([
            'company_id' => $this->companyB->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->adminA = User::create([
            'name' => 'Async Admin A',
            'email' => 'async_a_' . uniqid() . '@example.com',
            'phone' => '9811100001',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->companyA->users()->attach($this->adminA->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminA->id, 'branch_id' => $this->branchA],
            ['company_id' => $this->companyA->id, 'user_id' => $this->adminA->id, 'branch_id' => $this->branchA2],
        ]);

        $this->adminB = User::create([
            'name' => 'Async Admin B',
            'email' => 'async_b_' . uniqid() . '@example.com',
            'phone' => '9811100002',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->companyB->users()->attach($this->adminB->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->companyB->id,
            'user_id' => $this->adminB->id,
            'branch_id' => $this->branchB,
        ]);

        app(AiFeatureGate::class)->setCompanySetting($this->companyA->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 200000,
        ]);
        app(AiFeatureGate::class)->setCompanySetting($this->companyB->id, true, false, [
            'multimodal_enabled' => true,
            'daily_token_limit' => 200000,
        ]);
    }

    private function validPngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQImWP8//8/AzbAhFV00EoAAFbUAw00q2Z5AAAAAElFTkSuQmCC');
    }

    private function sessA(?int $branch = null): array
    {
        return [
            'company_id' => $this->companyA->id,
            'branch_id' => $branch ?? $this->branchA,
            'financial_year_id' => $this->fyA,
        ];
    }

    public function test_A01_admission_returns_202_before_provider_and_worker_progresses_on_real_database_queue(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'document_type' => 'purchase_bill',
                        'supplier_name' => 'Sample Supplier',
                        'invoice_number' => 'INV-900',
                        'total_amount' => 100.00,
                        'lines' => [['description' => 'Item 1', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100]],
                    ])],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ], 200),
        ]);

        $threadId = (string) Str::uuid();
        DB::table('ai_thread_scopes')->insert([
            'id' => $threadId,
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA,
            'financial_year_id' => $this->fyA,
            'user_id' => $this->adminA->id,
            'title' => 'Async Admission Test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = UploadedFile::fake()->createWithContent('samplebill.png', $this->validPngBytes());
        $clientRequestId = (string) Str::uuid();

        $res = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', [
                'file' => $file,
                'thread_id' => $threadId,
                'client_request_id' => $clientRequestId,
                'companion_text' => 'check this bill',
            ], ['Accept' => 'application/json']);

        $res->assertStatus(202);
        $res->assertJsonPath('state', 'queued');
        $res->assertJsonPath('state_version', 1);
        $res->assertJsonPath('model', null);
        $res->assertJsonPath('stages.0.key', 'upload');
        $res->assertJsonPath('stages.0.state', 'completed');
        $res->assertJsonPath('stages.1.state', 'pending');

        // Zero provider calls during web admission
        Http::assertNothingSent();

        // Job is durably queued on ai-perception database queue
        $this->assertDatabaseHas('jobs', ['queue' => 'ai-perception']);

        $taskId = $res->json('processing_task_id');
        $asset = AiMediaAsset::query()->find($res->json('media_id'));
        $this->assertSame('uploaded', $asset->state);
        $this->assertNotEmpty($asset->raw_private_key);

        // Execute real database queue worker (--once)
        Artisan::call('queue:work', [
            'connection' => 'ai_media_database',
            '--queue' => 'ai-perception',
            '--once' => true,
            '--sleep' => 0,
        ]);

        Http::assertSentCount(1);

        $poll = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->getJson("/ai/media/processing-tasks/{$taskId}");

        $poll->assertStatus(200);
        $this->assertContains($poll->json('state'), ['needs_confirmation', 'needs_input', 'review_ready']);
        $this->assertNotNull($poll->json('model.display_name'));
        $this->assertGreaterThan(1, $poll->json('state_version'));
    }

    public function test_A02_sync_queue_driver_fails_closed_without_inline_provider_call(): void
    {
        Http::fake();
        config()->set('queue.connections.ai_media_database.driver', 'sync');

        $file = UploadedFile::fake()->createWithContent('bill.png', $this->validPngBytes());
        $res = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', [
                'file' => $file,
                'client_request_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json']);

        $res->assertStatus(503);
        $res->assertJsonPath('error_code', 'ASYNC_QUEUE_UNAVAILABLE');
        Http::assertNothingSent();
        $this->assertSame(0, AiMediaProcessingTask::query()->count());
    }

    public function test_A03_invalid_mime_svg_and_corrupt_png_are_rejected_at_admission(): void
    {
        Http::fake();

        $svg = UploadedFile::fake()->createWithContent('evil.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $resSvg = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', ['file' => $svg, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $resSvg->assertStatus(422);
        $resSvg->assertJsonPath('error_code', 'INVALID_MAGIC_BYTES');

        $corrupt = UploadedFile::fake()->createWithContent('corrupt.png', "\x89PNG\r\n\x1a\n\x00\x00");
        $resCorrupt = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', ['file' => $corrupt, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $resCorrupt->assertStatus(422);
        $resCorrupt->assertJsonPath('error_code', 'CORRUPTED_MEDIA');

        $hugePixels = UploadedFile::fake()->createWithContent('huge.png', "\x89PNG\r\n\x1a\n" . 'not-real-png-ihdr');
        $resHuge = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', ['file' => $hugePixels, 'client_request_id' => (string) Str::uuid()], ['Accept' => 'application/json']);
        $resHuge->assertStatus(422);
        $resHuge->assertJsonPath('error_code', 'IMAGE_PIXELS_EXCEEDED');

        Http::assertNothingSent();
        $this->assertSame(0, AiMediaAsset::query()->count());
    }

    public function test_A11_A12_idempotent_replay_conflict_detection_and_lost_response_recovery(): void
    {
        Http::fake();
        $key = (string) Str::uuid();
        $file1 = UploadedFile::fake()->createWithContent('bill1.png', $this->validPngBytes());

        $first = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', [
                'file' => $file1,
                'client_request_id' => $key,
                'companion_text' => 'purchase bill',
            ], ['Accept' => 'application/json']);
        $first->assertStatus(202);
        $taskId = $first->json('processing_task_id');
        $mediaId = $first->json('media_id');

        // Replay with identical bytes + instruction returns same task & media with single quota consumption
        $fileReplay = UploadedFile::fake()->createWithContent('bill1.png', $this->validPngBytes());
        $replay = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', [
                'file' => $fileReplay,
                'client_request_id' => $key,
                'companion_text' => 'purchase bill',
            ], ['Accept' => 'application/json']);
        $replay->assertStatus(202);
        $replay->assertJsonPath('processing_task_id', $taskId);
        $replay->assertJsonPath('media_id', $mediaId);
        $replay->assertJsonPath('idempotent_replay', true);
        $this->assertSame(1, AiMediaAsset::query()->where('company_id', $this->companyA->id)->count());

        // Same key with changed instruction conflicts 409
        $fileConflict = UploadedFile::fake()->createWithContent('bill1.png', $this->validPngBytes());
        $conflict = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', [
                'file' => $fileConflict,
                'client_request_id' => $key,
                'companion_text' => 'different instruction',
            ], ['Accept' => 'application/json']);
        $conflict->assertStatus(409);
        $conflict->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');

        // Lost admission response recovery by client_request_id
        $recover = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->getJson('/ai/media/processing-tasks?client_request_id=' . urlencode($key));
        $recover->assertStatus(200);
        $recover->assertJsonPath('tasks.0.processing_task_id', $taskId);
    }

    public function test_A26_A29_cross_company_branch_isolation_and_xss_filename_escaping(): void
    {
        Http::fake();
        $xssFile = UploadedFile::fake()->createWithContent('<script>alert(1)</script>bill.png', $this->validPngBytes());
        $res = $this->actingAs($this->adminA)->withSession($this->sessA())
            ->post('/ai/media/processing-tasks', [
                'file' => $xssFile,
                'client_request_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json']);

        $res->assertStatus(202);
        $taskId = $res->json('processing_task_id');
        $this->assertStringNotContainsString('<script>', (string) $res->json('file_name'));

        // Foreign company B cannot poll, retry, or cancel
        $this->actingAs($this->adminB)->withSession([
            'company_id' => $this->companyB->id,
            'branch_id' => $this->branchB,
            'financial_year_id' => $this->fyB,
        ])->getJson("/ai/media/processing-tasks/{$taskId}")->assertStatus(403);

        // Same company A, different branch A2 cannot access branch A1 task
        $this->actingAs($this->adminA)->withSession($this->sessA($this->branchA2))
            ->getJson("/ai/media/processing-tasks/{$taskId}")->assertStatus(403);

        // Listing requires thread_id or client_request_id (never dumps all company tasks)
        $this->actingAs($this->adminA)->withSession($this->sessA())
            ->getJson('/ai/media/processing-tasks')->assertStatus(422);
    }
}
