<?php

declare(strict_types=1);

// Used when Laravel Horizon is installed. Plain queue:work can consume the same queues.
return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'zolo_erp_horizon:'),
    'middleware' => ['web', 'auth'],
    'waits' => ['redis:ai-execution' => 60, 'redis:ai-telemetry' => 120],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit' => 128,
    'defaults' => [
        'ai-execution' => ['connection' => 'redis', 'queue' => ['ai-execution'], 'balance' => false, 'maxProcesses' => 1, 'maxTime' => 3600, 'maxJobs' => 100, 'memory' => 128, 'tries' => 1, 'timeout' => 60, 'nice' => 5],
        'ai-telemetry' => ['connection' => 'redis', 'queue' => ['ai-telemetry'], 'balance' => false, 'maxProcesses' => 1, 'maxTime' => 3600, 'maxJobs' => 500, 'memory' => 64, 'tries' => 3, 'timeout' => 10, 'nice' => 10],
        'ai-scheduled' => ['connection' => 'redis', 'queue' => ['ai-scheduled'], 'balance' => false, 'maxProcesses' => 1, 'maxTime' => 3600, 'maxJobs' => 100, 'memory' => 128, 'tries' => 1, 'timeout' => 60, 'nice' => 10],
    ],
    'environments' => ['production' => ['ai-execution' => [], 'ai-telemetry' => [], 'ai-scheduled' => []], 'local' => ['ai-execution' => [], 'ai-telemetry' => [], 'ai-scheduled' => []]],
];
