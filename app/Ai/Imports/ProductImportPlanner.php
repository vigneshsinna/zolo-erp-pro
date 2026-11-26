<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Ai\Documents\DocumentAccess;
use App\Ai\Documents\DocumentIntakeService;
use App\Ai\DTOs\AiPrincipal;
use App\Services\Commercial\CommercialPermission;
use App\Services\ERP\ReviewedProductImport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Row-level recovery delegates every product mutation to the established reviewed importer. */
final class ProductImportPlanner
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentIntakeService $documents,
        private readonly SpreadsheetRows $reader,
        private readonly ReviewedProductImport $imports,
    ) {}

    public function preview(AiPrincipal $principal, string $documentId): array
    {
        $context = $this->access->context($principal);
        $this->imports->authorize($context, $principal->actorId);
        $document = $this->access->find($principal, $documentId);
        if ($document->classification !== 'product_import') {
            throw ValidationException::withMessages(['document' => 'Select a scanned product-import spreadsheet.']);
        }
        $rows = $this->reader->read($this->documents->scannedBytes($principal, $documentId), $document->extension);
        $codes = [];
        foreach ($rows as &$row) {
            $row['import_id'] = null;
            $code = mb_strtolower(trim((string) ($row['data']['code'] ?? '')));
            if ($code !== '' && isset($codes[$code])) {
                $row['errors']['code'] = ['Duplicate code within this spreadsheet.'];
            }
            $codes[$code] = true;
            if ($row['errors'] === []) {
                $path = tempnam(storage_path('app'), 'ai-import-');
                if ($path === false) {
                    throw new \RuntimeException('Unable to open a private import processing file.');
                }
                chmod($path, 0600);
                file_put_contents($path, $this->reader->csvBytes($row['data']));
                $file = new UploadedFile($path, 'reviewed.csv', 'text/csv', null, true);
                try {
                    $preview = $this->imports->preview($file, $context, $principal->actorId);
                    $row['errors'] = $preview->rows[0]['errors'];
                    $row['import_id'] = $preview->id;
                } finally {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            $messages = implode(' ', array_merge(...array_values($row['errors'] ?: [[]])));
            $row['status'] = $row['errors'] === [] ? 'valid'
                : (str_contains($messages, 'already exists') ? 'already-existing'
                    : (str_contains($messages, 'Duplicate') ? 'duplicate' : 'rejected'));
        }
        unset($row);
        $id = (string) Str::uuid();
        DB::table('ai_import_batches')->insert([
            'id' => $id, 'company_id' => $principal->companyId, 'branch_id' => $principal->branchId,
            'financial_year_id' => $principal->financialYearId, 'actor_id' => $principal->actorId,
            'document_id' => $documentId, 'source_hash' => $document->checksum,
            'rows_encrypted' => Crypt::encryptString(json_encode($rows, JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->show($principal, $id);
    }

    public function show(AiPrincipal $principal, string $id): array
    {
        $batch = $this->find($principal, $id);
        $rows = $this->rows($batch);
        $receipts = json_decode($batch->receipts_json ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        return ['id' => $batch->id, 'status' => $batch->status, 'source_hash' => $batch->source_hash,
            'review_hash' => $this->reviewHash($batch, $rows), 'schema_version' => $batch->schema_version,
            'rows' => $rows, 'receipts' => $receipts,
            'counts' => array_count_values(array_column($rows, 'status')), 'expires_at' => $batch->expires_at];
    }

    public function commit(AiPrincipal $principal, string $id, array $acceptedRows, string $reviewHash): array
    {
        $context = $this->access->context($principal);
        $this->imports->authorize($context, $principal->actorId);
        app(CommercialPermission::class)->assert('ai.action.approve', $context, $principal->actorId);
        $acceptedRows = array_values(array_unique(array_map('intval', $acceptedRows)));
        sort($acceptedRows);
        if ($acceptedRows === []) {
            throw ValidationException::withMessages(['rows' => 'Select at least one valid row.']);
        }
        DB::transaction(function () use ($principal, $id, $acceptedRows, $reviewHash): void {
            $batch = $this->find($principal, $id, true);
            $rows = $this->rows($batch);
            if (!hash_equals($this->reviewHash($batch, $rows), $reviewHash)) {
                throw ValidationException::withMessages(['review_hash' => 'Import review changed. Reload the preview.']);
            }
            $valid = array_column(array_filter($rows, static fn (array $row): bool => $row['status'] === 'valid'), 'row');
            if (array_diff($acceptedRows, $valid)) {
                throw ValidationException::withMessages(['rows' => 'Only validated rows from this preview can be approved.']);
            }
            $approval = hash('sha256', $reviewHash.json_encode($acceptedRows, JSON_THROW_ON_ERROR));
            if ($batch->approval_hash && !hash_equals($batch->approval_hash, $approval)) {
                throw ValidationException::withMessages(['rows' => 'Approved row selection cannot change. Create a new preview.']);
            }
            $this->query($principal)->where('id', $id)->update(['approval_hash' => $approval,
                'approved_at' => $batch->approved_at ?: now(), 'status' => 'executing', 'updated_at' => now()]);
        });
        foreach ($acceptedRows as $rowNumber) {
            try {
                DB::transaction(function () use ($principal, $context, $id, $rowNumber): void {
                    $batch = $this->find($principal, $id, true);
                    $receipts = json_decode($batch->receipts_json ?: '{}', true, flags: JSON_THROW_ON_ERROR);
                    if (($receipts[$rowNumber]['status'] ?? '') === 'completed') {
                        return;
                    }
                    $row = collect($this->rows($batch))->firstWhere('row', $rowNumber);
                    $result = $this->imports->commit((int) $row['import_id'], $context, $principal->actorId);
                    $receipts[$rowNumber] = ['status' => 'completed', 'products' => $result];
                    $this->query($principal)->where('id', $id)->update([
                        'receipts_json' => json_encode($receipts, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                });
            } catch (ValidationException $error) {
                DB::transaction(function () use ($principal, $id, $rowNumber, $error): void {
                    $batch = $this->find($principal, $id, true);
                    $receipts = json_decode($batch->receipts_json ?: '{}', true, flags: JSON_THROW_ON_ERROR);
                    $receipts[$rowNumber] = ['status' => 'needs_review', 'errors' => $error->errors()];
                    $this->query($principal)->where('id', $id)->update([
                        'receipts_json' => json_encode($receipts, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                });
            }
        }
        $batch = $this->find($principal, $id);
        $receipts = json_decode($batch->receipts_json ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        $completed = count(array_filter($receipts, static fn (array $receipt): bool => $receipt['status'] === 'completed'));
        $this->query($principal)->where('id', $id)->update([
            'status' => $completed === count($acceptedRows) ? 'completed' : 'partial', 'updated_at' => now(),
        ]);
        return $this->show($principal, $id);
    }

    public function errorCsv(AiPrincipal $principal, string $id): string
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fputcsv($stream, ['row', 'status', 'errors'], escape: '');
            foreach ($this->show($principal, $id)['rows'] as $row) {
                if ($row['errors'] !== []) {
                    $message = implode(' ', array_merge(...array_values($row['errors'])));
                    fputcsv($stream, [$row['row'], $row['status'], "'".$message], escape: '');
                }
            }
            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function query(AiPrincipal $principal): \Illuminate\Database\Query\Builder
    {
        $context = $this->access->context($principal);
        $this->imports->authorize($context, $principal->actorId);
        return DB::table('ai_import_batches')->where('company_id', $principal->companyId)
            ->where('branch_id', $principal->branchId)->where('financial_year_id', $principal->financialYearId)
            ->where('actor_id', $principal->actorId)->where('expires_at', '>', now());
    }

    private function find(AiPrincipal $principal, string $id, bool $lock = false): object
    {
        $query = $this->query($principal)->where('id', $id);
        $batch = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$batch) {
            throw new AuthorizationException('Import preview is expired or unavailable.');
        }
        $document = $this->access->find($principal, $batch->document_id);
        if (!hash_equals($batch->source_hash, $document->checksum)) {
            throw ValidationException::withMessages(['document' => 'Import source checksum changed. Create a new preview.']);
        }
        return $batch;
    }

    private function rows(object $batch): array
    {
        return json_decode(Crypt::decryptString($batch->rows_encrypted), true, flags: JSON_THROW_ON_ERROR);
    }

    private function reviewHash(object $batch, array $rows): string
    {
        return hash('sha256', json_encode([$batch->id, $batch->company_id, $batch->branch_id,
            $batch->financial_year_id, $batch->actor_id, $batch->source_hash, $batch->schema_version, $rows], JSON_THROW_ON_ERROR));
    }
}
