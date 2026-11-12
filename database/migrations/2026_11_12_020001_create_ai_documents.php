<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('classification', 30);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->string('storage_path');
            $table->string('status', 30)->default('quarantined');
            $table->unsignedInteger('source_version')->default(1);
            $table->unsignedInteger('acl_version')->default(1);
            $table->longText('extraction_encrypted')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['company_id', 'actor_id', 'created_at']);
        });
        Schema::create('ai_document_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->uuid('document_id');
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('acl_version');
            $table->unsignedInteger('page');
            $table->unsignedInteger('position');
            $table->char('checksum', 64);
            $table->longText('content_encrypted');
            $table->timestamps();
            $table->foreign('document_id')->references('id')->on('ai_documents');
            $table->index(['company_id', 'document_id', 'source_version']);
        });
        Schema::create('ai_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('financial_year_id');
            $table->unsignedBigInteger('actor_id');
            $table->uuid('document_id');
            $table->char('source_hash', 64);
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('status', 30)->default('preview');
            $table->longText('rows_encrypted');
            $table->longText('receipts_json')->nullable();
            $table->char('approval_hash', 64)->nullable();
            $table->string('execution_key', 190)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->foreign('document_id')->references('id')->on('ai_documents');
            $table->index(['company_id', 'actor_id', 'created_at']);
            $table->unique(['company_id', 'execution_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_import_batches');
        Schema::dropIfExists('ai_document_chunks');
        Schema::dropIfExists('ai_documents');
    }
};
