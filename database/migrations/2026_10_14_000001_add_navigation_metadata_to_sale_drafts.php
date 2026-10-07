<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sale_drafts is a historical table name: it stores both Sales and Purchase workspace drafts (kind = sale|purchase).
 * The metadata below is navigation only (tab title and party label) so the tab strip never decodes the payload;
 * it is never business authority and never drives party selection.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('sale_drafts')) {
            return;
        }
        Schema::table('sale_drafts', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_drafts', 'title')) {
                $table->string('title', 60)->nullable();
            }
            if (!Schema::hasColumn('sale_drafts', 'party_id')) {
                $table->unsignedBigInteger('party_id')->nullable();
            }
            if (!Schema::hasColumn('sale_drafts', 'party_name_snapshot')) {
                $table->string('party_name_snapshot', 150)->nullable();
            }
        });
        // Drafts saved before tab titles existed get a stable title so the tab strip never shows a blank label.
        foreach (DB::table('sale_drafts')->whereNull('title')->pluck('id') as $id) {
            DB::table('sale_drafts')->where('id', $id)->update(['title' => 'Draft '.$id]);
        }
        // Quota and prune both look drafts up by owner and recency, across branches and financial years.
        try {
            Schema::table('sale_drafts', fn (Blueprint $table) => $table->index(['company_id', 'user_id', 'kind', 'updated_at'], 'sale_drafts_quota_index'));
        } catch (\Throwable $error) {
            // already present on a partially migrated database
        }
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE sale_drafts COMMENT = 'Historical name: Sales and Purchase workspace drafts (kind = sale|purchase)'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('sale_drafts')) {
            return;
        }
        Schema::table('sale_drafts', function (Blueprint $table) {
            foreach (['title', 'party_id', 'party_name_snapshot'] as $column) {
                if (Schema::hasColumn('sale_drafts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
