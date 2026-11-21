<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Add explicit FY ownership to previously company/branch-scoped principal jobs. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('principal_job_orders')) return;
        if (!Schema::hasColumn('principal_job_orders', 'financial_year_id')) {
            Schema::table('principal_job_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('financial_year_id')->nullable()->index('principal_job_fy_scope');
            });
        }
        // Only backfill where the business date matches exactly one tenant FY. Ambiguous history remains blocked.
        if (Schema::hasTable('fiscal_years') && Schema::hasColumn('fiscal_years', 'company_id')) {
            DB::table('principal_job_orders')->whereNull('financial_year_id')->orderBy('id')
                ->chunkById(250, function ($orders): void {
                    foreach ($orders as $order) {
                        $candidates = DB::table('fiscal_years')->where('company_id', $order->company_id)
                            ->where('start_date', '<=', $order->business_date)
                            ->where('end_date', '>=', $order->business_date)->limit(2)->pluck('id');
                        if ($candidates->count() === 1) {
                            DB::table('principal_job_orders')->where('id', $order->id)
                                ->where('company_id', $order->company_id)->whereNull('financial_year_id')
                                ->update(['financial_year_id' => (int) $candidates[0]]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('principal_job_orders')
            && Schema::hasColumn('principal_job_orders', 'financial_year_id')) {
            Schema::table('principal_job_orders', function (Blueprint $table): void {
                $table->dropIndex('principal_job_fy_scope');
                $table->dropColumn('financial_year_id');
            });
        }
    }
};
