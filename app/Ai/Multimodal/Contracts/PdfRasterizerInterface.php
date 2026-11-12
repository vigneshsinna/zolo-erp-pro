<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Contracts;

/**
 * Bounded PDF-to-PNG/JPEG page rasterizer contract.
 */
interface PdfRasterizerInterface
{
    /**
     * @return array{page_count: int, page_paths: array<int, string>}
     */
    public function rasterize(string $pdfPath, string $outputDir, int $maxPages, int $dpi): array;
}

