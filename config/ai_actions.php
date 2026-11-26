<?php

declare(strict_types=1);

return [
    /*
     * Phase 1 is foundation-only. The existing read-only Copilot remains
     * untouched, and there are NO registered executable action adapters.
     * Both switches must be explicitly enabled in a later reviewed phase.
     */
    'foundation_enabled' => (bool) env('AI_ACTION_FOUNDATION_ENABLED', false),
    'execution_enabled' => (bool) env('AI_ACTION_EXECUTION_ENABLED', false),
    'max_pending_per_actor' => 25,
    'proposal_ttl_minutes' => (int) env('AI_PROPOSAL_TTL_MINUTES', 1440),
];
