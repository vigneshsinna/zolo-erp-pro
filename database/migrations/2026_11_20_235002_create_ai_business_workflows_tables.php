<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_business_workflows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->uuid('thread_id')->nullable();
            $table->uuid('workflow_task_id')->nullable();
            $table->string('process_id', 150);
            $table->string('knowledge_hash', 64);
            $table->string('status', 32)->default('planned');
            $table->unsignedInteger('revision')->default(1);
            $table->json('facts_json')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'financial_year_id', 'actor_id', 'status'], 'ai_bwf_owned_status');
        });

        Schema::create('ai_business_workflow_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('step_key', 100);
            $table->string('kind', 40);
            $table->string('action_key', 150)->nullable();
            $table->string('status', 32)->default('blocked');
            $table->json('depends_on_json');
            $table->uuid('proposal_id')->nullable();
            $table->string('evidence_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['workflow_id', 'step_key'], 'ai_bwf_step_unique');
            $table->index(['workflow_id', 'status'], 'ai_bwf_step_status');
        });

        Schema::create('ai_business_workflow_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedInteger('revision');
            $table->string('event_type', 60);
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workflow_id', 'revision'], 'ai_bwf_event_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_business_workflow_events');
        Schema::dropIfExists('ai_business_workflow_steps');
        Schema::dropIfExists('ai_business_workflows');
    }
};
