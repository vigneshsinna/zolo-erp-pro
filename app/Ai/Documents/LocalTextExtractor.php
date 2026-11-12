<?php

declare(strict_types=1);

namespace App\Ai\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

class LocalTextExtractor
{
    /** @return array{method:string,pages:array<int,string>,needs_review:bool} */
    public function extract(string $path, string $mime): array
    {
        if ($mime === 'text/plain') {
            $text = file_get_contents($path);
            $method = 'text';
        } elseif ($mime === 'application/pdf') {
            $info = $this->run('pdfinfo_binary', [$path]);
            if (!preg_match('/^Pages:\s*(\d+)\s*$/m', $info, $pageCount)
                || (int) $pageCount[1] > (int) config('ai_documents.max_pages', 50)) {
                throw new RuntimeException('PDF exceeds the page limit or its page count cannot be verified.');
            }
            $text = $this->run('pdftotext_binary', ['-layout', '-f', '1', '-l', (string) config('ai_documents.max_pages', 50), $path, '-']);
            $method = 'pdf_text';
        } elseif (in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $text = $this->run('tesseract_binary', [$path, 'stdout']);
            $method = 'ocr';
        } else {
            throw new RuntimeException('This document requires the spreadsheet import workflow.');
        }
        if (!is_string($text) || strlen($text) > (int) config('ai_documents.max_text_bytes', 500000)
            || !mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('Document text is invalid or exceeds the extraction limit.');
        }
        $pages = array_values(array_filter(array_map('trim', explode("\f", $text)), static fn (string $page): bool => $page !== ''));
        if ($pages === []) {
            throw new RuntimeException('No readable text was extracted. Upload clearer text or an image for OCR.');
        }
        return ['method' => $method, 'pages' => $pages, 'needs_review' => $method === 'ocr'];
    }

    private function run(string $configKey, array $arguments): string
    {
        $binary = config('ai_documents.'.$configKey);
        if (!is_string($binary) || $binary === '' || !is_file($binary)) {
            throw new RuntimeException('Local document extractor is unavailable.');
        }
        $process = new Process([$binary, ...$arguments]);
        $process->setTimeout((int) config('ai_documents.process_timeout', 45));
        $output = '';
        $process->run(function (string $type, string $buffer) use (&$output, $process): void {
            if ($type === Process::OUT) {
                $output .= $buffer;
                if (strlen($output) > (int) config('ai_documents.max_text_bytes', 500000)) {
                    $process->stop(0);
                    throw new RuntimeException('Document text exceeds the extraction limit.');
                }
            }
        });
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Local document extraction failed.');
        }
        return $output;
    }
}
