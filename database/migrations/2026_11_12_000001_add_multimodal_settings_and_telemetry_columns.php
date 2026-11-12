<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_feature_settings')) {
            Schema::table('ai_feature_settings', function (Blueprint $table): void {
                if (!Schema::hasColumn('ai_feature_settings', 'multimodal_enabled')) {
                    $table->boolean('multimodal_enabled')->default(false)->after('read_only');
                }
                if (!Schema::hasColumn('ai_feature_settings', 'daily_media_upload_limit')) {
                    $table->unsignedInteger('daily_media_upload_limit')->default(100)->after('daily_token_limit');
                }
            });
        }

        if (Schema::hasTable('ai_model_invocations')) {
            Schema::table('ai_model_invocations', function (Blueprint $table): void {
                if (!Schema::hasColumn('ai_model_invocations', 'model_purpose')) {
                    $table->string('model_purpose', 40)->nullable()->after('model');
                }
                if (!Schema::hasColumn('ai_model_invocations', 'media_id')) {
                    $table->uuid('media_id')->nullable()->after('model_purpose');
                    $table->index(['media_id', 'created_at'], 'idx_ai_model_inv_media_created');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_model_invocations')) {
            Schema::table('ai_model_invocations', function (Blueprint $table): void {
                if (Schema::hasColumn('ai_model_invocations', 'media_id')) {
                    $table->dropIndex('idx_ai_model_inv_media_created');
                    $table->dropColumn('media_id');
                }
                if (Schema::hasColumn('ai_model_invocations', 'model_purpose')) {
                    $table->dropColumn('model_purpose');
                }
            });
        }

        if (Schema::hasTable('ai_feature_settings')) {
            Schema::table('ai_feature_settings', function (Blueprint $table): void {
                $drops = array_values(array_filter([
                    Schema::hasColumn('ai_feature_settings', 'multimodal_enabled') ? 'multimodal_enabled' : null,
                    Schema::hasColumn('ai_feature_settings', 'daily_media_upload_limit') ? 'daily_media_upload_limit' : null,
                ]));
                if ($drops !== []) {
                    $table->dropColumn($drops);
                }
            });
        }
    }
};

