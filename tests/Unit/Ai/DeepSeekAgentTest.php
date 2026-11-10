<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Agents\DeepSeekAgent;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Services\AiFeatureGate;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteImage;
use Tests\TestCase;

class DeepSeekAgentTest extends TestCase
{
    protected ApprovedModelGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new ApprovedModelGateway($this->app->make(AiFeatureGate::class));
    }

    public function test_approved_model_gateway_resolves_nvidia_and_deepseek_providers(): void
    {
        // 1. Test NVIDIA provider with deepseek-ai/deepseek-v4.1-flash
        config([
            'erp_ai.provider' => 'nvidia',
            'erp_ai.model' => 'deepseek-ai/deepseek-v4.1-flash',
        ]);
        $resolvedNvidia = $this->gateway->resolveApprovedModel();
        $this->assertSame('nvidia', $resolvedNvidia['provider']);
        $this->assertSame('deepseek-ai/deepseek-v4.1-flash', $resolvedNvidia['model']);

        // 2. Test DeepSeek provider with deepseek-ai/deepseek-v4.1-flash
        config([
            'erp_ai.provider' => 'deepseek',
            'erp_ai.model' => 'deepseek-ai/deepseek-v4.1-flash',
        ]);
        $resolvedDeepSeek = $this->gateway->resolveApprovedModel();
        $this->assertSame('deepseek', $resolvedDeepSeek['provider']);
        $this->assertSame('deepseek-ai/deepseek-v4.1-flash', $resolvedDeepSeek['model']);

        // 3. Test DeepSeek provider models
        $this->assertTrue($this->gateway->isApproved('deepseek', 'deepseek-chat'));
        $this->assertTrue($this->gateway->isApproved('deepseek', 'deepseek-reasoner'));
        $this->assertTrue($this->gateway->isApproved('deepseek', 'deepseek-ai/deepseek-v4.1-flash'));
        $this->assertTrue($this->gateway->isApproved('nvidia', 'deepseek-ai/deepseek-v4.1-flash'));

        // 4. Test fallback to default allowed model if invalid model requested
        config([
            'erp_ai.provider' => 'nvidia',
            'erp_ai.model' => 'unapproved-model',
        ]);
        $fallback = $this->gateway->resolveApprovedModel();
        $this->assertSame('nvidia', $fallback['provider']);
        $this->assertSame('deepseek-ai/deepseek-v4.1-flash', $fallback['model']);
    }

    public function test_deepseek_agent_client_config_matches_nvidia_nim_spec(): void
    {
        config([
            'ai.providers.nvidia.url' => 'https://integrate.api.nvidia.com/v1',
            'ai.providers.nvidia.key' => 'nvapi-test-key-12345',
        ]);

        $clientConfig = DeepSeekAgent::makeClientConfig();

        $this->assertSame('https://integrate.api.nvidia.com/v1', $clientConfig['base_url']);
        $this->assertSame('deepseek-ai/deepseek-v4.1-flash', $clientConfig['model']);
        $this->assertSame('nvapi-test-key-12345', $clientConfig['api_key']);
    }

    public function test_deepseek_agent_creates_image_attachments_from_data_uri_and_url(): void
    {
        // 1. Data URI with base64
        $sampleBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $dataUri = 'data:image/png;base64,' . $sampleBase64;
        $attachment = DeepSeekAgent::createImageAttachment($dataUri);

        $this->assertInstanceOf(Base64Image::class, $attachment);
        $this->assertSame($sampleBase64, $attachment->base64);
        $this->assertSame('image/png', $attachment->mime);

        // 2. Remote URL
        $url = 'https://example.com/scenery.jpg';
        $remoteAttachment = DeepSeekAgent::createImageAttachment($url);
        $this->assertInstanceOf(RemoteImage::class, $remoteAttachment);
        $this->assertSame($url, $remoteAttachment->url);

        // 3. Raw Base64 string
        $rawAttachment = DeepSeekAgent::createImageAttachment($sampleBase64, 'image/jpeg');
        $this->assertInstanceOf(Base64Image::class, $rawAttachment);
        $this->assertSame('image/jpeg', $rawAttachment->mime);

        // 4. Local file path is rejected for security
        try {
            DeepSeekAgent::createImageAttachment(base_path('composer.json'));
            $this->fail('Expected InvalidArgumentException for local file path');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // 5. Invalid input throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        DeepSeekAgent::createImageAttachment('not a valid image string @#$%^&*');
    }

    public function test_deepseek_agent_instructions_and_tools_scoping(): void
    {
        // 1. Standalone agent without principal has no tools
        $agent = new DeepSeekAgent();
        $this->assertStringContainsString('deepseek-ai/deepseek-v4.1-flash', (string) $agent->instructions());
        $this->assertSame([], $agent->tools());

        // 2. Agent with principal with permitted tool
        $principal = new AiPrincipal(
            actorId: 1,
            companyId: 10,
            branchId: 20,
            financialYearId: 30,
            permittedToolNames: ['receivables.outstanding.v1'],
            requestId: '018f2f9b-1111-7000-8000-000000000001',
            effectiveRole: 'accountant'
        );

        $agentWithPrincipal = new DeepSeekAgent($principal);
        $tools = $agentWithPrincipal->tools();
        $this->assertCount(1, $tools);
        $this->assertInstanceOf(\App\Ai\Tools\GenericSdkTool::class, $tools[0]);
        $this->assertSame('CustomerOutstanding', $tools[0]->name());
    }

    public function test_deepseek_agent_prompt_execution_with_ai_fake(): void
    {
        $expectedAnswer = 'The dirt path winds steadily uphill through tall pine trees and rugged rocks. In the background, majestic snow-covered mountain peaks rise beneath a clear morning sky.';

        Ai::fakeAgent(DeepSeekAgent::class, [$expectedAnswer]);

        $agent = new DeepSeekAgent();
        $sampleDataUri = 'data:image/jpeg;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $prompt = 'Describe the path in this image and the landscape around it in two sentences.';
        $response = $agent->askWithImage($prompt, $sampleDataUri);

        $this->assertSame($expectedAnswer, $response->text);

        Ai::assertAgentWasPrompted(DeepSeekAgent::class, function ($receivedPrompt) use ($prompt) {
            return $receivedPrompt->prompt === $prompt && count($receivedPrompt->attachments) === 1;
        });
    }
}

