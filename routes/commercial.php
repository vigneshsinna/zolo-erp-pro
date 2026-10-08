<?php

use App\Http\Controllers\CommercialController;
use App\Http\Middleware\RequireSharedCommercial;
use Illuminate\Support\Facades\Route;

Route::prefix('commercial')->middleware(['auth', 'company.context', RequireSharedCommercial::class])
    ->where(['kind' => 'sale|purchase', 'resource' => 'parties|products|agents|areas|bill-sundries|sale-types|purchase-types|remarks|series', 'id' => '[0-9]+'])->group(function () {
        Route::post('{kind}', [CommercialController::class, 'store']);
        Route::post('{kind}/preview', [CommercialController::class, 'preview']);
        Route::get('{kind}/search/{resource}', [CommercialController::class, 'search']);
        Route::get('{kind}/party/{id}', [CommercialController::class, 'party']);
        Route::get('{kind}/previous-rates', [CommercialController::class, 'previousRates']);
        Route::get('{kind}/clone/{id}', [CommercialController::class, 'cloneDocument']);
        Route::get('{kind}/drafts', [CommercialController::class, 'drafts']);
        Route::post('{kind}/drafts', [CommercialController::class, 'draft']);
        Route::get('{kind}/drafts/{id}', [CommercialController::class, 'showDraft']);
        Route::delete('{kind}/drafts/{id}', [CommercialController::class, 'destroyDraft']);
        Route::post('{kind}/masters/{resource}', [CommercialController::class, 'inlineMaster']);
        Route::post('{kind}/{id}/reverse', [CommercialController::class, 'reverse']);
        Route::get('{kind}/{id}/reverse', [CommercialController::class, 'reversalForm']);
        Route::put('{kind}/{id}', [CommercialController::class, 'replace']);
        Route::post('{kind}/{id}/payments', [CommercialController::class, 'payment']);
        Route::post('purchase/{id}/receipts', [CommercialController::class, 'receive']);
    });
