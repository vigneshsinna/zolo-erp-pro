<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Enums;

/**
 * Purpose-scoped AI model routing classifications.
 * Perception models are strictly separated from reasoning/tool-orchestration models.
 */
enum ModelPurpose: string
{
    case Reasoning = 'reasoning';
    case PerceptionVision = 'perception_vision';
    case PerceptionAudio = 'perception_audio';
    case MultilingualSpeech = 'multilingual_speech';

    /**
     * Whether this purpose routes to an Omni perception client rather than a tool-calling agent.
     */
    public function isPerception(): bool
    {
        return match ($this) {
            self::PerceptionVision, self::PerceptionAudio, self::MultilingualSpeech => true,
            self::Reasoning => false,
        };
    }
}

