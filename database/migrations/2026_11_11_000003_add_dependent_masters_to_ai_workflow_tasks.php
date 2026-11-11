<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 2.2: Intelligent Dependent Master Data Creation & Workflow Resumption
     */
    public function up(): void
    {
        Schema::table('ai_workflow_tasks', function (Blueprint $table): void {
            if (!Schema::hasColumn('ai_workflow_tasks', 'dependent_masters_json')) {
                $table->json('dependent_masters_json')->nullable()->after('issues_json');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Additive migration: preserve data in production
    }
};
