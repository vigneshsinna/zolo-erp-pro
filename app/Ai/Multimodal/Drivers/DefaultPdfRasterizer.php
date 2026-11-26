<?php

declare(strict_types=1);

namespace App\Ai\Multimodal\Drivers;

use App\Ai\Multimodal\Contracts\PdfRasterizerInterface;
use App\Ai\Multimodal\Exceptions\MultimodalException;
use Imagick;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Bounded PDF rasterizer supporting pdftoppm or Imagick without shell string interpolation.
 */
final class DefaultPdfRasterizer implements PdfRasterizerInterface
{
    public function rasterize(string $pdfPath, string $outputDir, int $maxPages, int $dpi): array
    {
        if (! is_file($pdfPath)) {
            throw MultimodalException::corruptedMedia('PDF staging file was not found.');
        }

        $rawSlice = @file_get_contents($pdfPath, false, null, 0, min(2097152, (int) filesize($pdfPath)));
        if (is_string($rawSlice) && preg_match('/\/Encrypt\b/', $rawSlice)) {
            throw MultimodalException::encryptedPdf();
        }

        if (! is_dir($outputDir) && ! @mkdir($outputDir, 0750, true) && ! is_dir($outputDir)) {
            throw MultimodalException::dependencyUnavailable('PDF_STAGING_UNAVAILABLE', 'Unable to allocate private staging directory for PDF rasterization.');
        }

        $driver = (string) config('ai_multimodal.pdf_rasterizer.driver', 'auto');
        if ($driver === 'pdftoppm' || ($driver === 'auto' && $this->hasPdftoppm())) {
            return $this->rasterizeWithPdftoppm($pdfPath, $outputDir, $maxPages, $dpi);
        }

        if (extension_loaded('imagick')) {
            return $this->rasterizeWithImagick($pdfPath, $outputDir, $maxPages, $dpi);
        }

        throw MultimodalException::dependencyUnavailable(
            'PDF_RASTERIZER_UNAVAILABLE',
            'PDF rendering engine (pdftoppm or Imagick/Ghostscript) is unavailable on this server. Upload PNG/JPEG page images instead.'
        );
    }

    private function hasPdftoppm(): bool
    {
        $bin = (string) config('ai_multimodal.pdf_rasterizer.pdftoppm_bin', 'pdftoppm');
        $probe = new Process([$bin, '-v']);
        $probe->setTimeout(5);
        try {
            $probe->run();

            return $probe->isSuccessful() || str_contains(strtolower($probe->getErrorOutput() . $probe->getOutput()), 'pdftoppm');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{page_count: int, page_paths: array<int, string>}
     */
    private function rasterizeWithPdftoppm(string $pdfPath, string $outputDir, int $maxPages, int $dpi): array
    {
        $estimatedPages = $this->detectPageCountWithPdfinfo($pdfPath);
        if ($estimatedPages !== null && $estimatedPages > $maxPages) {
            throw MultimodalException::tooManyPages($estimatedPages, $maxPages);
        }

        $bin = (string) config('ai_multimodal.pdf_rasterizer.pdftoppm_bin', 'pdftoppm');
        $prefix = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'page';
        $timeout = (int) config('ai_multimodal.pdf_rasterizer.timeout_seconds', 25);

        $process = new Process([
            $bin,
            '-png',
            '-r',
            (string) max(100, min(300, $dpi)),
            '-f',
            '1',
            '-l',
            (string) ($maxPages + 1),
            $pdfPath,
            $prefix,
        ]);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $err = strtolower($process->getErrorOutput());
            if (str_contains($err, 'password') || str_contains($err, 'incorrect password')) {
                throw MultimodalException::encryptedPdf();
            }

            throw MultimodalException::dependencyUnavailable(
                'PDF_RASTERIZER_UNAVAILABLE',
                'PDF page rasterization failed on this server.'
            );
        }

        $pages = glob($prefix . '-*.png') ?: [];
        sort($pages, SORT_NATURAL);
        $pageCount = count($pages);

        if ($pageCount > $maxPages) {
            foreach ($pages as $pageFile) {
                @unlink($pageFile);
            }
            throw MultimodalException::tooManyPages($pageCount, $maxPages);
        }

        if ($pageCount === 0) {
            throw MultimodalException::corruptedMedia('PDF rendered zero pages.');
        }

        return [
            'page_count' => $pageCount,
            'page_paths' => array_values($pages),
        ];
    }

    private function detectPageCountWithPdfinfo(string $pdfPath): ?int
    {
        $probe = new Process(['pdfinfo', $pdfPath]);
        $probe->setTimeout(5);
        try {
            $probe->run();
            if ($probe->isSuccessful() && preg_match('/^Pages:\s+(\d+)/m', $probe->getOutput(), $matches)) {
                return (int) $matches[1];
            }
        } catch (Throwable) {
            // Fall through to bounded page rendering count.
        }

        return null;
    }

    /**
     * @return array{page_count: int, page_paths: array<int, string>}
     */
    private function rasterizeWithImagick(string $pdfPath, string $outputDir, int $maxPages, int $dpi): array
    {
        try {
            $probe = new Imagick();
            $probe->pingImage($pdfPath);
            $pageCount = $probe->getNumberImages();
            $probe->clear();
            $probe->destroy();

            if ($pageCount > $maxPages) {
                throw MultimodalException::tooManyPages($pageCount, $maxPages);
            }
            if ($pageCount <= 0) {
                throw MultimodalException::corruptedMedia('PDF contains zero readable pages.');
            }

            $paths = [];
            for ($i = 0; $i < $pageCount; $i++) {
                $im = new Imagick();
                $im->setResolution($dpi, $dpi);
                $im->readImage($pdfPath . '[' . $i . ']');
                $im->setImageFormat('png');
                $target = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'page-' . ($i + 1) . '.png';
                $im->writeImage($target);
                $im->clear();
                $im->destroy();
                $paths[] = $target;
            }

            return [
                'page_count' => $pageCount,
                'page_paths' => $paths,
            ];
        } catch (MultimodalException $e) {
            throw $e;
        } catch (Throwable) {
            throw MultimodalException::dependencyUnavailable(
                'PDF_RASTERIZER_UNAVAILABLE',
                'PDF rasterizer (pdftoppm or Ghostscript/Imagick delegate) is unavailable on this server. Upload PNG/JPEG images instead.'
            );
        }
    }
}

