<?php

use App\Http\Controllers\Api\V1\AiDocumentController;
use Illuminate\Support\Facades\Route;

// Include inside an authenticated, company-context AI route group.
Route::controller(AiDocumentController::class)->middleware('throttle:30,1')->group(function (): void {
    Route::get('documents-workspace', 'workspace');
    Route::post('documents', 'upload');
    Route::get('documents/{id}', 'show')->whereUuid('id');
    Route::post('documents/{id}/process', 'process')->whereUuid('id');
    Route::delete('documents/{id}', 'revoke')->whereUuid('id');
    Route::post('documents/{id}/approve-knowledge', 'approveKnowledge')->whereUuid('id');
    Route::post('knowledge/search', 'search');
    Route::post('product-imports', 'previewImport');
    Route::get('product-imports/{id}', 'showImport')->whereUuid('id');
    Route::post('product-imports/{id}/commit', 'commitImport')->whereUuid('id');
    Route::get('product-imports/{id}/errors', 'importErrors')->whereUuid('id');
});
