<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_invocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_model_invocations', 'provider_attempt_id')) {
                $table->string('provider_attempt_id')->nullable()->index();
            }
            if (! Schema::hasColumn('ai_model_invocations', 'reservation_id')) {
                $table->uuid('reservation_id')->nullable()->index();
            }
            if (! Schema::hasColumn('ai_model_invocations', 'billing_account_id')) {
                $table->uuid('billing_account_id')->nullable()->index();
            }
            if (! Schema::hasColumn('ai_model_invocations', 'charge_status')) {
                $table->string('charge_status', 30)->nullable();
            }
            if (! Schema::hasColumn('ai_model_invocations', 'rate_card_version')) {
                $table->string('rate_card_version', 50)->nullable();
            }
            if (! Schema::hasColumn('ai_model_invocations', 'fx_rate_version')) {
                $table->string('fx_rate_version', 50)->nullable();
            }
            if (! Schema::hasColumn('ai_model_invocations', 'charge_minor_inr')) {
                $table->integer('charge_minor_inr')->nullable();
            }
            if (! Schema::hasColumn('ai_model_invocations', 'credits_debited')) {
                $table->integer('credits_debited')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_invocations', function (Blueprint $table): void {
            $cols = [
                'provider_attempt_id',
                'reservation_id',
                'billing_account_id',
                'charge_status',
                'rate_card_version',
                'fx_rate_version',
                'charge_minor_inr',
                'credits_debited',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('ai_model_invocations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
