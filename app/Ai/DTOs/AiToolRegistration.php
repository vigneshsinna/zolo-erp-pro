<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

use App\Ai\Contracts\ErpToolHandlerInterface;

final readonly class AiToolRegistration
{
    /** @param string[] $requiredAny An empty group marks an auxiliary tool that requires a domain grant. */
    public function __construct(
        public ErpToolHandlerInterface $handler,
        public string $providerName,
        public array $requiredAny,
        public ?string $operationsCapability = null,
        public bool $legacyReconciliation = false,
    ) {}
}
