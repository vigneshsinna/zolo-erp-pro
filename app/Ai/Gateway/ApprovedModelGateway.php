<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Gatekeeper mediating model provider selection, token limits,
 * circuit breakers, and fail-safe error handling.
 */
class ApprovedModelGateway
{
    /**
     * @var array<string, array<string>>
     */
    protected array $allowedModels = [
        'openai' => ['gpt-4o-mini', 'gpt-4o'],
        'gemini' => ['gemini-1.5-flash', 'gemini-2.0-flash'],
        'anthropic' => ['claude-3-5-haiku-20241022', 'claude-3-5-sonnet-20241022'],
    ];

    public function __construct(
        protected AiFeatureGate $featureGate
    ) {}

    /**
     * Resolve the approved provider and model key for an execution context.
     *
     * @return array{provider: string, model: string}
     */
    public function resolveApprovedModel(AiPrincipal $principal, ?string $preferredProvider = null): array
    {
        if (!$this->featureGate->isEnabledForCompany($principal->companyId)) {
            throw new RuntimeException("AI is disabled for company ID [{$principal->companyId}].");
        }

        $provider = $preferredProvider ?? config('erp_ai.provider', 'openai');

        if (!array_key_exists($provider, $this->allowedModels)) {
            throw new InvalidArgumentException("Provider [{$provider}] is not in the approved AI gateway allowlist.");
        }

        $defaultModel = config('erp_ai.model', $this->allowedModels[$provider][0]);

        if (!in_array($defaultModel, $this->allowedModels[$provider], true)) {
            $defaultModel = $this->allowedModels[$provider][0];
        }

        return [
            'provider' => $provider,
            'model' => $defaultModel,
        ];
    }

    /**
     * Check if a specific provider and model pair is permitted.
     */
    public function isApproved(string $provider, string $model): bool
    {
        return isset($this->allowedModels[$provider]) && in_array($model, $this->allowedModels[$provider], true);
    }
}

