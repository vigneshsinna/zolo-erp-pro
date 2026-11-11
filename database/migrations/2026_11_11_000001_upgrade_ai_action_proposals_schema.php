<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive upgrade only. Historical proposal and execution rows stay intact,
 * including the disabled P3 records from the original implementation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_action_proposals', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->unsignedBigInteger('branch_id')->nullable()->change();
            $table->unsignedBigInteger('financial_year_id')->nullable()->change();

            // actor_id and action_type already exist in 2026_11_10_000005.
            $table->char('task_token', 64)->nullable()->after('actor_id');
            $table->string('action_key', 100)->nullable()->after('action_type');
            $table->unsignedInteger('version')->default(1)->after('action_key');
            $table->char('payload_hash', 64)->nullable()->after('payload_json');
            $table->char('source_state_hash', 64)->nullable()->after('payload_hash');

            $table->index(['actor_id', 'action_key', 'status'], 'idx_ai_proposal_bootstrap');
            $table->index(['company_id', 'branch_id', 'financial_year_id', 'status'], 'idx_ai_proposal_scope');
        });

        Schema::table('ai_action_approvals', function (Blueprint $table): void {
            // Legacy column is approver_id (not approver_user_id).
            $table->unsignedInteger('version')->default(1)->after('proposal_id');
            $table->char('approval_signature', 64)->nullable()->after('approver_id');
            $table->index(['proposal_id', 'version'], 'idx_ai_approval_version');
        });

        Schema::table('ai_action_executions', function (Blueprint $table): void {
            // Future bootstrap execution can be company-less, but only through isolated Mode A.
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->unsignedInteger('version')->default(1)->after('proposal_id');
            $table->string('domain_service', 128)->nullable()->after('executor_id');
            $table->string('domain_method', 64)->nullable()->after('domain_service');
            $table->string('erp_resource_type', 100)->nullable()->after('domain_method');
            $table->unsignedBigInteger('erp_resource_id')->nullable()->after('erp_resource_type');
            $table->unsignedInteger('duration_ms')->nullable()->after('result_json');
            // Existing result_json remains the authoritative execution receipt column.
            $table->index(['proposal_id', 'version', 'status'], 'idx_ai_execution_version');
            $table->index(['erp_resource_type', 'erp_resource_id'], 'idx_ai_execution_resource');
        });
    }

    public function down(): void
    {
        // Intentional: no destructive rollback of historical AI or approval data.
        // In deployments, disable action flags and roll back application code safely.
    }
};
