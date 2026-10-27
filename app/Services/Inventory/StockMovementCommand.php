<?php

namespace App\Services\Inventory;

use App\Services\Platform\CompanyContext;
use InvalidArgumentException;

/**
 * $warehouseId is the receiving/issuing/adjusting warehouse, or the transfer source.
 * $idempotencyKey must be namespaced by the caller, e.g. "sale:42".
 */
final readonly class StockMovementCommand
{
    /** @param list<StockLine> $lines */
    public function __construct(
        public string $date,
        public array $lines,
        public ?int $warehouseId = null,
        public ?int $toWarehouseId = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $sourceNo = null,
        public ?string $idempotencyKey = null,
        public ?string $reason = null,
        public ?int $userId = null,
        public ?CompanyContext $context = null,
        // Shadow: describe quantities a legacy writer already changed; projections are untouched and policy only warns.
        public bool $shadow = false,
        public string $purpose = 'ordinary',
    ) {
        if (!in_array($purpose, ['ordinary', 'customer_return', 'purchase_return', 'disposal', 'rework'], true)) {
            throw new InvalidArgumentException('Unsupported stock movement purpose.');
        }
        if ($lines === [] || array_filter($lines, fn ($line) => !$line instanceof StockLine) !== []) {
            throw new InvalidArgumentException('A stock movement needs at least one stock line.');
        }
        if (strtotime($date) === false) {
            throw new InvalidArgumentException('A stock movement needs a valid date.');
        }
    }
}
