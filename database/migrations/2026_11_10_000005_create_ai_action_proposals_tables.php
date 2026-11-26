<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Action Proposals (P3: Reviewable Drafts & Action Proposals)
        Schema::create('ai_action_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->uuid('thread_id')->nullable();
            $table->string('action_type', 60);
            $table->string('action_title', 255);
            $table->string('risk_tier', 20)->default('r1_draft');
            $table->json('payload_json');
            $table->json('preview_json');
            $table->string('status', 30)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['actor_id', 'status']);
            $table->index('thread_id');
        });

        // 2. Action Approvals (P3: Human Review & Decision Audit)
        Schema::create('ai_action_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('proposal_id');
            $table->unsignedBigInteger('approver_id');
            $table->string('decision', 20); // approved, rejected
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('proposal_id');
            $table->index(['approver_id', 'created_at']);
        });

        // 3. Action Executions (P3: Safe Transactional Execution & Idempotency)
        Schema::create('ai_action_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('proposal_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('executor_id');
            $table->string('idempotency_key', 120);
            $table->string('status', 20)->default('completed'); // completed, failed
            $table->json('result_json');
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->useCurrent();

            $table->index('proposal_id');
            $table->unique(['company_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_action_executions');
        Schema::dropIfExists('ai_action_approvals');
        Schema::dropIfExists('ai_action_proposals');
    }
};

