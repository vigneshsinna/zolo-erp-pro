<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_mcp_client_bindings')) {
            Schema::table('ai_mcp_client_bindings', function (Blueprint $table): void {
                if (!Schema::hasColumn('ai_mcp_client_bindings', 'client_identifier')) {
                    $table->string('client_identifier', 120)->nullable()->after('id');
                }
                if (!Schema::hasColumn('ai_mcp_client_bindings', 'allowed_operations')) {
                    $table->json('allowed_operations')->nullable()->after('allowed_tools');
                }
                if (!Schema::hasColumn('ai_mcp_client_bindings', 'allowed_actions')) {
                    $table->json('allowed_actions')->nullable()->after('allowed_operations');
                }
                if (!Schema::hasColumn('ai_mcp_client_bindings', 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable()->after('expires_at');
                }
                if (!Schema::hasColumn('ai_mcp_client_bindings', 'revoked_reason')) {
                    $table->string('revoked_reason', 255)->nullable()->after('revoked_at');
                }
            });
        }

        if (Schema::hasTable('ai_feature_settings') && !Schema::hasColumn('ai_feature_settings', 'mcp_proposals_enabled')) {
            Schema::table('ai_feature_settings', function (Blueprint $table): void {
                $table->boolean('mcp_proposals_enabled')->default(false)->after('multimodal_enabled');
            });
        }

        if (Schema::hasTable('ai_action_proposals')) {
            Schema::table('ai_action_proposals', function (Blueprint $table): void {
                if (!Schema::hasColumn('ai_action_proposals', 'source_channel')) {
                    $table->string('source_channel', 32)->default('internal')->after('status');
                }
                if (!Schema::hasColumn('ai_action_proposals', 'source_client_binding_id')) {
                    $table->uuid('source_client_binding_id')->nullable()->after('source_channel');
                }
            });
        }
    }

    public function down(): void
    {
        // Additive governance columns are retained on rollback; disable flags to halt MCP proposal creation.
    }
};
