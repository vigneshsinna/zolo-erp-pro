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
    }
}

