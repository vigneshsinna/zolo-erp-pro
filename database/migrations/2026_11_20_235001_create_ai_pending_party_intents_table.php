<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_pending_party_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->uuid('thread_id');
            $table->string('status', 20)->default('pending');
            $table->string('subject_hint', 200)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['company_id', 'actor_id', 'thread_id'], 'ai_party_intent_scope_unique');
            $table->index(['company_id', 'branch_id', 'financial_year_id', 'status'], 'ai_party_intent_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_party_intents');
    }
};
