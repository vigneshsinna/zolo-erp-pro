<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Phase 2.3 Multimodal Perception Killswitch (Disabled by Default)
    |--------------------------------------------------------------------------
    |
    | Both this global switch and per-company ai_feature_settings.multimodal_enabled
    | must be enabled before media upload, preprocessing, or Omni extraction runs.
    | Text-only Copilot conversations work independently without Omni.
    |
    */

    'enabled' => (bool) env('AI_MULTIMODAL_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Approved Perception Provider & Model
    |--------------------------------------------------------------------------
    |
    | Purpose-scoped separately from config('erp_ai.model') so Nemotron 3.5
    | Lightning stays dedicated to ERP tool orchestration and Nemotron 3 Nano
    | Omni Reasoning handles perception (image/PDF page OCR + audio STT) only.
    |
    */

    'provider' => (string) env('AI_MULTIMODAL_PROVIDER', 'nvidia'),
    'model' => (string) env('AI_MULTIMODAL_MODEL', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning'),

    /*
    |--------------------------------------------------------------------------
    | Multilingual Speech Routing (Disabled Until Benchmarked & Signed Off)
    |--------------------------------------------------------------------------
    */

    'multilingual_fallback_enabled' => (bool) env('AI_MULTIMODAL_MULTILINGUAL_ENABLED', false),
    'multilingual_provider' => env('AI_MULTIMODAL_MULTILINGUAL_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Direct Commercial Invoice Posting Policy
    |--------------------------------------------------------------------------
    |
    | When false (default), uploading an external vendor invoice/bill will
    | restrict direct General Ledger posting and route the user to stage a
    | Purchase Order (`purchase_order.create.v1`) to safeguard 3-way matching.
    | When true, direct Purchase Bill proposals (`purchase_bill.create.v1`)
    | can be staged from extracted media for human approval.
    |
    */

    'allow_direct_purchase_bill' => (bool) env('AI_MULTIMODAL_ALLOW_DIRECT_PURCHASE_BILL', false),
    'allow_direct_sales_invoice' => (bool) env('AI_MULTIMODAL_ALLOW_DIRECT_SALES_INVOICE', false),

    /*
    |--------------------------------------------------------------------------
    | Ingestion Security & Quotas
    |--------------------------------------------------------------------------
    */

    'remote_url_ingestion_enabled' => false,
    'storage_disk' => (string) env('AI_MULTIMODAL_DISK', 'ai_private'),
    'retention_hours' => max(1, (int) env('AI_MULTIMODAL_RETENTION_HOURS', 24)),
    'daily_company_uploads' => max(1, (int) env('AI_MULTIMODAL_DAILY_COMPANY_UPLOADS', 100)),
    'max_extraction_tokens' => max(256, (int) env('AI_MULTIMODAL_MAX_EXTRACTION_TOKENS', 4096)),
    'timeout_seconds' => max(10, (int) env('AI_MULTIMODAL_TIMEOUT_SECONDS', 75)),
    'connect_timeout_seconds' => max(3, (int) env('AI_MULTIMODAL_CONNECT_TIMEOUT_SECONDS', 10)),
    'max_retry_attempts' => max(0, (int) env('AI_MULTIMODAL_MAX_RETRY_ATTEMPTS', 2)),
    'adaptive_jpeg_for_photos' => (bool) env('AI_MULTIMODAL_ADAPTIVE_JPEG', false),

    /*
    |--------------------------------------------------------------------------
    | Asynchronous Media Preparation Pipeline (Default: Disabled / Fail-Closed)
    |--------------------------------------------------------------------------
    */

    'async' => [
        'enabled' => (bool) env('AI_MEDIA_ASYNC_ENABLED', false),
        'queue_connection' => (string) env('AI_MEDIA_QUEUE_CONNECTION', 'ai_media_database'),
        'queue_name' => (string) env('AI_MEDIA_QUEUE_NAME', 'ai-perception'),
        'job_timeout_seconds' => max(30, (int) env('AI_MEDIA_JOB_TIMEOUT', 180)),
        'lease_ttl_seconds' => max(60, (int) env('AI_MEDIA_LEASE_TTL_SECONDS', 210)),
        'task_deadline_seconds' => max(120, (int) env('AI_MEDIA_TASK_DEADLINE_SECONDS', 600)),
        'max_provider_attempts_per_page' => max(1, (int) env('AI_MEDIA_MAX_PROVIDER_ATTEMPTS', 2)),
        'max_rate_limit_deferrals' => max(1, (int) env('AI_MEDIA_MAX_RATE_LIMIT_DEFERRALS', 5)),
        'provider_immediate_retries' => max(0, (int) env('AI_MEDIA_PROVIDER_IMMEDIATE_RETRIES', 0)),
        'retry_backoff_seconds' => [15, 30, 60],
        'poll_after_ms' => max(500, (int) env('AI_MEDIA_POLL_AFTER_MS', 2000)),
        'worker_heartbeat_ttl_seconds' => max(30, (int) env('AI_MEDIA_WORKER_HEARTBEAT_TTL_SECONDS', 120)),
        'require_worker_heartbeat' => (bool) env('AI_MEDIA_REQUIRE_WORKER_HEARTBEAT', false),
        'outbox_dispatch_grace_seconds' => max(5, (int) env('AI_MEDIA_OUTBOX_GRACE_SECONDS', 15)),
        'abandoned_task_ttl_hours' => max(1, (int) env('AI_MEDIA_ABANDONED_TTL_HOURS', 24)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Format, Pixel, Page & Audio Limits
    |--------------------------------------------------------------------------
    */

    'max_input_bytes' => max(1024, (int) env('AI_MULTIMODAL_MAX_INPUT_BYTES', 10485760)), // 10 MB
    'max_pdf_bytes' => max(1024, (int) env('AI_MULTIMODAL_MAX_PDF_BYTES', 20971520)), // 20 MB
    'max_image_pixels' => max(100000, (int) env('AI_MULTIMODAL_MAX_IMAGE_PIXELS', 20000000)), // 20 MP
    'max_image_dimension' => max(512, (int) env('AI_MULTIMODAL_MAX_IMAGE_DIM', 2048)),
    'max_pdf_pages' => max(1, (int) env('AI_MULTIMODAL_MAX_PDF_PAGES', 5)),
    'max_audio_bytes' => max(1024, (int) env('AI_MULTIMODAL_MAX_AUDIO_BYTES', 20971520)), // 20 MB
    'max_audio_seconds' => max(5, (int) env('AI_MULTIMODAL_MAX_AUDIO_SECONDS', 120)),
    'max_invoice_lines' => max(1, (int) env('AI_MULTIMODAL_MAX_INVOICE_LINES', 100)),

    'allowed_image_mimes' => ['image/jpeg', 'image/png'],
    'allowed_pdf_mimes' => ['application/pdf'],
    'allowed_audio_mimes' => ['audio/webm', 'video/webm', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/mpeg', 'audio/mp3', 'audio/flac', 'audio/x-flac', 'audio/ogg'],

    /*
    |--------------------------------------------------------------------------
    | Backend Rasterization & Audio Transcoding Drivers
    |--------------------------------------------------------------------------
    */

    'pdf_rasterizer' => [
        'driver' => (string) env('AI_MULTIMODAL_PDF_DRIVER', 'auto'),
        'dpi' => max(100, min(300, (int) env('AI_MULTIMODAL_PDF_DPI', 175))),
        'pdftoppm_bin' => (string) env('PDFTOPPM_BIN', 'pdftoppm'),
        'timeout_seconds' => 25,
    ],

    'audio_transcoder' => [
        'driver' => (string) env('AI_MULTIMODAL_AUDIO_DRIVER', 'auto'),
        'ffmpeg_bin' => (string) env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe_bin' => (string) env('FFPROBE_BIN', 'ffprobe'),
        'sample_rate' => 16000,
        'channels' => 1,
        'timeout_seconds' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioned Extraction Prompt & Schema Identifiers
    |--------------------------------------------------------------------------
    */

    'schema_versions' => [
        'invoice' => 'media.invoice.v1',
        'invoice_optical' => 'media.invoice.optical.v2',
        'transcript' => 'media.transcript.v1',
        'normalized_input' => 'normalized.input.v1',
    ],

    'prompt_versions' => [
        'invoice' => 'prompt.invoice.v1',
        'invoice_optical' => 'prompt.invoice.optical.v2',
        'transcript' => 'prompt.transcript.v1',
    ],
];

