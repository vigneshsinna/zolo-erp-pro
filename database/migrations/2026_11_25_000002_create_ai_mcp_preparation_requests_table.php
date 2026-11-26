<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_mcp_preparation_requests')) {
            Schema::create('ai_mcp_preparation_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('client_binding_id');
                $table->unsignedBigInteger('actor_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('financial_year_id');
                $table->string('capability_key', 120);
                $table->string('client_request_id', 128);
                $table->char('request_hash', 64);
                $table->string('status', 32)->default('in_progress');
                $table->uuid('task_id')->nullable();
                $table->unsignedInteger('task_revision')->nullable();
                $table->uuid('proposal_id')->nullable();
                $table->unsignedInteger('proposal_version')->nullable();
                $table->json('response_json')->nullable();
                $table->uuid('correlation_id');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(
                    ['client_binding_id', 'actor_id', 'company_id', 'branch_id', 'financial_year_id', 'capability_key', 'client_request_id'],
                    'ai_mcp_prep_req_scope_unique'
                );
                $table->index(['company_id', 'actor_id', 'created_at'], 'idx_ai_mcp_prep_company_actor');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_mcp_preparation_requests');
    }
};
