<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Multimodal\Services\MediaExtractionService;
use App\Ai\Services\AiFeatureGate;
use App\Ai\Services\AiPrincipalFactory;
use App\Models\AiMediaAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued extraction job with company-scoped revalidation before calling Omni.
 * Safely aborts if the asset was purged/expired or multimodal was switched off after queuing.
 */
final class ExtractMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $companyId,
        public readonly int $actorId,
        public readonly int $branchId,
        public readonly int $financialYearId,
        public readonly string $mediaId,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $companionText = null,
    ) {}

    public function handle(
        AiFeatureGate $featureGate,
        AiPrincipalFactory $principalFactory,
        MediaExtractionService $extractor,
    ): void {
        if (! $featureGate->isMultimodalEnabledForCompany($this->companyId)) {
            return;
        }

        $asset = AiMediaAsset::query()
            ->where('company_id', $this->companyId)
            ->where('actor_id', $this->actorId)
            ->find($this->mediaId);

        if ($asset === null || $asset->isPurged() || $asset->isExpired()) {
            return;
        }

        $principal = $principalFactory->createForUser(
            userId: $this->actorId,
            companyId: $this->companyId,
            branchId: $this->branchId,
            financialYearId: $this->financialYearId,
        );

        $extractor->extract(
            principal: $principal,
            asset: $asset,
            idempotencyKey: $this->idempotencyKey,
            companionText: $this->companionText,
        );
    }
}

