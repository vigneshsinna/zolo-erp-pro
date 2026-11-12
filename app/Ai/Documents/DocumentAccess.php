<?php

declare(strict_types=1);

namespace App\Ai\Documents;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Services\AiFeatureGate;
use App\Services\Commercial\CommercialPermission;
use App\Services\Platform\CompanyContext;
use App\Services\Platform\CompanyContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DocumentAccess
{
    public function context(AiPrincipal $principal): CompanyContext
    {
        if (!config('ai_documents.enabled') || !$principal->isActive
            || !app(AiFeatureGate::class)->isEnabledForCompany($principal->companyId)) {
            throw new AuthorizationException('AI document processing is disabled.');
        }
        $context = app(CompanyContextResolver::class)->forActor(
            new CompanyContext($principal->companyId, $principal->branchId, $principal->financialYearId),
            $principal->actorId,
        );
        app(CommercialPermission::class)->assert('ai.use', $context, $principal->actorId);
        return $context;
    }

    public function documents(AiPrincipal $principal): Builder
    {
        $this->context($principal);
        // Uploads remain private to the uploader. Vector payloads never grant access.
        return DB::table('ai_documents')->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)->where('financial_year_id', $principal->financialYearId)
            ->where('actor_id', $principal->actorId)->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function find(AiPrincipal $principal, string $id, bool $lock = false): object
    {
        $query = $this->documents($principal)->where('id', $id);
        $document = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$document) {
            throw new AuthorizationException('Document is unavailable with your current ERP access.');
        }
        return $document;
    }
}
