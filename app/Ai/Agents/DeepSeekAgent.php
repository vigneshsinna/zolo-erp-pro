<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Tools\CustomerOutstanding;
use Closure;
use InvalidArgumentException;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Stringable;

/**
 * DeepSeek AI Agent powered by deepseek-ai/deepseek-v4.1-flash via NVIDIA NIM.
 *
 * Supports multimodal vision prompts (base64 and remote images) and
 * authoritative ERP tool execution when an AiPrincipal is bound.
 */
#[Provider('nvidia')]
#[Model('deepseek-ai/deepseek-v4.1-flash')]
class DeepSeekAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    public const MODEL = 'deepseek-ai/deepseek-v4.1-flash';
    public const DEFAULT_BASE_URL = 'https://integrate.api.nvidia.com/v1';

    public function __construct(
        protected readonly ?AiPrincipal $principal = null,
        protected readonly array $history = [],
        protected readonly ?Closure $budgetStep = null,
        protected readonly ?string $customInstructions = null,
    ) {}

    /**
     * Get client configuration compatible with OpenAI Python SDK specification.
     *
     * @return array{base_url: string, api_key: ?string, model: string}
     */
    public static function makeClientConfig(): array
    {
        return [
            'base_url' => (string) config('ai.providers.nvidia.url', env('NVIDIA_BASE_URL', self::DEFAULT_BASE_URL)),
            'api_key' => config('ai.providers.nvidia.key', env('NVIDIA_API_KEY')),
            'model' => self::MODEL,
        ];
    }

    /**
     * Get instructions for DeepSeek reasoning and vision.
     */
    public function instructions(): Stringable|string
    {
        if ($this->customInstructions !== null && trim($this->customInstructions) !== '') {
            return $this->customInstructions;
        }

        return 'You are the DeepSeek AI Assistant for zoloERP Pro, powered by deepseek-ai/deepseek-v4.1-flash via NVIDIA NIM. '
            . 'You provide concise, precise multimodal visual analysis and business reasoning. '
            . 'When inspecting images of documents, paths, landscapes, or physical inventory, provide accurate, factual descriptions. '
            . 'When answering enterprise ERP questions, rely exclusively on authorised ERP tool outputs without inventing balances.';
    }

    /**
     * Get conversation message history.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    public function middleware(): array
    {
        return $this->budgetStep === null ? [] : [$this->budgetStep];
    }

    public function maxSteps(): int
    {
        return min(5, max(2, (int) config('erp_ai.max_steps', 5)));
    }

    /**
     * Get tools available to the agent if an authenticated principal is provided.
     */
    public function tools(): iterable
    {
        if ($this->principal === null) {
            return [];
        }

        $tools = [];
        if ($this->principal->hasTool('receivables.outstanding.v1')) {
            $tools[] = new CustomerOutstanding($this->principal);
        }

        return $tools;
    }

    /**
     * Ask a text prompt with optional attachments.
     *
     * @param array<int, \Laravel\Ai\Files\File> $attachments
     */
    public function ask(string $prompt, array $attachments = []): AgentResponse
    {
        return $this->prompt($prompt, attachments: $attachments, provider: 'nvidia', model: self::MODEL);
    }

    /**
     * Ask a multimodal question with an image (data URI, base64 string, URL, or local path).
     */
    public function askWithImage(
        string $prompt,
        string $imageDataUriOrPath,
        ?string $mimeType = 'image/jpeg',
    ): AgentResponse {
        $attachment = self::createImageAttachment($imageDataUriOrPath, $mimeType);

        return $this->prompt($prompt, attachments: [$attachment], provider: 'nvidia', model: self::MODEL);
    }

    /**
     * Resolve any image representation into a typed Laravel AI File attachment.
     */
    public static function createImageAttachment(string $imageInput, ?string $defaultMime = 'image/jpeg'): Base64Image|LocalImage|RemoteImage
    {
        $input = trim($imageInput);

        if (str_starts_with($input, 'data:image/')) {
            $parts = explode(',', $input, 2);
            if (count($parts) === 2) {
                preg_match('/^data:(image\/[a-zA-Z0-9\+\-\.]+);base64$/', $parts[0], $matches);
                $mime = $matches[1] ?? ($defaultMime ?? 'image/jpeg');

                return new Base64Image($parts[1], $mime);
            }
        }

        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            return new RemoteImage($input);
        }

        if (file_exists($input) && is_file($input)) {
            return new LocalImage($input);
        }

        // Raw base64 string
        if (preg_match('/^[a-zA-Z0-9\/\r\n+]+=*$/', $input)) {
            return new Base64Image($input, $defaultMime ?? 'image/jpeg');
        }

        throw new InvalidArgumentException('Unable to resolve image input into a valid image attachment.');
    }
}

