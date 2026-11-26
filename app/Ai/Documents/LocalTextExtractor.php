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
            $pages = explode("\f", $text);
            // pdftotext emits a trailing form feed. Keep blank physical pages so citations retain their original page number.
            if (count($pages) > (int) $pageCount[1] && trim(end($pages)) === '') array_pop($pages);
            $pages = array_pad(array_slice($pages, 0, (int) $pageCount[1]), (int) $pageCount[1], '');
            $extractedBytes = strlen($text);
            foreach ($pages as $index => $page) {
                if (trim($page) !== '') continue;
                $pages[$index] = $this->ocrPdfPage($path, $index + 1);
                $extractedBytes += strlen($pages[$index]);
                if ($extractedBytes > (int) config('ai_documents.max_text_bytes', 500000)) {
                    throw new RuntimeException('Document text exceeds the extraction limit.');
                }
                $method = 'pdf_text_with_ocr';
            }
            $text = implode("\f", $pages);
        } elseif (in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $dimensions = @getimagesize($path);
            if (!$dimensions || $dimensions[0] * $dimensions[1] > (int) config('ai_documents.max_image_pixels', 20000000)) {
                throw new RuntimeException('Image dimensions are invalid or exceed the OCR pixel limit.');
            }
            $text = $this->run('tesseract_binary', [$path, 'stdout']);
            $method = 'ocr';
        } else {
            throw new RuntimeException('This document requires the spreadsheet import workflow.');
        }
        if (!is_string($text) || strlen($text) > (int) config('ai_documents.max_text_bytes', 500000)
            || !mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('Document text is invalid or exceeds the extraction limit.');
        }
        $pages = array_map('trim', explode("\f", $text));
        if (!array_any($pages, static fn (string $page): bool => $page !== '')) {
            throw new RuntimeException('No readable text was extracted. Upload clearer text or an image for OCR.');
        }
        return ['method' => $method, 'pages' => $pages, 'needs_review' => str_contains($method, 'ocr')];
    }

    private function ocrPdfPage(string $path, int $page): string
    {
        $prefix = tempnam(storage_path('app'), 'ai-pdf-ocr-');
        if ($prefix === false) throw new RuntimeException('Unable to open a private PDF processing file.');
        $image = $prefix.'.png';
        try {
            chmod($prefix, 0600);
            file_put_contents($image, '');
            chmod($image, 0600);
            $this->run('pdftoppm_binary', ['-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '2400', '-png', $path, $prefix]);
            clearstatcache(true, $image);
            if (!is_file($image) || filesize($image) === 0 || filesize($image) > 32 * 1024 * 1024) {
                throw new RuntimeException('PDF rasterization failed or exceeded the OCR image limit.');
            }
            chmod($image, 0600);
            return $this->run('tesseract_binary', [$image, 'stdout']);
        } finally {
            foreach ([$image, $prefix] as $temporary) {
                if (is_file($temporary)) unlink($temporary);
            }
        }
    }

    protected function run(string $configKey, array $arguments): string
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
