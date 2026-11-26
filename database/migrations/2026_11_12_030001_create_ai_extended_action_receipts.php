<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_extended_action_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action_key', 100);
            $table->string('idempotency_key', 150);
            $table->char('payload_hash', 64);
            $table->json('receipt_json');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'idempotency_key'], 'ai_extended_receipt_company_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_extended_action_receipts');
    }
};
