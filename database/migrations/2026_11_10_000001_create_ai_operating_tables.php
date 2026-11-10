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
        // 1. Thread scopes (mapping SDK conversation history to tenant context)
        if (!Schema::hasTable('ai_thread_scopes')) {
            Schema::create('ai_thread_scopes', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('sdk_conversation_id', 36)->nullable()->index();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('financial_year_id');
                $table->string('title', 255)->default('New AI Session');
                $table->string('status', 32)->default('active');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'user_id', 'updated_at'], 'idx_ai_thread_tenant_user');
            });
        }

        // 2. AI Runs (tracking each conversational or async agent invocation)
        if (!Schema::hasTable('ai_runs')) {
            Schema::create('ai_runs', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('thread_id', 36)->nullable()->index();
                $table->unsignedBigInteger('actor_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('financial_year_id');
                $table->string('agent_key', 100);
                $table->string('model_provider', 80)->nullable();
                $table->string('model_key', 120)->nullable();
                $table->string('prompt_version', 32)->nullable();
                $table->string('status', 32)->default('queued');
                $table->string('request_hash', 64)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('error_code', 80)->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('estimated_cost_minor')->default(0);
                $table->string('trace_id', 64)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'created_at'], 'idx_ai_runs_company_created');
                $table->index(['actor_id', 'created_at'], 'idx_ai_runs_actor_created');
                $table->index(['status', 'created_at'], 'idx_ai_runs_status_created');
            });
        }

        // 3. AI Tool Invocations (authoritative tool execution and audit log)
        if (!Schema::hasTable('ai_tool_invocations')) {
            Schema::create('ai_tool_invocations', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('run_id', 36)->index();
                $table->string('tool_key', 100);
                $table->string('schema_version', 20)->default('v1');
                $table->string('scope_hash', 64)->nullable();
                $table->json('input_redacted')->nullable();
                $table->string('output_ref', 255)->nullable();
                $table->string('authorization_decision', 32)->default('authorized');
                $table->string('status', 32)->default('ok');
                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('error_code', 80)->nullable();
                $table->timestamps();

                $table->index(['run_id', 'created_at'], 'idx_ai_tool_run_created');
            });
        }

        // 4. AI Usage Counters (daily rate and budget tracking per company & user)
        if (!Schema::hasTable('ai_usage_counters')) {
            Schema::create('ai_usage_counters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->date('date');
                $table->unsignedInteger('request_count')->default(0);
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('estimated_cost_minor')->default(0);
                $table->timestamps();

                $table->unique(['company_id', 'user_id', 'date'], 'uniq_ai_usage_comp_user_date');
                $table->index(['company_id', 'date'], 'idx_ai_usage_comp_date');
            });
        }

        // 5. AI Feature Settings (per-company governance and instant killswitch)
        if (!Schema::hasTable('ai_feature_settings')) {
            Schema::create('ai_feature_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->boolean('read_only')->default(true);
                $table->string('provider_key', 80)->nullable();
                $table->json('allowed_agent_keys')->nullable();
                $table->unsignedInteger('daily_token_limit')->default(100000);
                $table->unsignedInteger('monthly_budget_minor')->default(0);
                $table->unsignedSmallInteger('retention_days')->default(30);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_feature_settings');
        Schema::dropIfExists('ai_usage_counters');
        Schema::dropIfExists('ai_tool_invocations');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_thread_scopes');
    }
};

