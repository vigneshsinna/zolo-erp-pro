<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use App\Jobs\Ai\CleanupExpiredMediaJob;
use App\Jobs\Ai\ExtractMediaJob;
use App\Models\Accounting\FiscalYear;
use App\Models\AiMediaAsset;
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

final class MediaUploadSecurityTest extends TestCase
{
    use UsesAiMysql;

    private Company $companyA;
    private Company $companyB;
    private int $branchA;
    private int $branchB;
    private int $fyA;
    private int $fyB;
    private User $adminA;
    private User $adminB;
    private User $unpermittedUser;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai.providers.nvidia.key', 'nvapi-test-key-should-never-leak-anywhere');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');

        $this->companyA = Company::create([
            'legal_name' => 'Alpha Co ' . uniqid(),
            'trade_name' => 'Alpha Co',
            'code' => 'ALP-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchA = $this->companyA->branches()->create([
            'name' => 'Alpha Main',
            'code' => 'BRA-' . uniqid(),
            'is_active' => true,
        ])->id;
        $this->fyA = FiscalYear::create([
            'company_id' => $this->companyA->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->companyB = Company::create([
            'legal_name' => 'Beta Co ' . uniqid(),
            'trade_name' => 'Beta Co',
            'code' => 'BET-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchB = $this->companyB->branches()->create([
            'name' => 'Beta Main',
            'code' => 'BRB-' . uniqid(),
            'is_active' => true,
        ])->id;
        $this->fyB = FiscalYear::create([
            'company_id' => $this->companyB->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->adminA = User::create([
            'name' => 'Admin A',
            'email' => 'mm_admin_a_' . uniqid() . '@example.com',
            'phone' => '9800011111',
            'password' => bcrypt('secret'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->companyA->users()->attach($this->adminA->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->companyA->id,
            'user_id' => $this->adminA->id,
            'branch_id' => $this->branchA,
        ]);

        $this->adminB = User::create([
            'name' => 'Admin B',
            'email' => 'mm_admin_b_' . uniqid() . '@example.com',
            'phone' => '9800022222',
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

        $this->unpermittedUser = User::create([
            'name' => 'No AI User',
            'email' => 'mm_noai_' . uniqid() . '@example.com',
            'phone' => '9800033333',
            'password' => bcrypt('secret'),
            'role_id' => 99,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->companyA->users()->attach($this->unpermittedUser->id, ['is_default' => true]);
        DB::table('company_user_branches')->insert([
            'company_id' => $this->companyA->id,
            'user_id' => $this->unpermittedUser->id,
            'branch_id' => $this->branchA,
        ]);

        $gate = app(AiFeatureGate::class);
        $gate->setCompanySetting($this->companyA->id, true, false, ['multimodal_enabled' => true, 'daily_media_upload_limit' => 100]);
        $gate->setCompanySetting($this->companyB->id, true, false, ['multimodal_enabled' => true, 'daily_media_upload_limit' => 100]);
    }

    public function test_disabled_multimodal_rejects_upload_without_provider_calls_and_leaves_text_chat_working(): void
    {
        config()->set('ai_multimodal.enabled', false);
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Total due is Rs. 0.00.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 12],
            ], 200),
        ]);

        $jpg = $this->makeTempJpeg();
        $file = new UploadedFile($jpg, 'invoice.jpg', 'image/jpeg', null, true);

        $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/media', ['file' => $file])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'MULTIMODAL_DISABLED');

        // Workspace HTML omits multimodal controls when disabled
        $workspace = $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->get('/ai')
            ->assertStatus(200);
        $workspace->assertDontSee('id="btn-ai-attach-media"', false);
        $workspace->assertDontSee('id="btn-ai-record-audio"', false);

        // Text-only chat works independently without calling Omni
        $chatRes = $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/chat', ['message' => 'Hello Copilot'])
            ->assertStatus(200);
        $this->assertSame('completed', $chatRes->json('status'));
        Http::assertSent(fn ($req) => ($req->data()['model'] ?? '') === 'nvidia/nemotron-3.5-lightning-30b-a3b');
        Http::assertNotSent(fn ($req) => str_contains((string) ($req->data()['model'] ?? ''), 'omni'));

        @unlink($jpg);
    }

    public function test_remote_url_ssrf_and_unauthorized_user_are_rejected(): void
    {
        Http::fake();
        $jpg = $this->makeTempJpeg();
        $file = new UploadedFile($jpg, 'invoice.jpg', 'image/jpeg', null, true);

        // Remote URL SSRF attempt
        $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/media', ['url' => 'http://169.254.169.254/latest/meta-data', 'file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REMOTE_URL_FORBIDDEN');

        // Unauthorized user without ai.use capability
        $file2 = new UploadedFile($jpg, 'invoice2.jpg', 'image/jpeg', null, true);
        $this->actingAs($this->unpermittedUser)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/media', ['file' => $file2])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');

        Http::assertNothingSent();
        @unlink($jpg);
    }

    public function test_private_storage_exif_stripping_preview_headers_and_cross_company_isolation(): void
    {
        $fakeNvidia = function () {
            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'document_type' => 'purchase_order',
                            'supplier_name' => 'Optic Supplies Co',
                            'invoice_number' => 'PO-901',
                            'invoice_date' => '2026-11-12',
                            'subtotal' => 500,
                            'tax_amount' => 90,
                            'total_amount' => 590,
                            'confidence' => 0.94,
                            'warnings' => [],
                            'lines' => [
                                ['page' => 1, 'description' => 'Progressive Blank', 'quantity' => 5, 'uom' => 'Pairs', 'unit_price' => 100, 'line_total' => 500, 'confidence' => 0.94],
                            ],
                        ]),
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 250, 'completion_tokens' => 90],
            ], 200);
        };
        Http::fake(['integrate.api.nvidia.com/*' => $fakeNvidia]);

        $jpg = $this->makeTempJpeg(injectExifPayload: 'EXIF_GPS_PRIVATE_MARKER_XYZ');
        $file = new UploadedFile($jpg, 'bill_scan.jpg', 'image/jpeg', null, true);

        $upload = $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/media', ['file' => $file, 'extract_now' => true])
            ->assertStatus(201);

        $mediaId = $upload->json('media.id');
        $asset = AiMediaAsset::findOrFail($mediaId);

        // Must be stored exclusively on private disk, never inside public/
        $this->assertSame('ai_private', $asset->private_disk);
        $this->assertStringStartsWith('company_' . $this->companyA->id . '/', $asset->private_key);
        $this->assertTrue(Storage::disk('ai_private')->exists($asset->private_key));
        $storedBytes = Storage::disk('ai_private')->get($asset->private_key);
        $this->assertStringNotContainsString('EXIF_GPS_PRIVATE_MARKER_XYZ', (string) $storedBytes);

        // Authorized private preview returns strict no-store cache headers
        $previewRes = $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->get('/api/v1/ai/media/' . $mediaId . '/preview')
            ->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $previewRes->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $previewRes->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $previewRes->headers->get('X-Content-Type-Options'));

        // Cross-company isolation: Admin B must receive 403 across all endpoints
        $sessB = ['company_id' => $this->companyB->id, 'branch_id' => $this->branchB, 'financial_year_id' => $this->fyB];
        $this->actingAs($this->adminB)->withSession($sessB)->getJson("/api/v1/ai/media/{$mediaId}")->assertStatus(403);
        $this->actingAs($this->adminB)->withSession($sessB)->get("/api/v1/ai/media/{$mediaId}/preview")->assertStatus(403);
        $this->actingAs($this->adminB)->withSession($sessB)->postJson("/api/v1/ai/media/{$mediaId}/extract")->assertStatus(403);
        $this->actingAs($this->adminB)->withSession($sessB)->patchJson("/api/v1/ai/media/{$mediaId}/extraction", ['expected_revision' => 1])->assertStatus(403);
        $this->actingAs($this->adminB)->withSession($sessB)->postJson("/api/v1/ai/media/{$mediaId}/confirm")->assertStatus(403);
        $this->actingAs($this->adminB)->withSession($sessB)->deleteJson("/api/v1/ai/media/{$mediaId}")->assertStatus(403);

        // Cross-company chat media attachment blocked
        $this->actingAs($this->adminB)->withSession($sessB)
            ->postJson('/api/v1/ai/chat', ['message' => 'Summarize attachment', 'media_ids' => [$mediaId]])
            ->assertStatus(404);

        @unlink($jpg);
    }

    public function test_idempotency_daily_quota_and_cleanup_expiry_lifecycle(): void
    {
        app(AiFeatureGate::class)->setCompanyMultimodal($this->companyA->id, true, 1);
        Http::fake();

        $jpg1 = $this->makeTempJpeg();
        $idemKey = 'idem-' . Str::uuid();

        $r1 = $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg1, 'doc1.jpg', 'image/jpeg', null, true),
                'extract_now' => false,
            ], ['X-Idempotency-Key' => $idemKey])
            ->assertStatus(201);

        // Same idempotency key replays exact asset even when daily limit = 1
        $jpgReplay = $this->makeTempJpeg();
        $r2 = $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpgReplay, 'doc1.jpg', 'image/jpeg', null, true),
                'extract_now' => false,
            ], ['X-Idempotency-Key' => $idemKey])
            ->assertStatus(200);
        $this->assertSame($r1->json('media.id'), $r2->json('media.id'));
        $this->assertTrue($r2->json('idempotent_replay'));

        // New upload without idempotency key exceeds daily_media_upload_limit = 1
        $jpg2 = $this->makeTempJpeg();
        $this->actingAs($this->adminA)
            ->withSession(['company_id' => $this->companyA->id, 'branch_id' => $this->branchA, 'financial_year_id' => $this->fyA])
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($jpg2, 'doc2.jpg', 'image/jpeg', null, true),
                'extract_now' => false,
            ])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'DAILY_MEDIA_LIMIT_EXCEEDED');

        // Expiry race: mark asset expired -> ExtractMediaJob does not invoke Omni; CleanupExpiredMediaJob purges file
        $asset = AiMediaAsset::findOrFail($r1->json('media.id'));
        $privateKey = $asset->private_key;
        $asset->update(['expires_at' => now('UTC')->subMinutes(5)]);

        app()->call([
            new ExtractMediaJob($this->companyA->id, $this->adminA->id, $this->branchA, $this->fyA, $asset->id),
            'handle',
        ]);
        Http::assertNothingSent();

        $purgedCount = app()->call([new CleanupExpiredMediaJob(), 'handle']);
        $this->assertGreaterThanOrEqual(1, $purgedCount);
        $asset->refresh();
        $this->assertSame('purged', $asset->state);
        $this->assertNotNull($asset->deleted_at_policy);
        $this->assertFalse(Storage::disk('ai_private')->exists($privateKey));

        @unlink($jpg1);
        @unlink($jpgReplay);
        @unlink($jpg2);
    }

    private function makeTempJpeg(?string $injectExifPayload = null): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zolo_f_img_' . uniqid() . '.jpg';
        $im = imagecreatetruecolor(120, 80);
        $bg = imagecolorallocate($im, 250, 250, 250);
        imagefilledrectangle($im, 0, 0, 120, 80, $bg);
        imagejpeg($im, $path, 85);
        imagedestroy($im);

        if ($injectExifPayload !== null) {
            file_put_contents($path, (string) file_get_contents($path) . $injectExifPayload);
        }

        return $path;
    }
}
