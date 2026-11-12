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
    'multimodal_config' => 'ai_multimodal',

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
    'daily_company_tokens' => (int) env('AI_DAILY_COMPANY_TOKENS', 500000),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 4096),
    'timeout' => (int) env('AI_TIMEOUT', 45),
    'nvidia_rpm_limit' => (int) env('NVIDIA_RPM_LIMIT', 35),

    /*
    |--------------------------------------------------------------------------
    | New Company Provisioning Policy
    |--------------------------------------------------------------------------
    |
    | Governs how AI features are provisioned for newly created companies
    | (e.g. via Guided Initial Setup or CompanyDirectoryService).
    | If the installation has AI enabled, initializes the company with AI enabled,
    | restricted to authorized roles, with execution disabled (read_only = true)
    | until explicitly configured by an administrator.
    |
    */

    'provisioning_policy' => [
        'inherit_installation_enabled' => (bool) env('AI_PROVISION_NEW_COMPANIES_ENABLED', true),
        'default_read_only' => (bool) env('AI_PROVISION_DEFAULT_READ_ONLY', true),
        'default_agents' => ['erp_copilot'],
    ],

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

