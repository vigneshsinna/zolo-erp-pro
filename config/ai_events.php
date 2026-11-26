<?php

declare(strict_types=1);

return [
    // Explicitly opt in after the events table is migrated. No externally exposed webhook.
    'internal_schedule_ingestion_enabled' => (bool) env('AI_INTERNAL_EVENT_INGESTION_ENABLED', false),
];
