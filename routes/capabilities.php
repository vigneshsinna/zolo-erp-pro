<?php

use App\Http\Controllers\Api\V1\CapabilityApiController;
use App\Http\Controllers\Api\V1\DocumentSeriesApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/company-context')->middleware(['api', 'auth:sanctum', 'company.context'])->group(function () {
    Route::get('license', [CapabilityApiController::class, 'license'])->name('api.v1.license.show');
    Route::get('capabilities', [CapabilityApiController::class, 'index'])->name('api.v1.capabilities.index');
    Route::put('capabilities/{key}', [CapabilityApiController::class, 'update'])->name('api.v1.capabilities.update');
    Route::post('profiles/{key}', [CapabilityApiController::class, 'profile'])->name('api.v1.capabilities.profile');
    Route::post('capabilities/import-legacy', [CapabilityApiController::class, 'import'])->name('api.v1.capabilities.import');
    Route::get('document-series', [DocumentSeriesApiController::class, 'index'])->name('api.v1.document-series.index');
    Route::post('document-series', [DocumentSeriesApiController::class, 'store'])->name('api.v1.document-series.store');
});
