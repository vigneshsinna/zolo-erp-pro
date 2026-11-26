<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Contracts;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Multimodal\DTOs\PerceptionResult;

/**
 * Extension seam for dedicated regional multilingual STT fallback (disabled until approved).
 */
interface MultilingualSpeechProviderInterface
{
    public function isAvailable(): bool;

    public function supportsLanguage(string $languageCode): bool;

    /**
     * @param array<string, mixed> $options
     */
    public function transcribe(
        AiPrincipal $principal,
        string $mediaId,
        string $wavBinary,
        string $languageCode,
        array $options = [],
    ): PerceptionResult;
}

