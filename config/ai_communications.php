<?php

declare(strict_types=1);

return [
    'reminders_enabled' => (bool) env('AI_PAYMENT_REMINDERS_ENABLED', false),
    'queue_connection' => env('AI_COMMUNICATION_QUEUE_CONNECTION', 'redis'),
    'queue' => 'ai-communications',
    'daily_company_limit' => (int) env('AI_REMINDER_DAILY_COMPANY_LIMIT', 100),
];
