<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Contracts;

/**
 * Audio normalization contract converting uploaded audio into 16kHz mono PCM WAV.
 */
interface AudioTranscoderInterface
{
    /**
     * @return array{wav_path: string, duration_seconds: float, sample_rate: int, channels: int, rms: float}
     */
    public function transcodeToWav16kMono(
        string $sourcePath,
        string $sourceMime,
        string $outputWavPath,
        int $maxDurationSeconds,
    ): array;
}

