<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_order_items')) {
            return;
        }

        Schema::table('commercial_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('commercial_order_items', 'optical_spec_json')) {
                $table->json('optical_spec_json')->nullable()->after('batch_no');
            }
            if (! Schema::hasColumn('commercial_order_items', 'optical_signature')) {
                $table->string('optical_signature', 64)->nullable()->after('optical_spec_json');
                $table->index(['company_id', 'optical_signature'], 'coi_company_optical_sig_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('commercial_order_items')) {
            return;
        }

        Schema::table('commercial_order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('commercial_order_items', 'optical_signature')) {
                $table->dropIndex('coi_company_optical_sig_idx');
                $table->dropColumn('optical_signature');
            }
            if (Schema::hasColumn('commercial_order_items', 'optical_spec_json')) {
                $table->dropColumn('optical_spec_json');
            }
        });
    }
};
