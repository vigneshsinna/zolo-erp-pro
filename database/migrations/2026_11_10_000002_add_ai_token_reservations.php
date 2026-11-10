<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_counters', function (Blueprint $table) {
            $table->unsignedInteger('reserved_tokens')->default(0);
        });

        DB::table('permissions')->updateOrInsert(['name' => 'ai.use', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        // Preserve the permission and any role assignments across application rollbacks.
        Schema::table('ai_usage_counters', function (Blueprint $table) {
            $table->dropColumn('reserved_tokens');
        });
    }
};
