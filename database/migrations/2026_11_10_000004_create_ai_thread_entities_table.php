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
        if (!Schema::hasTable('ai_thread_entities')) {
            Schema::create('ai_thread_entities', function (Blueprint $table) {
                $table->id();
                $table->uuid('thread_id')->index();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('financial_year_id');
                $table->string('entity_type', 50); // 'customer', 'invoice', 'warehouse', 'product'
                $table->unsignedBigInteger('entity_id');
                $table->string('entity_name', 191)->nullable();
                $table->date('as_of')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['thread_id', 'entity_type', 'created_at'], 'ai_thread_entities_lookup_idx');
                $table->index(['company_id', 'thread_id'], 'ai_thread_entities_scope_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_thread_entities');
    }
};

