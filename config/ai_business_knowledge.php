<?php

return [
    // Read-only contextual facts are unavailable until an operator explicitly opts in.
    'enabled' => (bool) env('AI_BUSINESS_KNOWLEDGE_ENABLED', false),
    // PR 3: semantic language interpretation; independent rollout switch.
    'semantic_enabled' => (bool) env('AI_BUSINESS_SEMANTIC_ENABLED', false),
    'planner_enabled' => (bool) env('AI_BUSINESS_WORKFLOW_PLANNER_ENABLED', false),
    'service_bill_enabled' => (bool) env('AI_BUSINESS_SERVICE_BILL_ENABLED', false),
    'job_work_enabled' => (bool) env('AI_BUSINESS_JOB_WORK_ENABLED', false),
];
