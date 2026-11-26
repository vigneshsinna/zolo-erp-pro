<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Documents\DocumentAccess;
use App\Ai\Documents\DocumentIntakeService;
use App\Ai\Services\ActionPreparationService;
use App\Ai\Services\ActionSecurity;
use App\Services\Deployment\OpeningImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

/** Opening sources remain drafts until the normal proposal approval and execution gates. */
final class OpeningImportPlanner
{
    public function prepare(AiPrincipal $principal, string $actionKey, array $source): array
    {
        return $this->prepareSource($principal, $actionKey, $source);
    }

    public function prepareDocument(AiPrincipal $principal, string $actionKey, string $documentId, array $source): array
    {
        $document = app(DocumentAccess::class)->find($principal, $documentId);
        if ($document->classification !== 'opening_import' || isset($source['rows'])) {
            throw ValidationException::withMessages(['document_id' => 'Select a scanned opening-import spreadsheet and provide mappings and expected totals without replacement rows.']);
        }
        $source['rows'] = app(SpreadsheetRows::class)->readOpening(
            app(DocumentIntakeService::class)->scannedBytes($principal, $documentId), $document->extension,
        );
        $source['batch_key'] = 'ai-document:'.hash('sha256', $documentId.':'.$document->checksum.':'.($source['batch_key'] ?? ''));
        return DB::transaction(fn (): array => $this->prepareSource($principal, $actionKey, $source, $document));
    }

    private function prepareSource(AiPrincipal $principal, string $actionKey, array $source, ?object $document = null): array
    {
        if (!in_array($actionKey, ['setup.opening_stock.create.v1', 'setup.opening_balance.create.v1'], true)
            || !$principal->hasTool($actionKey)) {
            throw new AuthorizationException('Opening setup is unavailable with your current ERP access.');
        }
        $security = app(ActionSecurity::class);
        $security->definition($actionKey, $principal);
        $context = $security->context($principal);
        // The authoritative importer validates company-owned mappings and performs its exact posting dry-run in a rollback.
        $imports = app(OpeningImportService::class);
        $batch = $imports->stage($source, $context, $principal->actorId);
        if ($document !== null) {
            app(DocumentAccess::class)->find($principal, $document->id, true);
            DB::table('ai_opening_import_sources')->updateOrInsert(['company_id' => $principal->companyId, 'batch_id' => $batch->id], [
                'branch_id' => $principal->branchId, 'financial_year_id' => $principal->financialYearId,
                'actor_id' => $principal->actorId, 'document_id' => $document->id, 'checksum' => $document->checksum,
                'source_version' => $document->source_version, 'acl_version' => $document->acl_version,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($batch->status === 'committed') {
            throw ValidationException::withMessages(['batch_key' => 'This source was already committed. Review its existing opening receipt.']);
        }
        $batch = $imports->validate($batch->id, $context, $principal->actorId);
        if ($batch->status !== 'validated') {
            return ['status' => 'needs_input', 'batch_id' => (int) $batch->id, 'action_key' => $actionKey,
                'source_hash' => $batch->source_hash, 'errors' => $batch->validation_errors,
                'message' => 'Opening dry-run failed. Correct the source mappings, row data or expected totals and use a new batch key.'];
        }
        return app(ActionPreparationService::class)->prepare($principal, $actionKey, ['batch_id' => (int) $batch->id])
            + ['batch_id' => (int) $batch->id, 'source_hash' => $batch->source_hash];
    }
}
