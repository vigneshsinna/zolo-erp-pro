<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add optical prescription parameters to product_sales for lens manufacturers and optical retailers.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_sales')) {
            Schema::table('product_sales', function (Blueprint $table) {
                if (!Schema::hasColumn('product_sales', 'sph')) {
                    $table->string('sph', 20)->nullable()->after('is_packing');
                }
                if (!Schema::hasColumn('product_sales', 'cyl')) {
                    $table->string('cyl', 20)->nullable()->after('sph');
                }
                if (!Schema::hasColumn('product_sales', 'axis')) {
                    $table->string('axis', 20)->nullable()->after('cyl');
                }
                if (!Schema::hasColumn('product_sales', 'base')) {
                    $table->string('base', 20)->nullable()->after('axis');
                }
                if (!Schema::hasColumn('product_sales', 'addition')) {
                    $table->string('addition', 20)->nullable()->after('base');
                }
                if (!Schema::hasColumn('product_sales', 'lr')) {
                    $table->string('lr', 20)->nullable()->after('addition');
                }
                if (!Schema::hasColumn('product_sales', 'dia')) {
                    $table->string('dia', 20)->nullable()->after('lr');
                }
                if (!Schema::hasColumn('product_sales', 'prescription_json')) {
                    $table->json('prescription_json')->nullable()->after('dia');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('product_sales')) {
            Schema::table('product_sales', function (Blueprint $table) {
                $columns = [];
                foreach (['sph', 'cyl', 'axis', 'base', 'addition', 'lr', 'dia', 'prescription_json'] as $col) {
                    if (Schema::hasColumn('product_sales', $col)) {
                        $columns[] = $col;
                    }
                }
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};

