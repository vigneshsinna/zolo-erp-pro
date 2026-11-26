<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_media_assets')) {
            Schema::create('ai_media_assets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('financial_year_id');
                $table->unsignedBigInteger('actor_id');
                $table->uuid('thread_id')->nullable();
                $table->uuid('workflow_task_id')->nullable();
                $table->enum('source_kind', ['image', 'pdf', 'audio']);
                $table->string('original_file_name_sanitized', 180)->nullable();
                $table->string('original_mime', 80);
                $table->string('canonical_mime', 80);
                $table->unsignedBigInteger('size_bytes');
                $table->char('sha256', 64);
                $table->string('private_disk', 40);
                $table->string('private_key', 255);
                $table->json('derivatives_json')->nullable();
                $table->unsignedSmallInteger('page_count')->nullable();
                $table->decimal('duration_seconds', 8, 2)->nullable();
                $table->string('idempotency_key', 120)->nullable();
                $table->string('state', 32)->default('uploaded');
                $table->string('last_error_code', 80)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('deleted_at_policy')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'actor_id', 'state'], 'idx_ai_media_comp_actor_state');
                $table->index(['company_id', 'id'], 'idx_ai_media_comp_id');
                $table->index(['company_id', 'sha256'], 'idx_ai_media_comp_sha');
                $table->index(['company_id', 'created_at'], 'idx_ai_media_comp_created');
                $table->index(['state', 'expires_at'], 'idx_ai_media_state_expires');
                $table->unique(['company_id', 'actor_id', 'idempotency_key'], 'uniq_ai_media_idempotency');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media_assets');
    }
};

