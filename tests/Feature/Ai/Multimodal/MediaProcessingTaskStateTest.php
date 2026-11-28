<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Multimodal\Contracts\PdfRasterizerInterface;
use App\Ai\Multimodal\Services\MediaProcessingOrchestrator;
use App\Ai\Multimodal\Services\MediaProcessingTaskService;
use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\AiMediaProcessingTask;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class MediaProcessingTaskStateTest extends TestCase
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
        config()->set('erp_ai.read_only', false);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-state-test-key');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.allow_direct_purchase_bill', true);
        config()->set('ai_multimodal.allow_direct_sales_invoice', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_multimodal.storage_disk', 'ai_private');
        config()->set('ai_multimodal.async.enabled', true);
        config()->set('ai_multimodal.async.queue_connection', 'ai_media_database');
        config()->set('ai_multimodal.async.queue_name', 'ai-perception');
        config()->set('ai_multimodal.async.provider_immediate_retries', 0);
        config()->set('ai_actions.foundation_enabled', true);
        config()->set('ai_business_knowledge.enabled', true);
        config()->set('ai_business_knowledge.opticals_enabled', true);
        config()->set('queue.connections.ai_media_database.driver', 'database');
        config()->set('queue.connections.ai_media_database.retry_after', 240);

        $this->company = Company::create([
            'legal_name' => 'Optical State Co ' . uniqid(),
            'trade_name' => 'Optical State Co',
            'code' => 'OSC-' . strtoupper(substr(uniqid(), -5)),
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

        DB::table('company_industry_settings')->insert([
            'company_id' => $this->company->id,
            'profile_key' => 'opticals',
            'subtype' => 'wholesale',
            'settings_json' => json_encode(['lens_powers' => true]),
            'updated_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'State Admin',
            'email' => 'state_' . uniqid() . '@example.com',
            'phone' => '9822200001',
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
            'daily_token_limit' => 300000,
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

    public function test_A05_multipage_pdf_partial_failure_checkpoints_page1_and_retries_only_page2(): void
    {
        $pngBytes = $this->validPngBytes();
        $this->app->bind(PdfRasterizerInterface::class, function () use ($pngBytes) {
            return new class($pngBytes) implements PdfRasterizerInterface {
                public function __construct(private readonly string $png) {}
                public function rasterize(string $pdfPath, string $outputDir, int $maxPages = 5, int $dpi = 175): array
                {
                    @mkdir($outputDir, 0750, true);
                    $p1 = $outputDir . '/page_1.png';
                    $p2 = $outputDir . '/page_2.png';
                    file_put_contents($p1, $this->png);
                    file_put_contents($p2, $this->png);
                    return ['page_count' => 2, 'page_paths' => [$p1, $p2]];
                }
            };
        });

        $calls = 0;
        Http::fake([
            'integrate.api.nvidia.com/*' => function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    return Http::response([
                        'choices' => [[
                            'message' => ['role' => 'assistant', 'content' => json_encode([
                                'document_type' => 'sales_invoice',
                                'supplier_name' => 'Optical State Co',
                                'buyer_name' => 'Vision Care',
                                'invoice_number' => 'SI-100',
                                'lines' => [['description' => 'Essilor 1.56', 'lr' => 'RE', 'sph' => '-2.00', 'qty' => 2, 'unit_price' => 300, 'line_total' => 600]],
                            ])],
                            'finish_reason' => 'stop',
                        ]],
                        'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60],
                    ], 200);
                }
                if ($calls === 2) {
                    return Http::response(['error' => 'rate_limit'], 429, ['Retry-After' => '15']);
                }
                return Http::response([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => json_encode([
                            'document_type' => 'sales_invoice',
                            'lines' => [['description' => 'Essilor 1.56', 'lr' => 'LE', 'sph' => '-1.75', 'qty' => 2, 'unit_price' => 300, 'line_total' => 600]],
                        ])],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 110, 'completion_tokens' => 55],
                ], 200);
            },
        ]);

        $pdf = UploadedFile::fake()->createWithContent('two_page.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', [
                'file' => $pdf,
                'companion_text' => 'create a sales bill',
                'client_request_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json']);
        $res->assertStatus(202);
        $taskId = $res->json('processing_task_id');

        $tasks = app(MediaProcessingTaskService::class);
        $orchestrator = app(MediaProcessingOrchestrator::class);

        $claimed1 = $tasks->claimLease($taskId, 'worker-1');
        $this->assertNotNull($claimed1);
        $orchestrator->process($claimed1);

        $afterFirst = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('retry_scheduled', $afterFirst->state);
        $this->assertArrayHasKey('1', $afterFirst->page_checkpoints_json ?? []);
        $this->assertArrayNotHasKey('2', $afterFirst->page_checkpoints_json ?? []);
        $this->assertSame(2, $calls);

        // Allow retry immediately and run second pass; page 1 MUST NOT be re-called
        $afterFirst->update(['next_retry_at' => now('UTC')->subSecond()]);
        $claimed2 = $tasks->claimLease($taskId, 'worker-2');
        $this->assertNotNull($claimed2);
        $orchestrator->process($claimed2);

        $this->assertSame(3, $calls, 'Page 1 checkpoint must prevent re-calling provider for page 1.');
        $final = AiMediaProcessingTask::query()->findOrFail($taskId);
        $this->assertSame('needs_input', $final->state);
        $this->assertSame('sales_invoice.create.v1', $final->resolved_action_key);
    }

    public function test_A08_A09_incoming_supplier_bill_with_sales_instruction_pauses_at_resolve_and_non_optical_uses_product_labels(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'document_type' => 'purchase_bill',
                        'supplier_name' => 'External Lens Vendor',
                        'buyer_name' => 'Optical State Co',
                        'invoice_number' => 'VND-55',
                        'total_amount' => 1200,
                        'lines' => [['description' => 'Stock Lens', 'lr' => 'RE', 'sph' => '-2.00', 'cyl' => '-0.50', 'axis' => 90, 'qty' => 2, 'unit_price' => 600, 'line_total' => 1200]],
                    ])],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 45],
            ], 200),
        ]);

        $file = UploadedFile::fake()->createWithContent('vendor.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', [
                'file' => $file,
                'companion_text' => 'create a sales bill',
                'client_request_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json']);
        $res->assertStatus(202);
        $taskId = $res->json('processing_task_id');

        $tasks = app(MediaProcessingTaskService::class);
        $claimed = $tasks->claimLease($taskId, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed);

        $poll = $this->actingAs($this->admin)->withSession($this->sess())
            ->getJson("/ai/media/processing-tasks/{$taskId}");
        $poll->assertStatus(200);
        $poll->assertJsonPath('state', 'needs_confirmation');
        $poll->assertJsonPath('stages.3.key', 'resolve');
        $poll->assertJsonPath('stages.3.state', 'needs_input');
        $poll->assertJsonPath('stages.4.state', 'pending');
        $poll->assertJsonPath('stages.5.state', 'pending');
        $this->assertSame('source_role_conflict', $poll->json('result.status'));

        // Optical labels present on optical tenant
        $this->assertStringContainsString('lenses', strtolower((string) $poll->json('stages.3.label')));
    }

    public function test_A20_A27_cancel_before_dispatch_and_permission_revocation_while_queued(): void
    {
        Http::fake();
        $file1 = UploadedFile::fake()->createWithContent('cancel.png', $this->validPngBytes());
        $res1 = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', [
                'file' => $file1,
                'client_request_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json']);
        $res1->assertStatus(202);
        $taskId1 = $res1->json('processing_task_id');

        $cancelRes = $this->actingAs($this->admin)->withSession($this->sess())
            ->postJson("/ai/media/processing-tasks/{$taskId1}/cancel", ['expected_state_version' => 1]);
        $cancelRes->assertStatus(200);
        $cancelRes->assertJsonPath('state', 'cancelled');

        // Claiming a cancelled task must no-op
        $this->assertNull(app(MediaProcessingTaskService::class)->claimLease($taskId1, 'worker-1'));

        // Permission/multimodal revocation while queued fails closed on worker
        $file2 = UploadedFile::fake()->createWithContent('revoked.png', $this->validPngBytes());
        $res2 = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/processing-tasks', [
                'file' => $file2,
                'client_request_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json']);
        $taskId2 = $res2->json('processing_task_id');

        app(AiFeatureGate::class)->setCompanyMultimodal($this->company->id, false);
        $claimed2 = app(MediaProcessingTaskService::class)->claimLease($taskId2, 'worker-1');
        app(MediaProcessingOrchestrator::class)->process($claimed2);

        $failed = AiMediaProcessingTask::query()->findOrFail($taskId2);
        $this->assertSame('failed', $failed->state);
        $this->assertSame('MULTIMODAL_DISABLED', $failed->error_code);
        $this->assertFalse($failed->is_retryable);
        Http::assertNothingSent();
    }

    public function test_A41_legacy_sync_mode_preserved_when_async_flag_disabled(): void
    {
        config()->set('ai_multimodal.async.enabled', false);
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'document_type' => 'purchase_bill',
                        'supplier_name' => 'Legacy Vendor',
                        'lines' => [['description' => 'Item', 'qty' => 1, 'unit_price' => 50, 'line_total' => 50]],
                    ])],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 25],
            ], 200),
        ]);

        $file = UploadedFile::fake()->createWithContent('legacy.png', $this->validPngBytes());
        $res = $this->actingAs($this->admin)->withSession($this->sess())
            ->post('/ai/media/prepare', ['file' => $file, 'prepare_now' => '1'], ['Accept' => 'application/json']);

        $res->assertStatus(200);
        $this->assertArrayNotHasKey('processing_task_id', $res->json());
        $this->assertSame(0, AiMediaProcessingTask::query()->count());
    }
}
