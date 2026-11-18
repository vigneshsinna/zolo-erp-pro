<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Multimodal;

use App\Ai\Services\AiFeatureGate;
use App\Models\Accounting\FiscalYear;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\UsesAiMysql;
use Tests\TestCase;

final class AudioTranscriptionTest extends TestCase
{
    use UsesAiMysql;

    private Company $company;
    private int $branchId;
    private int $fyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai:rpm:nvidia');
        config()->set('erp_ai.enabled', true);
        config()->set('ai.providers.nvidia.key', 'nvapi-audio-secret');
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai_multimodal.max_audio_seconds', 10);

        $this->company = Company::create([
            'legal_name' => 'Voice Co ' . uniqid(),
            'trade_name' => 'Voice Co',
            'code' => 'VOI-' . uniqid(),
            'status' => 'active',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
        ]);
        $this->branchId = $this->company->branches()->create([
            'name' => 'Voice Main',
            'code' => 'BR-VOI-' . uniqid(),
            'is_active' => true,
        ])->id;
        $this->fyId = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => 'FY 2026-27',
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'status' => 'open',
        ])->id;

        $this->admin = User::create([
            'name' => 'Voice Admin',
            'email' => 'mm_voice_' . uniqid() . '@example.com',
            'phone' => '9822222222',
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

    public function test_silent_and_over_duration_audio_are_rejected_before_model_dispatch(): void
    {
        Http::fake();

        $silentPath = $this->createWav(durationSeconds: 1.0, amplitude: 0);
        $this->actingAs($this->admin)
            ->withSession(['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId])
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($silentPath, 'silent.wav', 'audio/wav', null, true),
                'extract_now' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SILENT_OR_EMPTY_AUDIO');

        // 12 seconds > configured 10s max_audio_seconds
        $longPath = $this->createWav(durationSeconds: 12.0, amplitude: 12000);
        $this->actingAs($this->admin)
            ->withSession(['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId])
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($longPath, 'toolong.wav', 'audio/wav', null, true),
                'extract_now' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'AUDIO_DURATION_EXCEEDED');

        Http::assertNothingSent();
        @unlink($silentPath);
        @unlink($longPath);
    }

    public function test_valid_wav_transcribes_via_omni_and_flags_number_negation_and_telemetry(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => function ($req) {
                $body = $req->data();
                $this->assertArrayNotHasKey('tools', $body);
                $this->assertSame('audio_url', $body['messages'][1]['content'][1]['type']);
                $this->assertStringStartsWith('data:audio/wav;base64,', $body['messages'][1]['content'][1]['audio_url']['url']);

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'transcript_text' => 'Create PO for fifty blue shirts, wait actually make that fifteen not fifty.',
                        'detected_language' => 'en',
                        'intent_guess' => 'purchase_order.create.v1',
                        'party_name' => 'Apex Textiles',
                        'warehouse_name' => 'Main Warehouse',
                        'confidence' => 0.88,
                        'items' => [
                            ['product_name' => 'Blue Shirt', 'quantity' => 15, 'uom' => 'PCS', 'unit_price' => 300],
                        ],
                    ])], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 420, 'completion_tokens' => 85],
                ], 200);
            },
        ]);

        $wavPath = $this->createWav(durationSeconds: 2.0, amplitude: 12000);
        $res = $this->actingAs($this->admin)
            ->withSession(['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId])
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($wavPath, 'voice_po.wav', 'audio/wav', null, true),
                'extract_now' => true,
            ])
            ->assertStatus(201);

        $ext = $res->json('extraction.extracted');
        $this->assertTrue($ext['language_supported']);
        $this->assertTrue($ext['requires_confirmation']);
        $this->assertContains('high_value_or_15_50_number_ambiguity', $ext['confirmation_reasons']);
        $this->assertContains('spoken_negation_or_correction', $ext['confirmation_reasons']);

        $inv = DB::table('ai_model_invocations')->where('media_id', $res->json('media.id'))->first();
        $this->assertNotNull($inv);
        $this->assertSame('perception_audio', $inv->model_purpose);

        @unlink($wavPath);
    }

    public function test_non_english_speech_marks_unsupported_and_warns_user(): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'transcript_text' => 'அன்வர் டெக்ஸ்டைல்ஸுக்கு பத்து மீட்டர் துணி வாங்கவும்',
                    'detected_language' => 'ta',
                    'intent_guess' => 'purchase_order.create.v1',
                    'confidence' => 0.72,
                    'items' => [],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 50],
            ], 200),
        ]);

        $wavPath = $this->createWav(durationSeconds: 1.5, amplitude: 10000);
        $res = $this->actingAs($this->admin)
            ->withSession(['company_id' => $this->company->id, 'branch_id' => $this->branchId, 'financial_year_id' => $this->fyId])
            ->postJson('/api/v1/ai/media', [
                'file' => new UploadedFile($wavPath, 'tamil_note.wav', 'audio/wav', null, true),
                'extract_now' => true,
            ])
            ->assertStatus(201);

        $ext = $res->json('extraction.extracted');
        $this->assertFalse($ext['language_supported']);
        $this->assertContains('unsupported_speech_language', $ext['confirmation_reasons']);

        @unlink($wavPath);
    }

    public function test_first_voice_po_requires_transcript_confirmation_and_enters_only_one_po_conversation(): void
    {
        config()->set('erp_ai.conversation_enabled', true);
        config()->set('ai_actions.foundation_enabled', true);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, true, [
            'multimodal_enabled' => true, 'daily_token_limit' => 200000,
        ]);
        $this->fakeVoiceTranscript('Please create a purchase order for ABC Traders', 'sales_order.create.v1');

        $this->actingAs($this->admin)->withSession([
            'company_id' => $this->company->id,
            'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
        ]);
        $thread = $this->postJson(route('api.v1.ai.threads.store'))->assertCreated()->json('id');
        $wav = $this->createWav(1.3, 10000);
        try {
            $prepared = $this->postJson(route('api.v1.ai.media.prepare'), [
                'file' => new UploadedFile($wav, 'voice_first_po.wav', 'audio/wav', null, true),
                'thread_id' => $thread,
                'companion_text' => 'Analyze this voice instruction',
            ])->assertOk()
                ->assertJsonPath('status', 'voice_intent_review_ready')
                ->assertJsonPath('intent_key', 'purchase_order.create.v1');
            $this->assertSame(0, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
            $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());

            $mediaId = $prepared->json('media.id');
            $revision = $prepared->json('extraction.revision');
            $this->postJson(route('api.v1.ai.chat'), [
                'thread_id' => $thread, 'message' => 'Please create a purchase order for ABC Traders',
                'client_turn_id' => (string) \Illuminate\Support\Str::uuid(),
                'media_ids' => [$mediaId], 'voice_extraction_revision' => $revision,
            ])->assertStatus(422);
            $this->assertSame(0, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());

            $confirmed = $this->postJson(route('api.v1.ai.media.confirm', ['id' => $mediaId]), [
                'expected_revision' => $revision,
                'transcript_text' => 'Please create a purchase order for ABC Traders',
            ])->assertOk();
            $started = $this->postJson(route('api.v1.ai.chat'), [
                'thread_id' => $thread, 'message' => 'Please create a purchase order for ABC Traders',
                'client_turn_id' => (string) \Illuminate\Support\Str::uuid(),
                'media_ids' => [$mediaId], 'voice_extraction_revision' => $confirmed->json('extraction.revision'),
            ])->assertOk()
                ->assertJsonPath('interaction_type', 'workflow_continuation')
                ->assertJsonPath('workflow.action_key', 'purchase_order.create.v1');

            $this->assertSame(1, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
            $this->assertSame('purchase_order.create.v1',
                DB::table('ai_workflow_tasks')->where('thread_id', $thread)->value('action_key'));
            $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
            $this->assertNotEmpty($started->json('workflow.task_id'));
            Http::assertSentCount(1);
        } finally {
            @unlink($wav);
        }
    }

    public function test_unknown_and_wrong_transaction_voice_never_fall_back_to_purchase_bill_or_po(): void
    {
        config()->set('erp_ai.conversation_enabled', true);
        config()->set('ai_actions.foundation_enabled', true);
        app(AiFeatureGate::class)->setCompanySetting($this->company->id, true, true, [
            'multimodal_enabled' => true, 'daily_token_limit' => 200000,
        ]);
        $this->actingAs($this->admin)->withSession([
            'company_id' => $this->company->id, 'branch_id' => $this->branchId,
            'financial_year_id' => $this->fyId,
        ]);

        foreach ([
            ['Create an order', 'purchase_order.create.v1', null],
            ['Create a purchase bill', 'purchase_order.create.v1', 'purchase_bill.create.v1'],
            ['Create a purchase order and purchase bill', 'purchase_order.create.v1', null],
        ] as [$transcript, $guessed, $expectedIntent]) {
            $this->fakeVoiceTranscript($transcript, $guessed);
            $thread = $this->postJson(route('api.v1.ai.threads.store'))->assertCreated()->json('id');
            $wav = $this->createWav(1.2, 10000);
            try {
                $prepared = $this->postJson(route('api.v1.ai.media.prepare'), [
                    'file' => new UploadedFile($wav, 'voice_ambiguous.wav', 'audio/wav', null, true),
                    'thread_id' => $thread,
                ])->assertOk()->assertJsonPath('status', 'voice_intent_review_ready')
                    ->assertJsonPath('intent_key', $expectedIntent);
                $confirmed = $this->postJson(route('api.v1.ai.media.confirm', ['id' => $prepared->json('media.id')]), [
                    'expected_revision' => $prepared->json('extraction.revision'),
                    'transcript_text' => $transcript,
                ])->assertOk();
                $this->postJson(route('api.v1.ai.chat'), [
                    'thread_id' => $thread, 'message' => $transcript,
                    'client_turn_id' => (string) \Illuminate\Support\Str::uuid(),
                    'media_ids' => [$prepared->json('media.id')],
                    'voice_extraction_revision' => $confirmed->json('extraction.revision'),
                ])->assertStatus(422);
                $this->assertSame(0, DB::table('ai_workflow_tasks')->where('thread_id', $thread)->count());
                $this->assertSame(0, DB::table('ai_action_proposals')->where('thread_id', $thread)->count());
            } finally {
                @unlink($wav);
            }
        }
    }

    public function test_voice_intent_resolver_does_not_use_model_guess_or_document_reference_as_action(): void
    {
        $resolver = app(\App\Ai\Conversation\VoiceIntentResolver::class);
        $this->assertSame('purchase_order.create.v1', $resolver->resolve('I need a purchase order for ABC Traders'));
        $this->assertSame('purchase_order.create.v1', $resolver->resolve('Create a PO'));
        $this->assertSame('purchase_bill.create.v1', $resolver->resolve('Please create a purchase bill'));
        $this->assertNull($resolver->resolve('What is the PO number on the supplier invoice?'));
        $this->assertNull($resolver->resolve('Create an order'));
        $this->assertNull($resolver->resolve('Create a purchase order and a purchase bill'));
    }

    private function fakeVoiceTranscript(string $text, string $intentGuess): void
    {
        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'transcript_text' => $text, 'detected_language' => 'en',
                    'intent_guess' => $intentGuess, 'confidence' => 0.94, 'items' => [],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 55],
            ], 200),
        ]);
    }

    private function createWav(float $durationSeconds, int $amplitude): string
    {
        $sampleRate = 8000;
        $totalSamples = (int) round($durationSeconds * $sampleRate);
        $pcm = '';
        for ($i = 0; $i < $totalSamples; $i++) {
            $sample = $amplitude === 0 ? 0 : (int) round($amplitude * sin(2 * M_PI * 440 * ($i / $sampleRate)));
            $pcm .= pack('s', $sample);
        }

        $dataSize = strlen($pcm);
        $header = 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVEfmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', 1)
            . pack('V', $sampleRate)
            . pack('V', $sampleRate * 2)
            . pack('v', 2)
            . pack('v', 16)
            . 'data'
            . pack('V', $dataSize);

        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zolo_wav_' . uniqid() . '.wav';
        file_put_contents($path, $header . $pcm);

        return $path;
    }
}

