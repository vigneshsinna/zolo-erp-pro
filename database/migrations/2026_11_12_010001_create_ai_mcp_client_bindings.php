<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_mcp_client_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('personal_access_token_id')->unique();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->json('allowed_tools');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['company_id', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_mcp_client_bindings');
    }
};
