<?php

declare(strict_types=1);

// Inspection only: this cannot execute ERP commands or approve a deployment.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Ai\Evaluation\AutonomyReleaseGate;

$options = getopt('', ['evidence::', 'output::', 'require-production-ready']);
$source = $options['evidence'] ?? null;
$output = $options['output'] ?? null;
$evidence = [];
if (is_string($source) && $source !== '') {
    if (!is_file($source)) {
        fwrite(STDERR, "Evidence file not found.\n");
        exit(2);
    }
    $evidence = json_decode((string) file_get_contents($source), true);
    if (!is_array($evidence)) {
        fwrite(STDERR, "Evidence must be a JSON object.\n");
        exit(2);
    }
}
$report = (new AutonomyReleaseGate())->evaluate($evidence, false);
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
if (is_string($output) && $output !== '') {
    $parent = dirname($output);
    if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
        fwrite(STDERR, "Cannot create output directory.\n");
        exit(2);
    }
    file_put_contents($output, $json);
}
fwrite(STDOUT, $json);
if (array_key_exists('require-production-ready', $options)) {
    // Explicit production gate deliberately fails before a separate reviewed safety release.
    exit($report['production_approved'] ? 0 : 1);
}
exit($report['status'] === 'BLOCKED' && !$report['production_approved'] ? 0 : 1);
