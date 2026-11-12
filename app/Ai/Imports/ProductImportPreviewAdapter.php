<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\Contracts\ActionPreviewAdapterInterface;
use App\Ai\DTOs\ActionDefinition;
use App\Ai\DTOs\ActionPreviewResult;
use App\Ai\DTOs\AiPrincipal;
use App\Ai\Security\BootstrapSetupPrincipal;
use App\Services\Platform\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductImportPreviewAdapter implements ActionPreviewAdapterInterface
{
    public function preview(ActionDefinition $definition, array $slots, CompanyContext|BootstrapSetupPrincipal $context): ActionPreviewResult
    {
        if (!$context instanceof CompanyContext) {
            throw new \InvalidArgumentException('Product import requires company context.');
        }
        // Proposal preview adapters have no actor parameter. Ownership is resolved from the authenticated actor.
        $actor = auth()->id();
        if (!$actor) {
            throw new \Illuminate\Auth\Access\AuthorizationException('An authenticated import reviewer is required.');
        }
        $principal = new AiPrincipal((int) $actor, $context->companyId, $context->branchId, $context->financialYearId);
        $preview = app(ProductImportPlanner::class)->show($principal, $slots['batch_id']);
        $rows = array_values(array_unique(array_map('intval', $slots['accepted_rows'])));
        sort($rows);
        $valid = array_column(array_filter($preview['rows'], static fn (array $row): bool => $row['status'] === 'valid'), 'row');
        if (!hash_equals($preview['review_hash'], $slots['review_hash']) || array_diff($rows, $valid)) {
            throw ValidationException::withMessages(['batch_id' => 'Import review or selected rows changed. Rebuild the preview.']);
        }
        return new ActionPreviewResult(
            payload: ['batch_id' => $slots['batch_id'], 'accepted_rows' => $rows, 'review_hash' => $slots['review_hash']],
            preview: ['document_type' => 'Reviewed Product Import', 'batch_id' => $slots['batch_id'],
                'rows' => array_values(array_filter($preview['rows'], static fn (array $row): bool => in_array($row['row'], $rows, true))),
                'source_hash' => $preview['source_hash'], 'inventory_impact' => 'none', 'ledger_impact' => 'none'],
            sourceStateHash: hash('sha256', $preview['review_hash']),
            warnings: ['Only selected validated rows will be imported. A changed master requires a new review.'],
        );
    }
}
