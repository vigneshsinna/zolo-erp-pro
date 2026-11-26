<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AI Evaluation Environment Guard
    |--------------------------------------------------------------------------
    |
    | When enabled, permits automated test corpus execution, benchmark runs,
    | and evaluation artifact export against allowlisted test databases.
    | Strictly disabled in production.
    |
    */
    'enabled' => (bool) env('AI_EVALUATION_ENABLED', env('APP_ENV') === 'testing'),

    /*
    |--------------------------------------------------------------------------
    | Staging Confirmation Token
    |--------------------------------------------------------------------------
    |
    | Optional secret token required to execute live model benchmarks on staging.
    |
    */
    'staging_token' => env('AI_STAGING_BENCHMARK_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Evaluation Artifacts Directory
    |--------------------------------------------------------------------------
    */
    'artifacts_path' => env('AI_EVALUATION_ARTIFACTS_PATH', base_path('artifacts/ai-evaluation')),
];
