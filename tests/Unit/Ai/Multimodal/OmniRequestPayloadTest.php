<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Multimodal;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Multimodal\Clients\NvidiaOmniPerceptionClient;
use App\Ai\Multimodal\Enums\ModelPurpose;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Services\AiFeatureGate;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

final class OmniRequestPayloadTest extends TestCase
{
    private function principal(): AiPrincipal
    {
        return new AiPrincipal(
            actorId: 10,
            companyId: 1,
            branchId: 1,
            financialYearId: 1,
            permittedToolNames: ['receivables.outstanding.v1'],
            requestId: '11111111-1111-1111-1111-111111111111',
            isActive: true,
        );
    }

    public function test_multimodal_is_disabled_by_default_and_never_calls_provider(): void
    {
        config()->set('ai_multimodal.enabled', false);
        Http::fake();

        $gate = app(AiFeatureGate::class);
        $this->assertFalse($gate->isMultimodalEnabledForCompany(1));

        $client = new NvidiaOmniPerceptionClient(
            app(ApprovedModelGateway::class),
            $gate,
        );

        try {
            $client->extractStructuredFromImages(
                $this->principal(),
                '22222222-2222-2222-2222-222222222222',
                [['mime' => 'image/jpeg', 'base64' => base64_encode('fake-img')]],
                'system prompt',
                'user prompt',
            );
            $this->fail('Expected MultimodalException when disabled.');
        } catch (MultimodalException $e) {
            $this->assertSame('MULTIMODAL_DISABLED', $e->errorCode);
            $this->assertSame(503, $e->httpStatus);
        }

        Http::assertNothingSent();
    }

    public function test_gateway_strictly_isolates_reasoning_and_perception_models(): void
    {
        config()->set('erp_ai.provider', 'nvidia');
        config()->set('erp_ai.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');

        $gateway = app(ApprovedModelGateway::class);

        $reasoning = $gateway->resolveApprovedModel();
        $this->assertSame('nvidia/nemotron-3.5-lightning-30b-a3b', $reasoning['model']);
        $this->assertFalse($gateway->isApproved('nvidia', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning'));

        $perception = $gateway->resolveApprovedPerceptionModel();
        $this->assertSame('nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', $perception['model']);

        $gateway->assertPurposeAllowsModel(ModelPurpose::PerceptionVision, 'nvidia', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        $gateway->assertPurposeAllowsModel(ModelPurpose::PerceptionAudio, 'nvidia', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        $gateway->assertPurposeAllowsModel(ModelPurpose::Reasoning, 'nvidia', 'nvidia/nemotron-3.5-lightning-30b-a3b');

        $this->expectException(InvalidArgumentException::class);
        $gateway->assertPurposeAllowsModel(ModelPurpose::Reasoning, 'nvidia', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
    }

    public function test_gateway_rejects_unapproved_perception_model_override(): void
    {
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');

        $this->expectException(InvalidArgumentException::class);
        app(ApprovedModelGateway::class)->resolveApprovedPerceptionModel();
    }

    public function test_omni_payload_excludes_tools_and_enforces_deterministic_settings(): void
    {
        $client = new NvidiaOmniPerceptionClient(
            app(ApprovedModelGateway::class),
            app(AiFeatureGate::class),
        );

        $imagePayload = $client->buildPayload(
            model: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
            systemPrompt: 'Extract JSON only.',
            userContent: [
                ['type' => 'text', 'text' => 'Read invoice'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,ZmFrZQ==']],
            ],
        );

        $this->assertArrayNotHasKey('tools', $imagePayload);
        $this->assertArrayNotHasKey('tool_choice', $imagePayload);
        $this->assertArrayNotHasKey('functions', $imagePayload);
        $this->assertSame(0.0, $imagePayload['temperature']);
        $this->assertFalse($imagePayload['stream']);
        $this->assertSame('nvidia/nemotron-3-nano-omni-30b-a3b-reasoning', $imagePayload['model']);
        $this->assertSame('image_url', $imagePayload['messages'][1]['content'][1]['type']);

        $audioPayload = $client->buildPayload(
            model: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
            systemPrompt: 'Transcribe WAV cleanly.',
            userContent: [
                ['type' => 'text', 'text' => 'Audio voice instruction'],
                ['type' => 'audio_url', 'audio_url' => ['url' => 'data:audio/wav;base64,UklGRg==']],
            ],
        );

        $this->assertArrayNotHasKey('tools', $audioPayload);
        $this->assertArrayNotHasKey('tool_choice', $audioPayload);
        $this->assertSame('audio_url', $audioPayload['messages'][1]['content'][1]['type']);
        $this->assertSame('data:audio/wav;base64,UklGRg==', $audioPayload['messages'][1]['content'][1]['audio_url']['url']);
        $this->assertSame(['enable_thinking' => false], $imagePayload['chat_template_kwargs']);
    }

    public function test_omni_payload_allows_custom_chat_template_kwargs(): void
    {
        $client = new NvidiaOmniPerceptionClient(
            app(ApprovedModelGateway::class),
            app(AiFeatureGate::class),
        );

        $payload = $client->buildPayload(
            model: 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning',
            systemPrompt: 'Extract JSON only.',
            userContent: [['type' => 'text', 'text' => 'Read invoice']],
            options: ['chat_template_kwargs' => ['custom_key' => 'custom_val']],
        );

        $this->assertSame(['custom_key' => 'custom_val'], $payload['chat_template_kwargs']);
    }

    public function test_provider_rejection_http_400_is_classified_as_provider_http_400(): void
    {
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai.providers.nvidia.key', 'nv-test-key');

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response(['error' => 'invalid_request_parameter'], 400),
        ]);

        $gate = $this->createStub(AiFeatureGate::class);
        $gate->method('isMultimodalEnabledForCompany')->willReturn(true);

        $gateway = $this->createStub(ApprovedModelGateway::class);
        $gateway->method('admitPerceptionDispatch')->willReturnCallback(function ($principal, $date, $purpose, $bytes, $cb, $mediaId = null) {
            return $cb('nvidia', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        });

        $client = new NvidiaOmniPerceptionClient($gateway, $gate);

        try {
            $client->extractStructuredFromImages(
                $this->principal(),
                '33333333-3333-3333-3333-333333333333',
                [['mime' => 'image/jpeg', 'base64' => base64_encode('fake-img')]],
                'system prompt',
                'user prompt',
            );
            $this->fail('Expected MultimodalException for HTTP 400.');
        } catch (MultimodalException $e) {
            $this->assertSame('PROVIDER_HTTP_400', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_truncated_perception_response_is_classified(): void
    {
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai.providers.nvidia.key', 'nv-test-key');

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"document_type": "purchase_bill", "lines": [',
                    ],
                    'finish_reason' => 'length',
                ]],
            ], 200),
        ]);

        $gate = $this->createStub(AiFeatureGate::class);
        $gate->method('isMultimodalEnabledForCompany')->willReturn(true);

        $gateway = $this->createStub(ApprovedModelGateway::class);
        $gateway->method('admitPerceptionDispatch')->willReturnCallback(function ($principal, $date, $purpose, $bytes, $cb, $mediaId = null) {
            return $cb('nvidia', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        });

        $client = new NvidiaOmniPerceptionClient($gateway, $gate);

        try {
            $client->extractStructuredFromImages(
                $this->principal(),
                '44444444-4444-4444-4444-444444444444',
                [['mime' => 'image/jpeg', 'base64' => base64_encode('fake-img')]],
                'system prompt',
                'user prompt',
            );
            $this->fail('Expected MultimodalException for truncated length.');
        } catch (MultimodalException $e) {
            $this->assertSame('TRUNCATED_PERCEPTION_RESPONSE', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_empty_perception_response_is_classified(): void
    {
        config()->set('ai_multimodal.enabled', true);
        config()->set('ai_multimodal.provider', 'nvidia');
        config()->set('ai_multimodal.model', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        config()->set('ai.providers.nvidia.key', 'nv-test-key');

        Http::fake([
            'integrate.api.nvidia.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $gate = $this->createStub(AiFeatureGate::class);
        $gate->method('isMultimodalEnabledForCompany')->willReturn(true);

        $gateway = $this->createStub(ApprovedModelGateway::class);
        $gateway->method('admitPerceptionDispatch')->willReturnCallback(function ($principal, $date, $purpose, $bytes, $cb, $mediaId = null) {
            return $cb('nvidia', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning');
        });

        $client = new NvidiaOmniPerceptionClient($gateway, $gate);

        try {
            $client->extractStructuredFromImages(
                $this->principal(),
                '55555555-5555-5555-5555-555555555555',
                [['mime' => 'image/jpeg', 'base64' => base64_encode('fake-img')]],
                'system prompt',
                'user prompt',
            );
            $this->fail('Expected MultimodalException for empty response.');
        } catch (MultimodalException $e) {
            $this->assertSame('EMPTY_PERCEPTION_RESPONSE', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }
    }
}

