<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reminder_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('open_item_id');
            $table->uuid('proposal_id');
            $table->unsignedInteger('proposal_version');
            $table->string('idempotency_key', 150);
            $table->char('payload_hash', 64);
            $table->char('source_state_hash', 64);
            $table->longText('payload_encrypted');
            $table->string('status', 40)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_category', 60)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'ai_reminder_company_idempotency');
            $table->index(['company_id', 'branch_id', 'status'], 'ai_reminder_scope_status');
            $table->index(['company_id', 'created_at'], 'ai_reminder_company_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reminder_outbox');
    }
};
