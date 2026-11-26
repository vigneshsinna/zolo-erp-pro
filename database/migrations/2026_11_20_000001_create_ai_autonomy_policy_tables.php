<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_autonomy_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('action_key', 100);
            $table->string('requested_level', 32)->default('draft');
            $table->unsignedBigInteger('max_amount_minor')->default(0);
            $table->unsignedSmallInteger('daily_action_limit')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['company_id', 'action_key'], 'uq_ai_autonomy_company_action');
            $table->index(['company_id', 'updated_at'], 'idx_ai_autonomy_company_updated');
        });

        Schema::create('ai_autonomy_policy_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('action_key', 100);
            $table->unsignedInteger('policy_version');
            $table->unsignedBigInteger('actor_id');
            $table->json('previous_json')->nullable();
            $table->json('current_json');
            $table->char('event_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['company_id', 'action_key', 'policy_version'], 'uq_ai_autonomy_policy_event');
        });
    }

    public function down(): void
    {
        // Audit evidence must not be destroyed by an application-code rollback.
        // Disable AI_AUTONOMY_POLICY_MANAGEMENT_ENABLED and deploy old code instead.
    }
};
