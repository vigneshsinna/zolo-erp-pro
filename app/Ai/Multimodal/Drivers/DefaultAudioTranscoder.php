<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Drivers;

use App\Ai\Multimodal\Contracts\AudioTranscoderInterface;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Audio normalization driver producing canonical 16kHz mono PCM WAV.
 * Handles WAV natively in pure PHP (zero external binary needed) and delegates
 * compressed WebM/OGG/MP3/FLAC formats cleanly to ffmpeg when present.
 */
final class DefaultAudioTranscoder implements AudioTranscoderInterface
{
    public function transcodeToWav16kMono(
        string $sourcePath,
        string $sourceMime,
        string $outputWavPath,
        int $maxDurationSeconds,
    ): array {
        if (! is_file($sourcePath)) {
            throw MultimodalException::corruptedMedia('Uploaded audio staging file was not found.');
        }

        $dir = dirname($outputWavPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw MultimodalException::dependencyUnavailable('AUDIO_STAGING_UNAVAILABLE', 'Unable to allocate staging directory for audio normalization.');
        }

        $isWav = in_array(strtolower($sourceMime), ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true);
        if ($isWav) {
            return $this->normalizeWavInPhp($sourcePath, $outputWavPath, $maxDurationSeconds);
        }

        return $this->transcodeViaFfmpeg($sourcePath, $outputWavPath, $maxDurationSeconds);
    }

    /**
     * @return array{wav_path: string, duration_seconds: float, sample_rate: int, channels: int, rms: float}
     */
    private function normalizeWavInPhp(string $sourcePath, string $outputWavPath, int $maxDurationSeconds): array
    {
        $bytes = file_get_contents($sourcePath);
        if (! is_string($bytes) || strlen($bytes) < 44) {
            throw MultimodalException::corruptedMedia('WAV header is incomplete.');
        }

        if (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            throw MultimodalException::invalidMagicBytes('Audio file is not a valid RIFF WAVE stream.');
        }

        $offset = 12;
        $totalLen = strlen($bytes);
        $fmt = null;
        $dataRaw = null;

        while ($offset + 8 <= $totalLen) {
            $chunkId = substr($bytes, $offset, 4);
            $chunkSize = unpack('Vlen', substr($bytes, $offset + 4, 4))['len'] ?? 0;
            $chunkStart = $offset + 8;
            $chunkEnd = min($totalLen, $chunkStart + $chunkSize);

            if ($chunkId === 'fmt ' && $chunkSize >= 16) {
                $fmt = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($bytes, $chunkStart, 16));
            } elseif ($chunkId === 'data') {
                $dataRaw = substr($bytes, $chunkStart, $chunkEnd - $chunkStart);
                break;
            }

            $offset = $chunkStart + $chunkSize + ($chunkSize % 2);
        }

        if (! is_array($fmt) || ! is_string($dataRaw) || $dataRaw === '') {
            throw MultimodalException::silentAudio();
        }

        $audioFormat = (int) $fmt['audioFormat'];
        $channels = max(1, (int) $fmt['channels']);
        $sampleRate = max(1, (int) $fmt['sampleRate']);
        $bitsPerSample = (int) $fmt['bitsPerSample'];

        if ($audioFormat !== 1 || ! in_array($bitsPerSample, [8, 16], true)) {
            throw MultimodalException::unsupportedMime("audio/wav (format={$audioFormat}, bits={$bitsPerSample})");
        }

        $bytesPerSample = intdiv($bitsPerSample, 8);
        $frameSize = $channels * $bytesPerSample;
        if ($frameSize <= 0) {
            throw MultimodalException::corruptedMedia('Invalid WAV channel frame layout.');
        }

        $totalFrames = intdiv(strlen($dataRaw), $frameSize);
        if ($totalFrames <= 0) {
            throw MultimodalException::silentAudio();
        }

        $duration = $totalFrames / $sampleRate;
        if ($duration > $maxDurationSeconds) {
            throw MultimodalException::audioTooLong($duration, $maxDurationSeconds);
        }

        // Convert frames cleanly to mono normalized float samples in [-1.0, 1.0].
        $monoSamples = [];
        $sumSq = 0.0;
        for ($f = 0; $f < $totalFrames; $f++) {
            $acc = 0.0;
            for ($c = 0; $c < $channels; $c++) {
                $pos = ($f * $frameSize) + ($c * $bytesPerSample);
                if ($bitsPerSample === 16) {
                    $val = unpack('sval', substr($dataRaw, $pos, 2))['val'] ?? 0;
                    $acc += $val / 32768.0;
                } else {
                    $u = ord($dataRaw[$pos]);
                    $acc += ($u - 128) / 128.0;
                }
            }
            $sample = $acc / $channels;
            $monoSamples[] = $sample;
            $sumSq += $sample * $sample;
        }

        $rms = sqrt($sumSq / $totalFrames);
        if ($rms < 0.002) {
            throw MultimodalException::silentAudio();
        }

        $targetRate = (int) config('ai_multimodal.audio_transcoder.sample_rate', 16000);
        if ($sampleRate !== $targetRate) {
            $resampled = $this->resampleLinear($monoSamples, $sampleRate, $targetRate);
        } else {
            $resampled = $monoSamples;
        }

        $pcmOut = '';
        foreach ($resampled as $sample) {
            $clamped = max(-1.0, min(1.0, $sample));
            $int16 = (int) round($clamped * 32767);
            $pcmOut .= pack('s', $int16);
        }

        $wavHeader = $this->buildPcm16MonoWavHeader(strlen($pcmOut), $targetRate);
        if (file_put_contents($outputWavPath, $wavHeader . $pcmOut) === false) {
            throw MultimodalException::dependencyUnavailable('AUDIO_STAGING_UNAVAILABLE', 'Failed writing canonical WAV output.');
        }

        return [
            'wav_path' => $outputWavPath,
            'duration_seconds' => round($duration, 2),
            'sample_rate' => $targetRate,
            'channels' => 1,
            'rms' => round($rms, 6),
        ];
    }

    /**
     * @return array{wav_path: string, duration_seconds: float, sample_rate: int, channels: int, rms: float}
     */
    private function transcodeViaFfmpeg(string $sourcePath, string $outputWavPath, int $maxDurationSeconds): array
    {
        $ffmpeg = (string) config('ai_multimodal.audio_transcoder.ffmpeg_bin', 'ffmpeg');
        $probe = new Process([$ffmpeg, '-version']);
        $probe->setTimeout(5);
        try {
            $probe->run();
            if (! $probe->isSuccessful()) {
                throw MultimodalException::dependencyUnavailable(
                    'AUDIO_TRANSCODER_UNAVAILABLE',
                    'Audio transcoder (ffmpeg) is unavailable on this server. Upload PCM WAV audio instead.'
                );
            }
        } catch (MultimodalException $e) {
            throw $e;
        } catch (Throwable) {
            throw MultimodalException::dependencyUnavailable(
                'AUDIO_TRANSCODER_UNAVAILABLE',
                'Audio transcoder (ffmpeg) is unavailable on this server. Upload PCM WAV audio instead.'
            );
        }

        $timeout = (int) config('ai_multimodal.audio_transcoder.timeout_seconds', 25);
        $targetRate = (int) config('ai_multimodal.audio_transcoder.sample_rate', 16000);
        $process = new Process([
            $ffmpeg,
            '-y',
            '-i',
            $sourcePath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            (string) $targetRate,
            '-sample_fmt',
            's16',
            '-t',
            (string) ($maxDurationSeconds + 2),
            '-f',
            'wav',
            $outputWavPath,
        ]);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputWavPath)) {
            throw MultimodalException::corruptedMedia('Uploaded audio stream could not be decoded or transcoded.');
        }

        return $this->normalizeWavInPhp($outputWavPath, $outputWavPath, $maxDurationSeconds);
    }

    /**
     * @param array<int, float> $samples
     * @return array<int, float>
     */
    private function resampleLinear(array $samples, int $fromRate, int $toRate): array
    {
        $count = count($samples);
        if ($count <= 1 || $fromRate <= 0 || $toRate <= 0) {
            return $samples;
        }

        $outCount = max(1, (int) round($count * ($toRate / $fromRate)));
        $ratio = ($count - 1) / max(1, $outCount - 1);
        $result = [];

        for ($i = 0; $i < $outCount; $i++) {
            $srcPos = $i * $ratio;
            $idx0 = (int) floor($srcPos);
            $idx1 = min($count - 1, $idx0 + 1);
            $frac = $srcPos - $idx0;
            $result[] = ($samples[$idx0] * (1.0 - $frac)) + ($samples[$idx1] * $frac);
        }

        return $result;
    }

    private function buildPcm16MonoWavHeader(int $dataSize, int $sampleRate): string
    {
        $byteRate = $sampleRate * 2;
        $riffSize = 36 + $dataSize;

        return 'RIFF'
            . pack('V', $riffSize)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)       // Subchunk1Size (PCM)
            . pack('v', 1)        // AudioFormat (PCM = 1)
            . pack('v', 1)        // NumChannels (Mono)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', 2)        // BlockAlign
            . pack('v', 16)       // BitsPerSample
            . 'data'
            . pack('V', $dataSize);
    }
}

