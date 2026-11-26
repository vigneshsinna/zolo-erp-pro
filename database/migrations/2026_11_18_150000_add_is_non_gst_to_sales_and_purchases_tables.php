<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'is_non_gst')) {
            Schema::table('sales', function (Blueprint $table) {
                if (Schema::hasColumn('sales', 'sale_status')) {
                    $table->boolean('is_non_gst')->default(false)->index()->after('sale_status');
                } else {
                    $table->boolean('is_non_gst')->default(false)->index();
                }
            });
        }

        if (Schema::hasTable('purchases') && !Schema::hasColumn('purchases', 'is_non_gst')) {
            Schema::table('purchases', function (Blueprint $table) {
                if (Schema::hasColumn('purchases', 'status')) {
                    $table->boolean('is_non_gst')->default(false)->index()->after('status');
                } else {
                    $table->boolean('is_non_gst')->default(false)->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'is_non_gst')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['is_non_gst']);
                $table->dropColumn('is_non_gst');
            });
        }

        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'is_non_gst')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropIndex(['is_non_gst']);
                $table->dropColumn('is_non_gst');
            });
        }
    }
};
