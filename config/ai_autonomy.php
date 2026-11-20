<?php

declare(strict_types=1);

return [
    // Separate opt-in for administrator policy editing. No effect on ERP command execution.
    'policy_management_enabled' => (bool) env('AI_AUTONOMY_POLICY_MANAGEMENT_ENABLED', false),

    // PR02 never authorizes automatic ERP execution, regardless of stored preferences.
    // A later reviewed release must add a separately verifiable authorization mechanism.
    'automatic_execution_enabled' => false,
];
