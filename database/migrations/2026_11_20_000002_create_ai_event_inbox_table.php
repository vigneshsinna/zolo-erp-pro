<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_event_inbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('source_type', 40);
            $table->string('source_key', 140);
            $table->string('event_type', 80);
            $table->string('status', 40)->default('pending_review');
            $table->char('payload_hash', 64);
            $table->json('summary_json');
            $table->uuid('proposal_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['company_id', 'source_type', 'source_key'], 'uq_ai_event_source_company');
            $table->index(['company_id', 'branch_id', 'financial_year_id', 'actor_id', 'occurred_at'], 'idx_ai_event_scope');
        });
    }

    public function down(): void
    {
        // The audit inbox is retained on code rollback. Disable the feature flag.
    }
};
