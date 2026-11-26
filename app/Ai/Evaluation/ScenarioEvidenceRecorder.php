<?php

declare(strict_types=1);

namespace App\Ai\Evaluation;

use Illuminate\Support\Str;

class ScenarioEvidenceRecorder
{
    private static ?string $currentRunId = null;
    private static ?string $cachedGitSha = null;
    /** @var array<string, array<string, mixed>> */
    private static array $inMemoryEvents = [];

    public static function getRunId(): string
    {
        if (self::$currentRunId === null) {
            self::$currentRunId = getenv('AI_EVALUATION_RUN_ID') ?: (string) Str::uuid();
        }

        return self::$currentRunId;
    }

    public static function setRunId(string $runId): void
    {
        self::$currentRunId = $runId;
    }

    public static function getGitSha(): string
    {
        if (self::$cachedGitSha !== null) {
            return self::$cachedGitSha;
        }

        $sha = null;
        $output = @shell_exec('git rev-parse HEAD');
        if ($output) {
            $sha = trim($output);
        }

        if (! $sha && file_exists(base_path('.git/HEAD'))) {
            $headContent = trim(file_get_contents(base_path('.git/HEAD')));
            if (str_starts_with($headContent, 'ref: ')) {
                $refPath = base_path('.git/' . trim(substr($headContent, 5)));
                if (file_exists($refPath)) {
                    $sha = trim(file_get_contents($refPath));
                }
            } else {
                $sha = $headContent;
            }
        }

        self::$cachedGitSha = $sha ?: (getenv('GITHUB_SHA') ?: 'unknown-sha');

        return self::$cachedGitSha;
    }

    /**
     * Record an authentic scenario test event.
     *
     * @param array{
     *     scenario_id: string,
     *     status: string,
     *     test_file: string,
     *     test_class?: string,
     *     test_method?: string,
     *     evidence_scope?: string,
     *     execution_mode: string,
     *     assertion_count?: int,
     *     provider_attempt_ids?: array<string>,
     *     ledger_entry_ids?: array<string>,
     *     source_media_sha256?: ?string,
     *     expected_snapshot_sha256?: ?string,
     *     actual_output_sha256?: ?string,
     *     latency_ms?: int,
     *     error_codes?: array<string>,
     *     details?: array<string, mixed>
     * } $data
     */
    public static function recordEvent(array $data): array
    {
        $scenarioId = (string) ($data['scenario_id'] ?? '');
        if ($scenarioId === '') {
            throw new \InvalidArgumentException('scenario_id is required for evidence recording.');
        }

        $event = [
            'scenario_id' => $scenarioId,
            'git_sha' => self::getGitSha(),
            'test_run_id' => self::getRunId(),
            'test_file' => (string) ($data['test_file'] ?? 'unknown'),
            'test_class' => (string) ($data['test_class'] ?? ''),
            'test_method' => (string) ($data['test_method'] ?? ''),
            'evidence_scope' => (string) ($data['evidence_scope'] ?? 'unspecified'),
            'execution_mode' => (string) ($data['execution_mode'] ?? 'fixture_mocked_model'),
            'status' => (string) ($data['status'] ?? 'passed'),
            'assertion_count' => (int) ($data['assertion_count'] ?? 1),
            'provider_attempt_ids' => $data['provider_attempt_ids'] ?? [],
            'ledger_entry_ids' => $data['ledger_entry_ids'] ?? [],
            'source_media_sha256' => $data['source_media_sha256'] ?? null,
            'expected_snapshot_sha256' => $data['expected_snapshot_sha256'] ?? null,
            'actual_output_sha256' => $data['actual_output_sha256'] ?? null,
            'latency_ms' => isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            'error_codes' => $data['error_codes'] ?? [],
            'details' => $data['details'] ?? [],
            'recorded_at' => now()->toIso8601String(),
        ];

        self::$inMemoryEvents[$scenarioId] = $event;

        $artifactsDir = base_path('artifacts');
        if (! is_dir($artifactsDir)) {
            @mkdir($artifactsDir, 0755, true);
        }

        $filePath = getenv('AI_SCENARIO_EVENTS_PATH') ?: $artifactsDir . DIRECTORY_SEPARATOR . 'scenario-events.jsonl';
        file_put_contents($filePath, json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

        return $event;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function loadFromDisk(?string $path = null): array
    {
        $filePath = $path ?: base_path('artifacts/scenario-events.jsonl');
        if (! file_exists($filePath)) {
            return self::$inMemoryEvents;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $events = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['scenario_id'])) {
                $events[$decoded['scenario_id']] = $decoded;
            }
        }

        return $events;
    }

    public static function clear(): void
    {
        self::$inMemoryEvents = [];
        $filePath = base_path('artifacts/scenario-events.jsonl');
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
}
