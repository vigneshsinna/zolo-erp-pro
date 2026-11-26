<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_provider_cost_variances')) {
            Schema::create('ai_provider_cost_variances', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->index();
                $table->uuid('period_id')->index();
                $table->uuid('reservation_id')->nullable()->index();
                $table->string('provider_attempt_id')->unique();
                $table->string('provider')->index();
                $table->string('model')->index();
                $table->integer('actual_cost_minor_inr');
                $table->integer('customer_funded_credits');
                $table->integer('unfunded_cost_minor_inr');
                $table->integer('unfunded_credits');
                $table->string('rate_card_version');
                $table->string('fx_rate_version');
                $table->string('incident_status')->default('unfunded_overage'); // unfunded_overage, resolved, written_off
                $table->text('resolution_reason')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('ai_credit_reservations') && ! Schema::hasColumn('ai_credit_reservations', 'request_digest')) {
            Schema::table('ai_credit_reservations', function (Blueprint $table) {
                $table->string('request_digest', 64)->nullable()->after('idempotency_key');
            });
        }

        if (Schema::hasTable('ai_credit_periods') && ! Schema::hasColumn('ai_credit_periods', 'reconcile_required')) {
            Schema::table('ai_credit_periods', function (Blueprint $table) {
                $table->boolean('reconcile_required')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_cost_variances');
        if (Schema::hasTable('ai_credit_reservations') && Schema::hasColumn('ai_credit_reservations', 'request_digest')) {
            Schema::table('ai_credit_reservations', function (Blueprint $table) {
                $table->dropColumn('request_digest');
            });
        }
        if (Schema::hasTable('ai_credit_periods') && Schema::hasColumn('ai_credit_periods', 'reconcile_required')) {
            Schema::table('ai_credit_periods', function (Blueprint $table) {
                $table->dropColumn('reconcile_required');
            });
        }
    }
};
