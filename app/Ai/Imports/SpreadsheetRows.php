<?php

declare(strict_types=1);

namespace App\Ai\Imports;

use App\Services\ERP\ReviewedProductImport;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ZipArchive;

final class SpreadsheetRows
{
    public function read(string $bytes, string $extension): array
    {
        $rows = $extension === 'xlsx' ? $this->xlsx($bytes) : $this->csv($bytes);
        $header = array_shift($rows);
        if (!$header) {
            $this->fail('Spreadsheet is empty.');
        }
        $header = array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $header);
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
        if (count($header) !== count(array_unique($header)) || array_diff($header, ReviewedProductImport::COLUMNS)
            || array_diff(['name', 'code', 'type', 'category_id'], $header)) {
            $this->fail('Use supported product template columns, including name, code, type and category_id.');
        }
        $result = [];
        foreach ($rows as $index => $values) {
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }
            $errors = [];
            if (count($values) !== count($header)) {
                $errors['row'] = ['Column count does not match the header.'];
            }
            foreach ($values as $value) {
                $text = (string) $value;
                if (!mb_check_encoding($text, 'UTF-8')) {
                    $errors['row'] = ['Use UTF-8 text.'];
                }
                if (preg_match('/^[=+@]/u', ltrim($text)) || (str_starts_with(ltrim($text), '-') && !is_numeric(trim($text)))) {
                    $errors['row'] = ['Spreadsheet formulas and formula-like cells are not accepted.'];
                }
            }
            $result[] = ['row' => $index + 2, 'data' => count($values) === count($header) ? array_combine($header, $values) : [], 'errors' => $errors];
        }
        if ($result === []) {
            $this->fail('Spreadsheet has no product rows.');
        }
        return $result;
    }

    public function csvBytes(array $data): string
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fputcsv($stream, array_keys($data), escape: '');
            fputcsv($stream, array_values($data), escape: '');
            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function csv(string $bytes): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $bytes);
        rewind($stream);
        $rows = [];
        try {
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = $row;
                if (count($rows) > (int) config('ai_documents.max_import_rows', 1000) + 1) {
                    $this->fail('Spreadsheet exceeds the permitted row limit.');
                }
            }
        } finally {
            fclose($stream);
        }
        return $rows;
    }

    private function xlsx(string $bytes): array
    {
        $path = tempnam(storage_path('app'), 'ai-sheet-');
        if ($path === false) {
            $this->fail('Unable to open a private spreadsheet processing file.');
        }
        try {
            chmod($path, 0600);
            file_put_contents($path, $bytes);
            $archive = new ZipArchive;
            if ($archive->open($path) !== true || $archive->locateName('xl/workbook.xml') === false) {
                $this->fail('Upload a valid XLSX workbook.');
            }
            $expanded = 0;
            try {
                for ($i = 0; $i < $archive->numFiles; $i++) {
                    $entry = $archive->statIndex($i);
                    $expanded += $entry['size'];
                    if ($expanded > 20 * 1024 * 1024 || preg_match('/vbaProject|externalLinks/i', $entry['name'])) {
                        $this->fail('Workbook contains unsupported links, macros, or excessive expanded content.');
                    }
                }
            } finally {
                $archive->close();
            }
            $reader = new Xlsx;
            $limit = (int) config('ai_documents.max_import_rows', 1000);
            $info = $reader->listWorksheetInfo($path);
            if (count($info) !== 1 || $info[0]['totalRows'] > $limit + 1 || $info[0]['totalColumns'] > count(ReviewedProductImport::COLUMNS)) {
                $this->fail('Use one worksheet within the product row and column limits.');
            }
            $reader->setReadDataOnly(false);
            $reader->setReadFilter(new class($limit + 1) implements IReadFilter {
                public function __construct(private readonly int $limit) {}
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->limit && strlen($columnAddress) <= 1 && $columnAddress <= 'P';
                }
            });
            $workbook = $reader->load($path);
            try {
                $sheet = $workbook->getActiveSheet();
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    foreach ($row->getCellIterator('A', $info[0]['lastColumnLetter']) as $cell) {
                        $values[] = $cell->getDataType() === DataType::TYPE_FORMULA ? '=FORMULA_REJECTED' : $cell->getValue();
                    }
                    $rows[] = $values;
                }
                return $rows;
            } finally {
                $workbook->disconnectWorksheets();
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
