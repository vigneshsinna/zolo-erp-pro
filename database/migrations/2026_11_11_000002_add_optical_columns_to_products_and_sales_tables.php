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
        // 1. Add optical attributes to products table
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'base')) {
                $table->string('base', 50)->nullable()->after('barcode_symbology');
            }
            if (!Schema::hasColumn('products', 'addition')) {
                $table->string('addition', 50)->nullable()->after('base');
            }
            if (!Schema::hasColumn('products', 'lr')) {
                $table->string('lr', 10)->nullable()->after('addition'); // 'L', 'R', 'Pair'
            }
            if (!Schema::hasColumn('products', 'dia')) {
                $table->string('dia', 20)->nullable()->after('lr'); // e.g. '65', '70', '75'
            }
            if (!Schema::hasColumn('products', 'product_type_id')) {
                $table->unsignedBigInteger('product_type_id')->nullable()->after('type');
            }
        });

        // 2. Add warranty end-user and optical lab fields to sales table
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'end_user_name')) {
                $table->string('end_user_name', 191)->nullable()->after('sale_note');
            }
            if (!Schema::hasColumn('sales', 'end_user_phone')) {
                $table->string('end_user_phone', 50)->nullable()->after('end_user_name');
            }
            if (!Schema::hasColumn('sales', 'end_user_email')) {
                $table->string('end_user_email', 191)->nullable()->after('end_user_phone');
            }
            if (!Schema::hasColumn('sales', 'end_user_address')) {
                $table->text('end_user_address')->nullable()->after('end_user_email');
            }
            if (!Schema::hasColumn('sales', 'fitting_charge')) {
                $table->decimal('fitting_charge', 15, 4)->default(0)->after('end_user_address');
            }
            if (!Schema::hasColumn('sales', 'tinting_cost')) {
                $table->decimal('tinting_cost', 15, 4)->default(0)->after('fitting_charge');
            }
            if (!Schema::hasColumn('sales', 'customer_order_no')) {
                $table->string('customer_order_no', 191)->nullable()->after('tinting_cost');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $cols = array_filter(['base', 'addition', 'lr', 'dia', 'product_type_id'], fn($c) => Schema::hasColumn('products', $c));
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            $cols = array_filter(['end_user_name', 'end_user_phone', 'end_user_email', 'end_user_address', 'fitting_charge', 'tinting_cost', 'customer_order_no'], fn($c) => Schema::hasColumn('sales', $c));
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

