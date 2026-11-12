<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Clients;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Gateway\ApprovedModelGateway;
use App\Ai\Multimodal\Contracts\PerceptionClientInterface;
use App\Ai\Multimodal\DTOs\PerceptionResult;
use App\Ai\Multimodal\Enums\ModelPurpose;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use App\Ai\Services\AiFeatureGate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Purpose-scoped client for NVIDIA Nemotron 3 Nano Omni perception calls.
 * Enforces tool-free payloads, Gateway rate/token budgeting, and secret-free errors.
 */
final class NvidiaOmniPerceptionClient implements PerceptionClientInterface
{
    public function __construct(
        protected ApprovedModelGateway $gateway,
        protected AiFeatureGate $featureGate,
    ) {}

    public function extractStructuredFromImages(
        AiPrincipal $principal,
        string $mediaId,
        array $images,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
    ): PerceptionResult {
        if ($images === []) {
            throw MultimodalException::corruptedMedia('No rendered pages or image frames were supplied for extraction.');
        }

        $content = [['type' => 'text', 'text' => $userPrompt]];
        $estimatedBytes = strlen($systemPrompt) + strlen($userPrompt);
        $validFrames = 0;
        foreach ($images as $image) {
            $mime = (string) ($image['mime'] ?? 'image/jpeg');
            $base64 = (string) ($image['base64'] ?? '');
            if ($base64 === '') {
                continue;
            }
            // Multimodal vision tokenization scales with image patches (~2k tokens/page), not raw base64 size.
            $validFrames++;
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $mime . ';base64,' . $base64,
                ],
            ];
        }
        $estimatedBytes += ($validFrames * 8192);

        return $this->dispatchPerception(
            principal: $principal,
            mediaId: $mediaId,
            purpose: ModelPurpose::PerceptionVision,
            systemPrompt: $systemPrompt,
            userContent: $content,
            estimatedInputBytes: $estimatedBytes,
            options: $options,
        );
    }

    public function transcribeWavAudio(
        AiPrincipal $principal,
        string $mediaId,
        string $wavBinary,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
    ): PerceptionResult {
        if ($wavBinary === '') {
            throw MultimodalException::silentAudio();
        }

        $base64Audio = base64_encode($wavBinary);
        $content = [
            ['type' => 'text', 'text' => $userPrompt],
            [
                'type' => 'input_audio',
                'input_audio' => [
                    'data' => $base64Audio,
                    'format' => 'wav',
                ],
            ],
        ];

        // 16kHz 16-bit mono WAV = 32KB/s; reserve ~250 tokens/sec (~1KB byte-equiv/s), settled to actual usage on response.
        $estimatedAudioBytes = min(32768, max(2048, (int) ceil(strlen($wavBinary) / 32)));

        return $this->dispatchPerception(
            principal: $principal,
            mediaId: $mediaId,
            purpose: ModelPurpose::PerceptionAudio,
            systemPrompt: $systemPrompt,
            userContent: $content,
            estimatedInputBytes: strlen($systemPrompt) + strlen($userPrompt) + $estimatedAudioBytes,
            options: $options,
        );
    }

    /**
     * Build a strictly tool-free Chat Completions payload for testing or dispatch.
     *
     * @param array<int, array<string, mixed>> $userContent
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function buildPayload(
        string $model,
        string $systemPrompt,
        array $userContent,
        array $options = [],
    ): array {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => (float) ($options['temperature'] ?? 0.0),
            'max_tokens' => max(256, (int) ($options['max_tokens'] ?? config('ai_multimodal.max_extraction_tokens', 4096))),
            'stream' => false,
        ];

        // Non-negotiable invariant: Omni perception calls never declare ERP write tools.
        unset($payload['tools'], $payload['tool_choice'], $payload['functions']);

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $userContent
     * @param array<string, mixed> $options
     */
    protected function dispatchPerception(
        AiPrincipal $principal,
        string $mediaId,
        ModelPurpose $purpose,
        string $systemPrompt,
        array $userContent,
        int $estimatedInputBytes,
        array $options,
    ): PerceptionResult {
        if (! $this->featureGate->isMultimodalEnabledForCompany($principal->companyId)) {
            throw MultimodalException::featureDisabled();
        }

        $date = now('UTC')->toDateString();

        return $this->gateway->admitPerceptionDispatch(
            principal: $principal,
            date: $date,
            purpose: $purpose,
            estimatedInputBytes: $estimatedInputBytes,
            mediaId: $mediaId,
            callback: function (string $provider, string $model) use ($systemPrompt, $userContent, $options, $purpose): PerceptionResult {
                $apiKey = (string) config("ai.providers.{$provider}.key", '');
                $baseUrl = rtrim((string) config("ai.providers.{$provider}.url", 'https://integrate.api.nvidia.com/v1'), '/');
                if (trim($apiKey) === '') {
                    throw MultimodalException::providerUnavailable('NVIDIA perception credentials are not configured.');
                }

                $payload = $this->buildPayload($model, $systemPrompt, $userContent, $options);
                $endpoint = $baseUrl . '/chat/completions';
                $timeout = max(10, (int) config('ai_multimodal.timeout_seconds', 75));
                $maxRetries = max(0, (int) config('ai_multimodal.max_retry_attempts', 2));

                $start = microtime(true);
                $attempts = 0;
                $response = null;

                while (true) {
                    if ($attempts > 0) {
                        $this->gateway->enforceProviderRateLimit($provider);
                    }
                    $attempts++;

                    try {
                        $response = Http::withToken($apiKey)
                            ->connectTimeout(min(15, $timeout))
                            ->timeout($timeout)
                            ->withHeaders(['Expect' => ''])
                            ->withOptions([
                                'curl' => [
                                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                                ],
                            ])
                            ->acceptJson()
                            ->post($endpoint, $payload);

                        if ($response->successful()) {
                            break;
                        }

                        $status = $response->status();
                        if ($status === 429) {
                            $retryAfter = (int) ($response->header('Retry-After') ?: 30);
                            throw MultimodalException::rateLimited($retryAfter);
                        }

                        if (! in_array($status, [408, 502, 503, 504], true) || $attempts > $maxRetries) {
                            throw MultimodalException::providerUnavailable("Perception provider returned HTTP {$status}.");
                        }
                    } catch (ConnectionException $e) {
                        if ($attempts > $maxRetries) {
                            throw MultimodalException::providerUnavailable('Perception provider connection timed out.');
                        }
                    }
                }

                /** @var Response $response */
                $durationMs = (int) round((microtime(true) - $start) * 1000);
                $body = $response->json();
                if (! is_array($body)) {
                    throw MultimodalException::extractionFailed('INVALID_PERCEPTION_RESPONSE', 'Perception provider returned a non-JSON payload.');
                }

                $rawText = $this->extractMessageText($body['choices'][0]['message']['content'] ?? '');
                $parsed = $this->parseStrictJson($rawText);
                $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

                return new PerceptionResult(
                    rawText: $rawText,
                    parsedJson: $parsed,
                    provider: $provider,
                    model: $model,
                    purpose: $purpose,
                    inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
                    outputTokens: (int) ($usage['completion_tokens'] ?? 0),
                    durationMs: $durationMs,
                    finishReason: isset($body['choices'][0]['finish_reason']) ? (string) $body['choices'][0]['finish_reason'] : null,
                    warnings: [],
                    httpAttempts: $attempts,
                );
            },
        );
    }

    /**
     * @param mixed $content
     */
    private function extractMessageText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * Parse structured JSON cleanly, stripping reasoning <think> blocks and markdown code fences.
     *
     * @return array<string, mixed>
     */
    private function parseStrictJson(string $rawText): array
    {
        $cleaned = preg_replace('/<think>.*?<\/think>/is', '', $rawText) ?? $rawText;
        $cleaned = trim($cleaned);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $cleaned, $matches)) {
            $cleaned = trim($matches[1]);
        }

        $decoded = json_decode($cleaned, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $firstBrace = strpos($cleaned, '{');
        $lastBrace = strrpos($cleaned, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $slice = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
            $decodedSlice = json_decode($slice, true);
            if (is_array($decodedSlice)) {
                return $decodedSlice;
            }
        }

        throw MultimodalException::extractionFailed('INVALID_PERCEPTION_JSON', 'Perception model output did not contain valid JSON.');
    }
}

