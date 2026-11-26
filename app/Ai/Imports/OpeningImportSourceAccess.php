<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\Documents\DocumentAccess;
use App\Ai\DTOs\AiPrincipal;
use App\Services\Platform\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class OpeningImportSourceAccess
{
    public function assertCurrent(int $batchId, CompanyContext $context, int $actorId, bool $lock = false): ?object
    {
        $source = DB::table('ai_opening_import_sources')->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)->where('financial_year_id', $context->financialYearId)->where('batch_id', $batchId)->first();
        if (!$source) return null;
        if ((int) $source->actor_id !== $actorId) throw new AuthorizationException('Opening source belongs to another document reviewer.');
        $principal = new AiPrincipal($actorId, $context->companyId, $context->branchId, $context->financialYearId);
        $document = app(DocumentAccess::class)->find($principal, $source->document_id, $lock);
        if ($document->classification !== 'opening_import' || $document->status !== 'scanned' || !$document->scanned_at
            || !hash_equals($source->checksum, $document->checksum)
            || (int) $source->source_version !== (int) $document->source_version
            || (int) $source->acl_version !== (int) $document->acl_version) {
            throw new AuthorizationException('Opening document changed or is no longer approved for parsing. Prepare a new review.');
        }
        return $source;
    }
}
