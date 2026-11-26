<?php

declare(strict_types=1);

namespace App\Ai\Handlers;

use App\Ai\Contracts\ErpToolHandlerInterface;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\DTOs\ErpToolResult;
use App\Ai\Services\ReadCopilotHelp;

class CopilotHelpHandler implements ErpToolHandlerInterface
{
    public function __construct(
        protected ReadCopilotHelp $service
    ) {}

    public function key(): string
    {
        return 'copilot.help.v1';
    }

    public function description(): string
    {
        return 'List all available and authorized ERP tools, supported operational domains, and recommended question templates.';
    }

    public function risk(): string
    {
        return 'read_only';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function requiredPermissions(): array
    {
        return ['ai.use'];
    }

    public function authorize(AiPrincipal $principal, array $input): bool
    {
        return $principal->isActive;
    }

    public function execute(AiPrincipal $principal, array $validatedInput): ErpToolResult
    {
        return $this->service->execute($principal);
    }
}
