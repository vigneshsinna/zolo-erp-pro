<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_automation_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('reviewer_id');
            $table->string('outcome', 16); // correct, incorrect
            $table->unsignedSmallInteger('minutes_saved')->default(0);
            $table->unsignedInteger('hourly_rate_minor')->default(0); // paise, reviewer benchmark
            $table->unsignedBigInteger('estimated_gross_savings_minor')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'run_id'], 'uq_ai_automation_review_run');
            $table->index(['company_id', 'branch_id', 'financial_year_id', 'created_at'],
                'idx_ai_automation_review_scope');
        });
    }

    public function down(): void
    {
        // Reviews are decision evidence. Disable the review route on rollback;
        // never discard previously submitted accuracy/cost assumptions.
    }
};
