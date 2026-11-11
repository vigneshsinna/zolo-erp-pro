<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/** Maps the actual receipt returned by an authoritative ERP domain command. */
final readonly class ActionExecutionReceipt
{
    public function __construct(
        public string $resourceType,
        public int $resourceId,
        public string $status,
        public array $details = [],
    ) {}
}
