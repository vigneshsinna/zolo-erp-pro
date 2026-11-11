<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * Reviewed, code-registered domain capability. Never constructed from LLM input.
 */
final readonly class ActionDefinition
{
    public function __construct(
        public string $key,
        public string $businessModule,
        public string $riskTier,
        public array $requiredPermissions,
        public string $inputSchemaClass,
        public string $previewAdapterClass,
        public string $commandAdapterClass,
        public bool $domainOwnsTransaction = true,
        public bool $requiresApproval = true,
        public bool $requiresFreshSnapshot = true,
        public bool $enabledByDefault = false,
        public string $mode = 'company',
    ) {}
}
