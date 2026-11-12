<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Ai\Multimodal\Exceptions\MultimodalException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates company-scoped private media uploads while blocking remote URL SSRF vectors.
 */
class UploadAiMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['url', 'media_url', 'remote_url', 'file_url', 'source_url'] as $forbiddenKey) {
            if ($this->filled($forbiddenKey)) {
                throw MultimodalException::remoteUrlForbidden();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = max(
            (int) ceil(((int) config('ai_multimodal.max_pdf_bytes', 20_971_520)) / 1024),
            (int) ceil(((int) config('ai_multimodal.max_audio_bytes', 20_971_520)) / 1024),
        );

        return [
            'file' => ['required', 'file', "max:{$maxKb}"],
            'thread_id' => ['nullable', 'uuid'],
            'workflow_task_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'companion_text' => ['nullable', 'string', 'max:2000'],
            'extract_now' => ['nullable', 'boolean'],
            'async' => ['nullable', 'boolean'],
        ];
    }
}

