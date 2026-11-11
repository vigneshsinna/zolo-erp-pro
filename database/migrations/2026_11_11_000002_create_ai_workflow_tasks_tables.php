<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive schema for Phase 2.1 Unified Multi-Slot Input Collection & Clarification Engine.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ai_workflow_tasks')) {
            Schema::create('ai_workflow_tasks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('action_key', 100);
                $table->string('mode', 20)->default('company'); // company, bootstrap
                $table->unsignedBigInteger('actor_id');
                $table->string('thread_id', 120)->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('financial_year_id')->nullable();
                $table->string('bootstrap_task_id', 120)->nullable();
                $table->string('status', 30)->default('collecting'); // collecting, validating, ready, proposal_created, cancelled, expired
                $table->unsignedInteger('revision')->default(1);
                $table->json('slots_json');
                $table->json('resolution_json')->nullable();
                $table->json('issues_json')->nullable();
                $table->json('provenance_json')->nullable();
                $table->uuid('linked_proposal_id')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'actor_id', 'status'], 'idx_wf_tasks_company_actor');
                $table->index(['actor_id', 'status'], 'idx_wf_tasks_actor_status');
                $table->index('thread_id', 'idx_wf_tasks_thread');
                $table->index('linked_proposal_id', 'idx_wf_tasks_proposal');
            });
        }

        if (!Schema::hasTable('ai_workflow_task_events')) {
            Schema::create('ai_workflow_task_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('task_id');
                $table->unsignedBigInteger('actor_id');
                $table->string('event_type', 50); // created, patched, evaluated, proposal_linked, cancelled
                $table->unsignedInteger('revision');
                $table->json('patch_json')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('task_id', 'idx_wf_task_events_task');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_workflow_task_events');
        Schema::dropIfExists('ai_workflow_tasks');
    }
};
