<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_media_extractions')) {
            Schema::create('ai_media_extractions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('company_id');
                $table->uuid('media_id');
                $table->unsignedInteger('revision')->default(1);
                $table->string('model_provider', 40);
                $table->string('model_key', 160);
                $table->string('prompt_version', 32);
                $table->string('extraction_schema_version', 32);
                $table->json('extracted_json');
                $table->json('source_evidence_json')->nullable();
                $table->json('normalized_input_json')->nullable();
                $table->string('confirmation_status', 32)->default('needs_review');
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->string('idempotency_key', 120)->nullable();
                $table->string('error_code', 80)->nullable();
                $table->timestamps();

                $table->unique(['media_id', 'revision'], 'uniq_ai_extract_media_rev');
                $table->index(['company_id', 'media_id'], 'idx_ai_extract_comp_media');
                $table->index(['media_id', 'idempotency_key'], 'idx_ai_extract_idempotency');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media_extractions');
    }
};

