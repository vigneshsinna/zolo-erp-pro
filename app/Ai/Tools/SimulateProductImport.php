<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\DTOs\AiPrincipal;
use App\Ai\Imports\ProductImportPlanner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * AI Tool providing safe, dry-run spreadsheet simulation for bulk product imports.
 * Scans every row against existing codes, categories, units, and barcode formats with zero database writes.
 */
final class SimulateProductImport implements Tool
{
    public function __construct(
        private readonly AiPrincipal $principal,
        private readonly ?ProductImportPlanner $planner = null,
    ) {
    }

    public function name(): string
    {
        return 'simulate_product_import';
    }

    public function description(): string
    {
        return 'Performs a dry-run validation simulation of a bulk product import spreadsheet (CSV/Excel). Scans every row against categories, units, and duplicate codes without creating any records in the database. Returns validation counts and an import batch ID for governed human review.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()
                ->description('UUID of the uploaded and virus-scanned product spreadsheet document')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $documentId = trim((string) ($args['document_id'] ?? ''));

        if (empty($documentId)) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'document_id',
                'message' => 'Please provide the document ID of the uploaded spreadsheet to simulate.',
            ], JSON_THROW_ON_ERROR);
        }

        try {
            $planner = $this->planner ?? app(ProductImportPlanner::class);
            $preview = $planner->preview($this->principal, $documentId);

            $counts = $preview['counts'] ?? [];
            $validCount = $counts['valid'] ?? 0;
            $duplicateCount = $counts['duplicate'] ?? 0;
            $existingCount = $counts['already-existing'] ?? 0;
            $rejectedCount = $counts['rejected'] ?? 0;

            return json_encode([
                'status' => 'ok',
                'tool' => 'simulate_product_import',
                'batch_id' => $preview['id'],
                'review_hash' => $preview['review_hash'],
                'counts' => [
                    'valid' => $validCount,
                    'duplicate' => $duplicateCount,
                    'already_existing' => $existingCount,
                    'rejected' => $rejectedCount,
                    'total' => count($preview['rows'] ?? []),
                ],
                'sample_valid_rows' => array_slice(array_filter($preview['rows'] ?? [], fn ($r) => ($r['status'] ?? '') === 'valid'), 0, 5),
                'rejected_rows' => array_slice(array_filter($preview['rows'] ?? [], fn ($r) => ($r['status'] ?? '') !== 'valid'), 0, 5),
                'instructions' => "Batch {$preview['id']} simulated successfully. {$validCount} rows are valid and ready to import. Use prepare_product_import with batch_id and review_hash to generate a proposal for approval.",
            ], JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            return json_encode([
                'status' => 'needs_input',
                'missing_field' => 'document_id',
                'errors' => $e->errors(),
                'message' => implode(' ', $e->validator->errors()->all()),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return json_encode([
                'status' => 'error',
                'error_code' => 'AI_IMPORT_SIMULATION_FAILED',
                'message' => 'Could not simulate product import: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

