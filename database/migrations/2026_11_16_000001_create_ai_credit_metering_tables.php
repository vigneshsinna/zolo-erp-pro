<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_credit_accounts')) {
            Schema::create('ai_credit_accounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('subscription_id')->nullable()->index();
                $table->string('billing_entity_id')->nullable()->index();
                $table->string('currency', 3)->default('INR');
                $table->string('credit_policy_version')->default('cost_v1');
                $table->string('status')->default('active'); // active, frozen, cancelled
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_credit_account_companies')) {
            Schema::create('ai_credit_account_companies', function (Blueprint $table) {
                $table->id();
                $table->uuid('account_id')->index();
                $table->unsignedBigInteger('company_id')->index();
                $table->timestamps();

                $table->unique(['account_id', 'company_id']);
            });
        }

        if (! Schema::hasTable('ai_credit_periods')) {
            Schema::create('ai_credit_periods', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->index();
                $table->timestamp('period_start_utc');
                $table->timestamp('period_end_utc');
                $table->bigInteger('included_granted')->default(0);
                $table->bigInteger('topups_granted')->default(0);
                $table->bigInteger('spent_credits')->default(0);
                $table->bigInteger('reserved_credits')->default(0);
                $table->bigInteger('refunded_credits')->default(0);
                $table->string('status')->default('active'); // active, closing, closed
                $table->string('subscription_version')->nullable();
                $table->timestamps();

                $table->unique(['account_id', 'period_start_utc']);
            });
        }

        if (! Schema::hasTable('ai_credit_ledger')) {
            Schema::create('ai_credit_ledger', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->index();
                $table->uuid('period_id')->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('run_id')->nullable()->index();
                $table->string('model_invocation_id')->nullable();
                $table->string('provider_attempt_id')->nullable()->index();
                $table->string('kind'); // included_grant, topup_grant, usage_debit, reservation_release, credit_adjustment, expiry, refund
                $table->integer('amount_credits'); // signed: positive for grants/releases, negative for debits
                $table->string('currency', 3)->default('INR');
                $table->integer('cost_minor_inr')->nullable();
                $table->string('rate_card_version')->nullable();
                $table->string('fx_rate_version')->nullable();
                $table->string('source_type')->nullable();
                $table->string('source_id')->nullable();
                $table->string('idempotency_key')->nullable()->index();
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['account_id', 'idempotency_key'], 'uniq_ledger_account_idempotency');
            });
        }

        if (! Schema::hasTable('ai_credit_reservations')) {
            Schema::create('ai_credit_reservations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->index();
                $table->uuid('period_id')->index();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('actor_id')->index();
                $table->string('run_id')->index();
                $table->string('operation_key');
                $table->string('requested_model');
                $table->integer('estimated_credits');
                $table->integer('remaining_held_credits');
                $table->string('status')->default('active'); // active, partially_settled, settled, released, reconcile_required
                $table->string('idempotency_key')->index();
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->unique(['account_id', 'idempotency_key'], 'uniq_res_account_idempotency');
            });
        }

        if (! Schema::hasTable('ai_model_rate_cards')) {
            Schema::create('ai_model_rate_cards', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('version')->index();
                $table->string('provider')->index();
                $table->string('model')->index();
                $table->string('billable_unit'); // input_token, output_token, cached_input, image, audio_second
                $table->decimal('unit_price_usd_decimal', 14, 8);
                $table->timestamp('effective_from');
                $table->timestamp('effective_to')->nullable();
                $table->string('approved_by')->nullable();
                $table->timestamps();

                $table->unique(['version', 'provider', 'model', 'billable_unit'], 'uniq_rate_card_unit');
            });
        }

        if (! Schema::hasTable('ai_fx_rates')) {
            Schema::create('ai_fx_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('source'); // USD_INR
                $table->date('date');
                $table->decimal('rate_decimal', 10, 4);
                $table->string('approved_by')->nullable();
                $table->string('version');
                $table->timestamps();

                $table->unique(['source', 'date', 'version'], 'uniq_fx_source_date_ver');
            });
        }

        if (! Schema::hasTable('ai_credit_topup_orders')) {
            Schema::create('ai_credit_topup_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->index();
                $table->string('pack_sku');
                $table->integer('credits');
                $table->integer('amount_minor_inr');
                $table->integer('tax_minor_inr');
                $table->string('billing_cycle')->default('one_time');
                $table->string('payment_provider')->default('razorpay');
                $table->string('provider_order_id')->nullable()->index();
                $table->string('provider_payment_id')->nullable()->index();
                $table->string('status')->default('pending'); // pending, paid, failed, cancelled, refunded
                $table->uuid('credit_grant_ledger_id')->nullable();
                $table->string('idempotency_key')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_credit_payment_events')) {
            Schema::create('ai_credit_payment_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->nullable()->index();
                $table->string('payment_provider');
                $table->string('event_id')->index();
                $table->string('event_type');
                $table->string('payload_hash');
                $table->string('status')->default('processed'); // processed, ignored, failed
                $table->timestamps();

                $table->unique(['payment_provider', 'event_id'], 'uniq_pay_event');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_payment_events');
        Schema::dropIfExists('ai_credit_topup_orders');
        Schema::dropIfExists('ai_fx_rates');
        Schema::dropIfExists('ai_model_rate_cards');
        Schema::dropIfExists('ai_credit_reservations');
        Schema::dropIfExists('ai_credit_ledger');
        Schema::dropIfExists('ai_credit_periods');
        Schema::dropIfExists('ai_credit_account_companies');
        Schema::dropIfExists('ai_credit_accounts');
    }
};
