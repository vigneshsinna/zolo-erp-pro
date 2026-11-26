<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Contracts;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\DTOs\PerceptionResult;

/**
 * Dedicated perception model contract for vision extraction and audio STT.
 * Must NEVER accept ERP write tools or issue tool_calls.
 */
interface PerceptionClientInterface
{
    /**
     * @param array<int, array{mime: string, base64: string, page?: int}> $images
     * @param array<string, mixed> $options
     */
    public function extractStructuredFromImages(
        AiPrincipal $principal,
        string $mediaId,
        array $images,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
    ): PerceptionResult;

    /**
     * @param array<string, mixed> $options
     */
    public function transcribeWavAudio(
        AiPrincipal $principal,
        string $mediaId,
        string $wavBinary,
        string $systemPrompt,
        string $userPrompt,
        array $options = [],
    ): PerceptionResult;
}

