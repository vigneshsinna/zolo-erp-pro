<?php

declare(strict_types=1);

namespace App\Support\AiEvaluation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AssertSafeEvaluationEnvironment
{
    /**
     * Permitted database name prefixes for automated evaluation and benchmarks.
     */
    private const ALLOWED_DB_PREFIXES = [
        'zolo_test_',
        'zolo_test',
        'zolo_erp_test',
    ];

    /**
     * Assert that the current runtime environment is strictly isolated for evaluation.
     * Throws RuntimeException if executed in production or against non-test databases.
     *
     * @param bool $allowStagingExplicitly When true, allows a dedicated staging DB only if confirmed by token
     * @param string|null $confirmationToken Required confirmation token for non-testing environments
     * @throws RuntimeException
     */
    public static function assertSafe(bool $allowStagingExplicitly = false, ?string $confirmationToken = null): void
    {
        $env = app()->environment();
        $isEvaluationEnabled = (bool) config('ai_evaluation.enabled', env('AI_EVALUATION_ENABLED', false));

        // 1. Check environment
        if ($env !== 'testing') {
            if (! $allowStagingExplicitly) {
                throw new RuntimeException("AI evaluation execution forbidden in [{$env}] environment. APP_ENV must be 'testing'.");
            }

            $expectedToken = config('ai_evaluation.staging_token', env('AI_STAGING_BENCHMARK_TOKEN'));
            if (empty($expectedToken) || $confirmationToken !== $expectedToken) {
                throw new RuntimeException("AI evaluation in [{$env}] requires a valid AI_STAGING_BENCHMARK_TOKEN confirmation.");
            }
        }

        // 2. Check explicit evaluation enabled flag
        if (! $isEvaluationEnabled) {
            throw new RuntimeException("AI evaluation is disabled. Set AI_EVALUATION_ENABLED=true in the test environment.");
        }

        // 3. Check connected database name
        $dbName = (string) DB::connection()->getDatabaseName();
        $isAllowedDb = false;

        foreach (self::ALLOWED_DB_PREFIXES as $prefix) {
            if (str_starts_with($dbName, $prefix)) {
                $isAllowedDb = true;
                break;
            }
        }

        if (! $isAllowedDb) {
            throw new RuntimeException(
                "AI evaluation execution blocked: Connected database [{$dbName}] is not an allowlisted test database. " .
                "Must start with one of: " . implode(', ', self::ALLOWED_DB_PREFIXES)
            );
        }
    }

    /**
     * Generate secure, random credentials for test actors.
     *
     * @return array{email: string, password: string, name: string}
     */
    public static function generateEphemeralCredentials(): array
    {
        $random = Str::random(12);

        return [
            'name' => "Eval Actor {$random}",
            'email' => "eval_{$random}@test.internal",
            'password' => Str::random(32),
        ];
    }
}
