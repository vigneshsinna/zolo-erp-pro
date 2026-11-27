<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('optical_rx_job_allocations')) {
            Schema::create('optical_rx_job_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->uuid('job_id');
                $table->string('source_type', 32); // delivery_challan | sale
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('source_line_id');
                $table->decimal('qty_allocated', 14, 4);
                $table->timestamp('released_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'source_type', 'source_id', 'source_line_id'], 'orja_company_source_idx');
                $table->index(['company_id', 'job_id'], 'orja_company_job_idx');
            });
        }

        if (Schema::hasTable('optical_rx_jobs') && !Schema::hasColumn('optical_rx_jobs', 'qc_inspector_id')) {
            Schema::table('optical_rx_jobs', function (Blueprint $table): void {
                $table->unsignedBigInteger('qc_inspector_id')->nullable()->after('created_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('optical_rx_jobs') && Schema::hasColumn('optical_rx_jobs', 'qc_inspector_id')) {
            Schema::table('optical_rx_jobs', function (Blueprint $table): void {
                $table->dropColumn('qc_inspector_id');
            });
        }

        Schema::dropIfExists('optical_rx_job_allocations');
    }
};
