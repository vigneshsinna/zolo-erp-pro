<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_media_assets')) {
            Schema::table('ai_media_assets', function (Blueprint $table): void {
                if (! Schema::hasColumn('ai_media_assets', 'raw_private_key')) {
                    $table->string('raw_private_key', 255)->nullable()->after('private_key');
                }
                if (! Schema::hasColumn('ai_media_assets', 'preprocessing_metrics_json')) {
                    $table->json('preprocessing_metrics_json')->nullable()->after('derivatives_json');
                }
            });
        }

        if (! Schema::hasTable('ai_media_processing_tasks')) {
            Schema::create('ai_media_processing_tasks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('financial_year_id');
                $table->unsignedBigInteger('actor_id');
                $table->uuid('thread_id')->nullable();
                $table->uuid('workflow_task_id')->nullable();
                $table->unsignedInteger('expected_task_revision')->nullable();
                $table->uuid('media_id');
                $table->uuid('extraction_id')->nullable();
                $table->unsignedInteger('extraction_revision')->nullable();
                $table->uuid('proposal_id')->nullable();
                $table->unsignedInteger('proposal_version')->nullable();
                $table->string('client_request_id', 120);
                $table->char('request_hash', 64);
                $table->string('requested_action_key', 80)->nullable();
                $table->string('resolved_action_key', 80)->nullable();
                $table->text('companion_text_encrypted')->nullable();
                $table->string('resolution_mode', 40)->nullable();
                $table->boolean('force_reextract')->default(false);
                $table->boolean('is_optical_profile')->default(false);
                $table->string('source_kind', 20)->default('image');
                $table->string('state', 32)->default('queued');
                $table->string('current_stage', 32)->default('preprocess');
                $table->unsignedInteger('state_version')->default(1);
                $table->json('stages_json');
                $table->json('page_checkpoints_json')->nullable();
                $table->json('result_ref_json')->nullable();
                $table->string('model_provider', 40)->nullable();
                $table->string('model_key', 120)->nullable();
                $table->string('lease_owner', 120)->nullable();
                $table->uuid('lease_token')->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->unsignedSmallInteger('rate_limit_deferrals')->default(0);
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamp('deadline_at')->nullable();
                $table->timestamp('cancel_requested_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('error_code', 80)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->string('failed_stage', 32)->nullable();
                $table->boolean('is_retryable')->default(false);
                $table->string('correlation_id', 64);
                $table->string('preparation_idempotency_key', 120)->nullable();
                $table->timestamps();

                $table->unique(
                    ['company_id', 'branch_id', 'financial_year_id', 'actor_id', 'client_request_id'],
                    'uniq_ai_media_task_scope_req'
                );
                $table->index(['company_id', 'actor_id', 'thread_id', 'created_at'], 'idx_ai_media_task_thread');
                $table->index(['state', 'dispatched_at', 'lease_expires_at'], 'idx_ai_media_task_recovery');
                $table->index(['company_id', 'media_id'], 'idx_ai_media_task_media');
            });
        }

        if (! Schema::hasTable('ai_media_processing_events')) {
            Schema::create('ai_media_processing_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('task_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedInteger('state_version');
                $table->string('stage', 32);
                $table->string('event_type', 40);
                $table->string('from_state', 32)->nullable();
                $table->string('to_state', 32);
                $table->string('error_code', 80)->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamp('occurred_at_utc')->nullable();

                $table->index(['task_id', 'state_version'], 'idx_ai_media_events_task_ver');
            });
        }

        if (! Schema::hasTable('ai_media_provider_attempts')) {
            Schema::create('ai_media_provider_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('task_id')->nullable();
                $table->unsignedBigInteger('company_id');
                $table->uuid('media_id');
                $table->unsignedSmallInteger('page_number')->default(1);
                $table->unsignedSmallInteger('attempt_number')->default(1);
                $table->string('provider', 40);
                $table->string('model_key', 120);
                $table->string('provider_attempt_id', 80)->unique();
                $table->string('reservation_id', 80)->nullable();
                $table->string('provider_request_id', 120)->nullable();
                $table->string('status', 32)->default('admitted');
                $table->string('error_code', 80)->nullable();
                $table->unsignedInteger('http_status')->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['task_id', 'page_number', 'attempt_number'], 'uniq_ai_media_task_page_att');
                $table->index(['status', 'updated_at'], 'idx_ai_media_attempts_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media_provider_attempts');
        Schema::dropIfExists('ai_media_processing_events');
        Schema::dropIfExists('ai_media_processing_tasks');

        if (Schema::hasTable('ai_media_assets')) {
            Schema::table('ai_media_assets', function (Blueprint $table): void {
                $cols = [];
                if (Schema::hasColumn('ai_media_assets', 'raw_private_key')) {
                    $cols[] = 'raw_private_key';
                }
                if (Schema::hasColumn('ai_media_assets', 'preprocessing_metrics_json')) {
                    $cols[] = 'preprocessing_metrics_json';
                }
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
