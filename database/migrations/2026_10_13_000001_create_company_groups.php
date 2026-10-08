<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group (for example "MJ Group") only organises legal companies. Books, stock, tax and financial years stay
 * per company; access is still granted through company_user, so group admins are synchronized into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('company_group_admins', function (Blueprint $table) {
            $table->foreignId('company_group_id')->constrained('company_groups')->restrictOnDelete();
            $table->unsignedInteger('user_id');
            $table->timestamps();
            $table->primary(['company_group_id', 'user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('company_group_id')->nullable()->after('id')->constrained('company_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_group_id');
        });
        Schema::dropIfExists('company_group_admins');
        Schema::dropIfExists('company_groups');
    }
};
