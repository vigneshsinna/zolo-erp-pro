<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Global AI Enablement & Emergency Killswitch
    |--------------------------------------------------------------------------
    |
    | When disabled, all AI endpoints fail closed and reject incoming requests
    | immediately without querying any external model provider.
    |
    */

    'enabled' => env('AI_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Global Read-Only Enforcement
    |--------------------------------------------------------------------------
    |
    | Copilot is read-only. The executor applies this policy independently
    | of legacy company read_only settings.
    |
    */

    'read_only' => true,

    /*
    |--------------------------------------------------------------------------
    | Approved Model Gateway Defaults
    |--------------------------------------------------------------------------
    |
    | Default approved provider and model key. Client requests cannot specify
    | or override these server-selected providers.
    | Approved providers: openai, nvidia, deepseek, gemini, anthropic.
    | Default models include gpt-4o-mini and deepseek-ai/deepseek-v4.1-flash.
    |
    */

    'provider' => env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Operational Guardrails & Quotas
    |--------------------------------------------------------------------------
    |
    | Resource limits and safety boundaries per request and tenant.
    |
    */

    'max_tools_per_run' => (int) env('AI_MAX_TOOLS_PER_RUN', 3),
    'max_steps' => (int) env('AI_MAX_STEPS', 5),
    'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 4000),
    'max_output_rows' => (int) env('AI_MAX_OUTPUT_ROWS', 20),
    'daily_user_requests' => (int) env('AI_DAILY_USER_REQUESTS', 50),
    'daily_company_tokens' => (int) env('AI_DAILY_COMPANY_TOKENS', 100000),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1536),
    'timeout' => (int) env('AI_TIMEOUT', 45),
    'nvidia_rpm_limit' => (int) env('NVIDIA_RPM_LIMIT', 35),

    /*
    |--------------------------------------------------------------------------
    | Observability & Tracing
    |--------------------------------------------------------------------------
    |
    | When enabled, anonymized telemetry traces are recorded.
    |
    */

    'trace_enabled' => env('AI_TRACE_ENABLED', false),

];

