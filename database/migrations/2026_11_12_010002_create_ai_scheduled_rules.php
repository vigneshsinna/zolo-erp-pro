<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_scheduled_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('name', 120);
            $table->string('kind', 40)->default('low_stock');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('prepare_purchase_order')->default(false);
            $table->json('inputs_json');
            $table->unsignedInteger('interval_minutes');
            $table->unsignedSmallInteger('max_runs_per_day')->default(1);
            $table->timestamp('next_run_at');
            $table->timestamp('expires_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'actor_id', 'is_active']);
            $table->index(['is_active', 'next_run_at']);
        });
        Schema::create('ai_schedule_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('rule_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedInteger('rule_revision');
            $table->timestamp('scheduled_for');
            $table->date('quota_date');
            $table->string('status', 32)->default('queued');
            $table->uuid('task_id')->nullable();
            $table->uuid('proposal_id')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['rule_id', 'scheduled_for']);
            $table->index(['company_id', 'actor_id', 'quota_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_schedule_runs');
        Schema::dropIfExists('ai_scheduled_rules');
    }
};
