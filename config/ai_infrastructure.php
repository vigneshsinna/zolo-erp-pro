<?php

declare(strict_types=1);

return [
    'async_execution_enabled' => (bool) env('AI_ASYNC_EXECUTION_ENABLED', false),
    'queue_connection' => env('AI_QUEUE_CONNECTION', 'redis'),
    // Horizon is installation-wide and may expose jobs from every company.
    'operations_admin_actor_ids' => array_values(array_filter(array_map('intval', explode(',',
        (string) env('AI_OPERATIONS_ADMIN_ACTOR_IDS', ''))), static fn (int $id): bool => $id > 0)),
    'scheduling' => [
        'enabled' => (bool) env('AI_SCHEDULING_ENABLED', false),
        'max_rules_per_actor' => 10,
        'max_company_runs_per_day' => 100,
        'claim_batch_size' => 50,
    ],
    'mcp' => [
        'enabled' => (bool) env('AI_MCP_ENABLED', false),
        'outbound' => [
            'enabled' => (bool) env('AI_MCP_OUTBOUND_ENABLED', false),
            // Code-reviewed server URLs, tool schemas, permission requirements and per-company/branch credentials.
            // Never populate this registry from model output or client request parameters.
            'servers' => [],
            'max_response_bytes' => 65536,
        ],
        // Exact browser origins. Requests without Origin still require a bound bearer token.
        'allowed_origins' => array_values(array_filter(explode(',', (string) env('AI_MCP_ALLOWED_ORIGINS', '')))),
        'tools' => ['receivables.outstanding.v1', 'inventory.stock_summary.v1'],
        'max_request_bytes' => 16384,
        'max_response_bytes' => 65536,
    ],
    'langfuse' => [
        'enabled' => (bool) env('AI_LANGFUSE_ENABLED', false),
        'url' => env('LANGFUSE_URL', 'https://cloud.langfuse.com'),
        'allowed_hosts' => ['cloud.langfuse.com', 'us.cloud.langfuse.com'],
        'public_key' => env('LANGFUSE_PUBLIC_KEY'),
        'secret_key' => env('LANGFUSE_SECRET_KEY'),
        // Optional private contract rates in USD per million tokens. Never assume current public prices.
        'model_rates' => [],
    ],
];
