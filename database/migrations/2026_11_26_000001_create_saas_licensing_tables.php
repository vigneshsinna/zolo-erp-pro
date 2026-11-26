<?php

use App\Support\MigrationConstraints;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\SaasPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('saas_plans')) {
            Schema::create('saas_plans', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50);
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('billing_cycle', 20)->default('monthly');
                $table->decimal('price', 14, 2)->default(0);
                $table->string('currency_code', 10)->default('INR');
                $table->unsignedInteger('trial_days')->default(14);
                $table->unsignedInteger('grace_days')->default(7);
                $table->unsignedInteger('max_users')->nullable();
                $table->unsignedInteger('max_branches')->nullable();
                $table->unsignedInteger('max_warehouses')->nullable();
                $table->unsignedInteger('max_monthly_invoices')->nullable();
                $table->unsignedInteger('ai_monthly_credits')->nullable();
                $table->unsignedInteger('ai_monthly_media_pages')->nullable();
                $table->unsignedInteger('ai_max_concurrent_runs')->nullable();
                $table->unsignedInteger('ai_daily_token_limit')->nullable();
                $table->boolean('ai_read_only')->default(true);
                $table->json('modules_json');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_system')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('company_licenses')) {
            Schema::create('company_licenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('saas_plan_id')->nullable();
                $table->string('license_key', 64);
                $table->string('status', 25)->default('active');
                $table->string('billing_cycle', 20)->default('monthly');
                $table->date('starts_at')->nullable();
                $table->date('expires_at')->nullable();
                $table->date('grace_ends_at')->nullable();
                $table->timestamp('suspended_at')->nullable();
                $table->string('suspension_reason', 500)->nullable();
                $table->unsignedInteger('max_users')->nullable();
                $table->unsignedInteger('max_branches')->nullable();
                $table->unsignedInteger('max_warehouses')->nullable();
                $table->unsignedInteger('max_monthly_invoices')->nullable();
                $table->unsignedInteger('ai_monthly_credits')->nullable();
                $table->unsignedInteger('ai_monthly_media_pages')->nullable();
                $table->unsignedInteger('ai_max_concurrent_runs')->nullable();
                $table->unsignedInteger('ai_daily_token_limit')->nullable();
                $table->boolean('ai_read_only')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('managed_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('company_license_modules')) {
            Schema::create('company_license_modules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('capability_key', 100);
                $table->string('state', 16)->default('grant');
                $table->date('expires_at')->nullable();
                $table->string('notes', 255)->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('company_license_audits')) {
            Schema::create('company_license_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('saas_plan_id')->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('action', 60);
                $table->string('summary', 255);
                $table->json('before_json')->nullable();
                $table->json('after_json')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        MigrationConstraints::unique('saas_plans', 'saas_plans_code_unique', ['code']);
        MigrationConstraints::unique('company_licenses', 'company_licenses_company_id_unique', ['company_id']);
        MigrationConstraints::unique('company_licenses', 'company_licenses_license_key_unique', ['license_key']);
        MigrationConstraints::unique('company_license_modules', 'company_license_modules_company_cap_unique', ['company_id', 'capability_key']);
        MigrationConstraints::index('company_license_audits', 'company_license_audits_company_idx', ['company_id', 'created_at']);

        if (Schema::hasTable('companies')) {
            MigrationConstraints::foreign('company_licenses', 'company_licenses_company_id_foreign', ['company_id'], 'companies');
            MigrationConstraints::foreign('company_license_modules', 'company_license_modules_company_id_foreign', ['company_id'], 'companies');
        }
        MigrationConstraints::foreign('company_licenses', 'company_licenses_saas_plan_id_foreign', ['saas_plan_id'], 'saas_plans');

        foreach (['ai_monthly_credits', 'ai_monthly_media_pages', 'ai_max_concurrent_runs', 'ai_daily_token_limit', 'ai_read_only'] as $col) {
            foreach (['saas_plans', 'company_licenses'] as $tbl) {
                if (!Schema::hasColumn($tbl, $col)) {
                    Schema::table($tbl, function (Blueprint $table) use ($col, $tbl) {
                        if ($col === 'ai_read_only') {
                            $tbl === 'saas_plans' ? $table->boolean($col)->default(true) : $table->boolean($col)->nullable();
                        } else {
                            $table->unsignedInteger($col)->nullable();
                        }
                    });
                }
            }
        }

        if (Schema::hasTable('capabilities')) {
            (new CapabilitySeeder)->run();
        }
        (new SaasPlanSeeder)->run();

        // Preserve full access for existing companies by assigning the Enterprise baseline license idempotently.
        if (Schema::hasTable('companies')) {
            $enterprisePlanId = DB::table('saas_plans')->where('code', 'enterprise')->value('id');
            $existingIds = DB::table('companies')->pluck('id');
            foreach ($existingIds as $companyId) {
                if (!DB::table('company_licenses')->where('company_id', $companyId)->exists()) {
                    DB::table('company_licenses')->insert([
                        'company_id' => $companyId,
                        'saas_plan_id' => $enterprisePlanId,
                        'license_key' => 'ZOLO-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
                        'status' => 'active',
                        'billing_cycle' => 'lifetime',
                        'starts_at' => now()->toDateString(),
                        'expires_at' => null,
                        'grace_ends_at' => null,
                        'notes' => 'Auto-initialized Enterprise baseline for existing company.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_license_audits');
        Schema::dropIfExists('company_license_modules');
        Schema::dropIfExists('company_licenses');
        Schema::dropIfExists('saas_plans');
    }
};
