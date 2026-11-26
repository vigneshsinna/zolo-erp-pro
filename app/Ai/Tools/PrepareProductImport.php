<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Imports\ProductImportPlanner;
use App\Ai\Services\ActionPreparationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool bridging reviewed bulk product import batches to the Action Proposal pipeline.
 * Creates an Action Proposal for human approval before committing any rows to product masters.
 */
final class PrepareProductImport implements Tool
{
    public function __construct(
        private readonly AiPrincipal $principal,
        private readonly ?ProductImportPlanner $planner = null,
        private readonly ?ActionPreparationService $preparationService = null,
    ) {
    }

    public function name(): string
    {
        return 'prepare_product_import';
    }

    public function description(): string
    {
        return 'Prepares a draft Action Proposal to import validated rows from a reviewed bulk product import batch. Requires explicit human review and approval before creating product master records.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'batch_id' => $schema->string()
                ->description('UUID of the reviewed import batch returned by simulate_product_import')
                ->required(),
            'accepted_rows' => $schema->array()
                ->items($schema->integer())
                ->description('Optional explicit list of 1-based row numbers to import. If omitted, all valid rows from the batch are imported.'),
            'review_hash' => $schema->string()
                ->description('Review hash checksum returned by simulate_product_import. If omitted, retrieved from the batch.'),
            'task_id' => $schema->string()
                ->description('Optional workflow task ID when resuming an existing clarification session'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $batchId = trim((string) ($args['batch_id'] ?? ''));

        if (empty($batchId)) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'batch_id',
                'message' => 'Please provide the batch ID from simulate_product_import.',
            ], JSON_THROW_ON_ERROR);
        }

        try {
            $planner = $this->planner ?? app(ProductImportPlanner::class);
            $batch = $planner->show($this->principal, $batchId);

            $reviewHash = !empty($args['review_hash']) ? (string) $args['review_hash'] : $batch['review_hash'];

            // If accepted_rows was not provided, default to all valid rows from the batch
            $acceptedRows = $args['accepted_rows'] ?? null;
            if (!is_array($acceptedRows) || empty($acceptedRows)) {
                $acceptedRows = [];
                foreach ($batch['rows'] as $row) {
                    if (($row['status'] ?? '') === 'valid') {
                        $acceptedRows[] = (int) $row['row'];
                    }
                }
            }

            if (empty($acceptedRows)) {
                return json_encode([
                    'status' => 'needs_input',
                    'missing_field' => 'accepted_rows',
                    'message' => 'No valid rows found in this batch to import.',
                ], JSON_THROW_ON_ERROR);
            }

            $inputs = [
                'batch_id' => $batchId,
                'accepted_rows' => array_values(array_unique(array_map('intval', $acceptedRows))),
                'review_hash' => $reviewHash,
            ];

            $service = $this->preparationService ?? app(ActionPreparationService::class);
            $result = $service->prepare(
                $this->principal,
                'setup.products.import.v1',
                $inputs,
                !empty($args['task_id']) ? (string) $args['task_id'] : null
            );

            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'validation',
                'errors' => $e->errors(),
                'message' => implode(' ', $e->validator->errors()->all()),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_PRODUCT_IMPORT_PREPARATION_FAILED',
                'message' => 'Could not generate product import proposal: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

