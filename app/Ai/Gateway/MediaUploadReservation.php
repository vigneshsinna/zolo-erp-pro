<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

use Closure;

/**
 * Atomic reservation handle for daily company media upload quotas.
 * Automatically releases the reservation slot if released or destroyed on failure.
 */
final class MediaUploadReservation
{
    private bool $settled = false;

    /**
     * @param Closure(): void $onRelease
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $date,
        private readonly Closure $onRelease,
    ) {}

    public function commit(): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        ($this->onRelease)();
    }

    public function release(): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        ($this->onRelease)();
    }

    public function __destruct()
    {
        $this->release();
    }
}

