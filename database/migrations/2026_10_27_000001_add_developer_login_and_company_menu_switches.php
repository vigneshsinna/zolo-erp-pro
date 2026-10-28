<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Developer login (an Admin account flagged as developer: manages every admin login) and the sections the developer
 * switched off per company. A row means "off"; no row means the section shows as usual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'is_developer')) {
            Schema::table('users', fn (Blueprint $table) => $table->boolean('is_developer')->default(false)->after('role_id'));
        }
        if (!Schema::hasTable('company_menu_switches')) {
            Schema::create('company_menu_switches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('menu_key', 120);
                $table->string('label', 150);
                // The screens this switch blocks: [{"path": "/employees", "query": {}}], read on every request.
                $table->json('paths');
                $table->unsignedBigInteger('switched_off_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'menu_key']);
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_menu_switches');
        if (Schema::hasColumn('users', 'is_developer')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_developer'));
        }
    }
};
