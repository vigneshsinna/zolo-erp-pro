<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_opening_import_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('batch_id');
            $table->uuid('document_id');
            $table->char('checksum', 64);
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('acl_version');
            $table->timestamps();
            $table->unique(['company_id', 'batch_id']);
            $table->foreign('document_id')->references('id')->on('ai_documents');
            $table->index(['company_id', 'actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_opening_import_sources');
    }
};
