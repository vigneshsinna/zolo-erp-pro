<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_model_invocations')) {
            Schema::create('ai_model_invocations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('run_id', 36)->index();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedSmallInteger('step_number')->default(1);
                $table->string('provider', 50)->index();
                $table->string('model', 120);
                $table->string('status', 30)->default('completed');
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'step_number'], 'idx_ai_model_inv_run_step');
                $table->index(['provider', 'created_at'], 'idx_ai_model_inv_prov_created');
                $table->index(['company_id', 'created_at'], 'idx_ai_model_inv_comp_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_invocations');
    }
};
