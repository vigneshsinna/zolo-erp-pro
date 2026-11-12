<?php

declare(strict_types=1);

namespace App\Ai\Documents;

use App\Ai\Services\AiPrincipalFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(
        public readonly string $documentId,
        public readonly int $actorId,
        public readonly int $companyId,
        public readonly int $branchId,
        public readonly int $financialYearId,
    ) {
        $this->onQueue('ai-documents');
    }

    public function handle(AiPrincipalFactory $factory, DocumentIntakeService $documents): void
    {
        $principal = $factory->createForUser($this->actorId, $this->companyId, $this->branchId, $this->financialYearId);
        $documents->process($principal, $this->documentId);
    }
}
