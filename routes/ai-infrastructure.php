<?php

use App\Ai\Infrastructure\AsyncActionController;
use App\Ai\Infrastructure\McpController;
use Illuminate\Support\Facades\Route;

// Require this file from RouteServiceProvider::map(), outside any existing route group.
Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->match(['GET', 'POST'], 'mcp/erp', McpController::class)->name('ai.mcp');
Route::middleware(['api', 'auth:sanctum', 'company.context', 'throttle:60,1'])->prefix('api/v1/ai/proposals')->group(function (): void {
    Route::post('{id}/execute-async', [AsyncActionController::class, 'execute'])->whereUuid('id')->name('api.v1.ai.actions.execute-async');
    Route::get('{id}/execution', [AsyncActionController::class, 'status'])->whereUuid('id')->name('api.v1.ai.actions.execution');
});
Route::middleware(['web', 'auth', 'company.context', 'throttle:60,1'])->prefix('ai/proposals')->group(function (): void {
    Route::post('{id}/execute-async', [AsyncActionController::class, 'execute'])->whereUuid('id')->name('ai.actions.execute-async');
    Route::get('{id}/execution', [AsyncActionController::class, 'status'])->whereUuid('id')->name('ai.actions.execution');
});
