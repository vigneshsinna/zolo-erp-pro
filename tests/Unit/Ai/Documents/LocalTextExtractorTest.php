<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Documents;

use App\Ai\Documents\KnowledgeRetrievalService;
use App\Ai\Documents\LocalTextExtractor;
use RuntimeException;
use Tests\TestCase;

final class LocalTextExtractorTest extends TestCase
{
    public function test_pdf_blank_pages_preserve_original_citation_numbers_and_private_rasters_are_removed(): void
    {
        $extractor = $this->extractor("First physical page\f\fThird physical page\f", 3, ['']);
        $result = $extractor->extract('reviewed.pdf', 'application/pdf');
        $this->assertSame(['First physical page', '', 'Third physical page'], $result['pages']);
        $this->assertTrue($result['needs_review']);
        $chunks = app(KnowledgeRetrievalService::class)->chunk($result['pages']);
        $this->assertSame([1, 3], array_column($chunks, 'page'));
        $this->assertSame('2', $extractor->rasterArguments[0][1]);
        foreach ($extractor->rasterPaths as $path) $this->assertFileDoesNotExist($path);
    }

    public function test_scanned_pdf_uses_bounded_page_rasterization_then_local_ocr(): void
    {
        $extractor = $this->extractor("\f\f", 2, ['Scanned invoice header', 'Scanned invoice lines']);
        $result = $extractor->extract('scanned.pdf', 'application/pdf');
        $this->assertSame('pdf_text_with_ocr', $result['method']);
        $this->assertSame(['Scanned invoice header', 'Scanned invoice lines'], $result['pages']);
        $this->assertTrue($result['needs_review']);
        $this->assertCount(2, $extractor->rasterArguments);
        $this->assertContains('2400', $extractor->rasterArguments[0]);
        foreach ($extractor->rasterPaths as $path) $this->assertFileDoesNotExist($path);
    }

    public function test_pdf_page_limit_fails_before_parsing_or_rasterization(): void
    {
        $extractor = $this->extractor('', 51, []);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('page limit');
        try { $extractor->extract('too-many-pages.pdf', 'application/pdf'); }
        finally { $this->assertSame(['pdfinfo_binary'], $extractor->commands); }
    }

    public function test_oversized_image_dimensions_fail_before_the_ocr_process_starts(): void
    {
        $extractor = $this->extractor('', 0, []);
        $path = tempnam(storage_path('app'), 'ai-large-image-');
        file_put_contents($path, "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 200000, 200000, 8, 2, 0, 0, 0).pack('N', 0));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('OCR pixel limit');
            $extractor->extract($path, 'image/png');
        } finally {
            unlink($path);
            $this->assertSame([], $extractor->commands);
        }
    }

    private function extractor(string $text, int $pages, array $ocr): LocalTextExtractor
    {
        return new class($text, $pages, $ocr) extends LocalTextExtractor {
            public array $rasterPaths = [];
            public array $rasterArguments = [];
            public array $commands = [];
            public function __construct(private readonly string $text, private readonly int $pages, private array $ocr) {}
            protected function run(string $configKey, array $arguments): string
            {
                $this->commands[] = $configKey;
                if ($configKey === 'pdfinfo_binary') return 'Pages: '.$this->pages."\n";
                if ($configKey === 'pdftotext_binary') return $this->text;
                if ($configKey === 'pdftoppm_binary') {
                    $path = $arguments[array_key_last($arguments)].'.png';
                    $this->rasterPaths[] = $path;
                    $this->rasterArguments[] = $arguments;
                    file_put_contents($path, 'private raster fixture');
                    return '';
                }
                if ($configKey === 'tesseract_binary') return array_shift($this->ocr) ?? '';
                throw new RuntimeException('Unexpected extractor command.');
            }
        };
    }
}
