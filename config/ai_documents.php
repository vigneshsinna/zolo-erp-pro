<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AI_DOCUMENTS_ENABLED', false),
    'disk' => 'local',
    'max_kilobytes' => 10240,
    'max_text_bytes' => 500000,
    'max_pages' => 50,
    'retention_days' => 30,
    // Executable paths are operator configuration, never request parameters.
    'clamav_binary' => env('AI_CLAMAV_BINARY'),
    'pdftotext_binary' => env('AI_PDFTOTEXT_BINARY'),
    'pdfinfo_binary' => env('AI_PDFINFO_BINARY'),
    'tesseract_binary' => env('AI_TESSERACT_BINARY'),
    'process_timeout' => 45,
    'max_import_rows' => 1000,
    'qdrant' => [
        'enabled' => (bool) env('AI_QDRANT_ENABLED', false),
        'url' => env('AI_QDRANT_URL'),
        'api_key' => env('AI_QDRANT_API_KEY'),
        'collection' => 'zolo_approved_knowledge_v1',
        'dimensions' => 1536,
        'embedding_provider' => env('AI_EMBEDDING_PROVIDER'),
        'embedding_model' => env('AI_EMBEDDING_MODEL'),
        'max_requests_per_company_day' => 100,
    ],
];
