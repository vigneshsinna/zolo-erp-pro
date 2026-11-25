<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_branch_industry_roles')) {
            Schema::create('company_branch_industry_roles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->string('role_key', 40);
                $table->json('settings_json')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'branch_id'], 'cbir_company_branch_unique');
                $table->index(['company_id', 'role_key'], 'cbir_company_role_idx');
            });
        }

        if (!Schema::hasTable('optical_lens_identities')) {
            Schema::create('optical_lens_identities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->string('lens_family', 120);
                $table->string('lens_material', 80)->nullable();
                $table->string('refractive_index', 20)->nullable();
                $table->string('coating', 80)->nullable();
                $table->string('lens_form', 32)->default('finished_stock');
                $table->string('sph', 16)->nullable();
                $table->string('cyl', 16)->nullable();
                $table->string('axis', 16)->nullable();
                $table->string('addition', 16)->nullable();
                $table->string('base_curve', 16)->nullable();
                $table->string('dia', 16)->nullable();
                $table->string('lr', 16)->nullable();
                $table->string('spec_signature', 64);
                $table->json('raw_spec_json')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'spec_signature'], 'oli_company_sig_unique');
                $table->index(['company_id', 'product_id'], 'oli_company_product_idx');
                $table->index(['company_id', 'lens_family', 'sph', 'cyl'], 'oli_company_power_idx');
            });
        }

        if (!Schema::hasTable('optical_rx_jobs')) {
            Schema::create('optical_rx_jobs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('financial_year_id');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('sales_order_id')->nullable();
                $table->unsignedBigInteger('sale_id')->nullable();
                $table->unsignedBigInteger('production_order_id')->nullable();
                $table->unsignedBigInteger('job_work_order_id')->nullable();
                $table->unsignedBigInteger('principal_job_order_id')->nullable();
                $table->string('job_no', 64);
                $table->string('customer_order_no', 191)->nullable();
                $table->unsignedBigInteger('source_warehouse_id')->nullable();
                $table->string('fulfillment_route', 40)->default('internal_lab');
                $table->string('status', 40)->default('collecting');
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('product_id')->nullable();
                $table->json('optical_spec_json');
                $table->decimal('qty_requested', 14, 4);
                $table->decimal('qty_accepted', 14, 4)->default(0);
                $table->decimal('qty_failed', 14, 4)->default(0);
                $table->decimal('qty_completed', 14, 4)->default(0);
                $table->decimal('qty_packed', 14, 4)->default(0);
                $table->decimal('qty_dispatched', 14, 4)->default(0);
                $table->decimal('qty_invoiced', 14, 4)->default(0);
                $table->date('promised_date')->nullable();
                $table->uuid('parent_job_id')->nullable();
                $table->string('remake_reason', 255)->nullable();
                $table->string('idempotency_key', 150)->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->unique(['company_id', 'job_no'], 'orj_company_job_no_unique');
                $table->unique(['company_id', 'idempotency_key'], 'orj_company_idem_unique');
                $table->index(['company_id', 'branch_id', 'financial_year_id', 'status'], 'orj_scope_status_idx');
            });
        }

        if (!Schema::hasTable('optical_rx_job_events')) {
            Schema::create('optical_rx_job_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('job_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('actor_id');
                $table->unsignedInteger('revision');
                $table->string('event_type', 64);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->string('evidence_type', 64)->nullable();
                $table->unsignedBigInteger('evidence_id')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['job_id', 'revision'], 'orje_job_rev_idx');
            });
        }

        if (Schema::hasTable('goods_received_note_items') && !Schema::hasColumn('goods_received_note_items', 'optical_spec_json')) {
            Schema::table('goods_received_note_items', function (Blueprint $table): void {
                $table->json('optical_spec_json')->nullable();
            });
        }

        if (Schema::hasTable('delivery_challan_items') && !Schema::hasColumn('delivery_challan_items', 'optical_spec_json')) {
            Schema::table('delivery_challan_items', function (Blueprint $table): void {
                $table->json('optical_spec_json')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Additive optical foundation tables are retained on rollback; disable AI_OPTICALS_ENABLED=false to halt exposure.
    }
};
