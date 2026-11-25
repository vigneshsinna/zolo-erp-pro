<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure;

use App\Ai\DTOs\AiPrincipal;

/**
 * Bound external MCP client authorization grant resolved server-side from
 * Sanctum PersonalAccessToken abilities and ai_mcp_client_bindings.
 */
final readonly class McpClientGrant
{
    /**
     * @param list<string> $allowedOperations
     * @param list<string> $allowedReadTools
     * @param list<string> $allowedPrepareActions
     * @param list<string> $allowedStatusTools
     */
    public function __construct(
        public string $bindingId,
        public string $clientIdentifier,
        public int $tokenId,
        public AiPrincipal $principal,
        public array $allowedOperations,
        public array $allowedReadTools,
        public array $allowedPrepareActions,
        public array $allowedStatusTools,
    ) {}

    public function allowsOperation(string $operation): bool
    {
        return in_array($operation, $this->allowedOperations, true);
    }

    public function allowsCapability(string $capabilityKey, string $operation): bool
    {
        if (!$this->allowsOperation($operation)) {
            return false;
        }

        return match ($operation) {
            'read' => in_array($capabilityKey, $this->allowedReadTools, true),
            'prepare' => in_array($capabilityKey, $this->allowedPrepareActions, true),
            'status' => in_array($capabilityKey, $this->allowedStatusTools, true),
            default => false,
        };
    }
}
