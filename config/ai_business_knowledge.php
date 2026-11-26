<?php

return [
    // Read-only contextual facts are unavailable until an operator explicitly opts in.
    'enabled' => (bool) env('AI_BUSINESS_KNOWLEDGE_ENABLED', false),
    // PR 3: semantic language interpretation; independent rollout switch.
    'semantic_enabled' => (bool) env('AI_BUSINESS_SEMANTIC_ENABLED', false),
    'planner_enabled' => (bool) env('AI_BUSINESS_WORKFLOW_PLANNER_ENABLED', false),
    'service_bill_enabled' => (bool) env('AI_BUSINESS_SERVICE_BILL_ENABLED', false),
    'job_work_enabled' => (bool) env('AI_BUSINESS_JOB_WORK_ENABLED', false),
    'opticals_enabled' => (bool) env('AI_OPTICALS_ENABLED', false),
    'opticals_lab_enabled' => (bool) env('AI_OPTICALS_LAB_ENABLED', false),
    'opticals_cross_store_enabled' => (bool) env('AI_OPTICALS_CROSS_STORE_ENABLED', false),
    'opticals_mcp_enabled' => (bool) env('AI_OPTICALS_MCP_ENABLED', false),
];
