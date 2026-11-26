<?php

declare(strict_types=1);

namespace App\Ai\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

class VirusScanner
{
    public function assertClean(string $path): void
    {
        $binary = config('ai_documents.clamav_binary');
        if (!is_string($binary) || $binary === '' || !is_file($binary)) {
            throw new RuntimeException('Document scanner is unavailable. File remains quarantined.');
        }
        $process = new Process([$binary, '--no-summary', '--', $path]);
        $process->setTimeout((int) config('ai_documents.process_timeout', 45));
        $process->disableOutput();
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Document scan did not pass. File remains quarantined.');
        }
    }
}
