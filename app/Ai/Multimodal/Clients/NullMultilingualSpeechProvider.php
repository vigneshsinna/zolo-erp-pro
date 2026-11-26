<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Clients;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\Contracts\MultilingualSpeechProviderInterface;
use App\Ai\Multimodal\DTOs\PerceptionResult;
use App\Ai\Multimodal\Exceptions\MultimodalException;

/**
 * Default fail-closed multilingual speech provider when dedicated regional STT is disabled.
 */
final class NullMultilingualSpeechProvider implements MultilingualSpeechProviderInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function supportsLanguage(string $languageCode): bool
    {
        return false;
    }

    public function transcribe(
        AiPrincipal $principal,
        string $mediaId,
        string $wavBinary,
        string $languageCode,
        array $options = [],
    ): PerceptionResult {
        throw MultimodalException::dependencyUnavailable(
            'MULTILINGUAL_STT_UNAVAILABLE',
            "Dedicated multilingual speech transcription is not enabled for language [{$languageCode}]. Please record in English or type the request manually."
        );
    }
}

