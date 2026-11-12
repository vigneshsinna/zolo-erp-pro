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
        if (Schema::hasTable('purchases') && !Schema::hasColumn('purchases', 'lot_number')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->string('lot_number', 191)->nullable()->index()->after('supplier_invoice_no');
            });
        }

        if (Schema::hasTable('sales') && !Schema::hasColumn('sales', 'lot_number')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('lot_number', 191)->nullable()->index()->after('reference_no');
            });
        }

        if (Schema::hasTable('goods_received_notes') && !Schema::hasColumn('goods_received_notes', 'lot_number')) {
            Schema::table('goods_received_notes', function (Blueprint $table) {
                $table->string('lot_number', 191)->nullable()->after('grn_no');
            });
        }

        if (Schema::hasTable('delivery_challans') && !Schema::hasColumn('delivery_challans', 'lot_number')) {
            Schema::table('delivery_challans', function (Blueprint $table) {
                $table->string('lot_number', 191)->nullable()->after('challan_no');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'lot_number')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropIndex(['lot_number']);
                $table->dropColumn('lot_number');
            });
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'lot_number')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['lot_number']);
                $table->dropColumn('lot_number');
            });
        }

        if (Schema::hasTable('goods_received_notes') && Schema::hasColumn('goods_received_notes', 'lot_number')) {
            Schema::table('goods_received_notes', function (Blueprint $table) {
                $table->dropColumn('lot_number');
            });
        }

        if (Schema::hasTable('delivery_challans') && Schema::hasColumn('delivery_challans', 'lot_number')) {
            Schema::table('delivery_challans', function (Blueprint $table) {
                $table->dropColumn('lot_number');
            });
        }
    }
};
