<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_action_reversal_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->uuid('execution_id');
            $table->uuid('proposal_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 40)->default('pending_manual_review');
            $table->text('reason');
            $table->char('execution_receipt_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'execution_id'], 'uq_ai_reversal_execution');
            $table->index(['company_id', 'branch_id', 'financial_year_id', 'status'], 'idx_ai_reversal_scope');
        });
    }

    public function down(): void
    {
        // A reversal request is audit evidence. Disable the request entry point
        // during a rollback instead of silently deleting the evidence.
    }
};
